<?php

namespace App\Services;

use App\Models\CustomerDocument;
use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\MikroTik\CustomerDeprovisionManager;
use App\Services\MikroTik\RouterEndpointResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Borra un cliente SIN dejar residuos.
 *
 * Antes del 2026-08-06 `CustomerProfileController::destroy()` hacía sólo
 * `$profile->delete()` + `$user->delete()` y confiaba en las claves foráneas
 * en cascada. Eso limpia bien las tablas que SÍ tienen la clave (facturas,
 * pagos, documentos, servicios, bitácoras), pero dejaba tres clases de basura
 * que nunca se iban a borrar:
 *
 *  1. **Los archivos en S3.** La cascada ocurre dentro de PostgreSQL, que
 *     jamás pasa por PHP: desaparecían las filas de `customer_documents` y los
 *     objetos —contratos firmados, fotos de instalación— se quedaban en el
 *     bucket para siempre, ya sin nada que apuntara a ellos.
 *  2. **La configuración en el router.** No se llamaba a ningún manager de
 *     MikroTik, así que el cliente borrado SEGUÍA NAVEGANDO, y sin ficha en
 *     ISPWatch ya no quedaba de dónde sacar la IP para limpiarlo a mano.
 *  3. **Filas huérfanas** en las tres tablas que tienen columna de cliente
 *     pero no clave foránea: `customer_installations`, `bulk_provision_runs` y
 *     `prospects.converted_user_id`.
 *
 * ORDEN DE LAS OPERACIONES, que no es arbitrario:
 *
 *   1. Tomar la "identidad de red" del cliente ANTES de tocar nada — una vez
 *      borrado ya no hay de dónde sacar IP, usuario PPPoE ni MAC.
 *   2. Limpiar el router PRIMERO. Si fallara después del borrado, quedaría un
 *      cliente navegando sin ningún registro de quién era: irrecuperable. Al
 *      revés, si el router se limpia y luego falla el borrado, el cliente
 *      sigue en ISPWatch y se re-aprovisiona con un clic.
 *   3. Borrar en base de datos dentro de una transacción.
 *   4. Borrar los archivos de S3 DESPUÉS del commit. S3 no es transaccional:
 *      hacerlo antes significaría borrar los archivos de un cliente que sigue
 *      existiendo si la transacción se revierte.
 *
 * Un fallo al limpiar el router NO aborta el borrado (un router caído dejaría
 * clientes imposibles de eliminar), pero se reporta explícitamente en la
 * respuesta y en el log para que el operador sepa que hay que ir a mano.
 */
class CustomerDeletionService
{
    public function __construct(
        private readonly CustomerDeprovisionManager $deprovision,
        private readonly RouterEndpointResolver $endpoints,
    ) {
    }

    /**
     * @return array{router:array,files:array{deleted:int,failed:int},records:array<string,int>}
     */
    public function delete(User $user, CustomerProfile $profile): array
    {
        $customerId = (int) $user->id;

        $identity  = $this->networkIdentity($profile);
        $filePaths = $this->collectFilePaths($customerId);

        $routerResult = $this->purgeRouter($profile, $identity);

        $records = DB::transaction(function () use ($customerId, $user, $profile) {
            return $this->deleteRecords($customerId, $user, $profile);
        });

        $files = $this->deleteFiles($filePaths, $customerId);

        Log::info('[CustomerDeletion] Cliente eliminado', [
            'customer_id'   => $customerId,
            'identity'      => $identity,
            'router_ok'     => $routerResult['success'] ?? false,
            'files_deleted' => $files['deleted'],
            'records'       => $records,
        ]);

        return ['router' => $routerResult, 'files' => $files, 'records' => $records];
    }

    /**
     * Todo lo que hace falta para encontrar al cliente dentro del router. Se
     * lee ANTES de borrar nada: después ya no existe.
     *
     * @return array{ip:?string,pppoe_username:?string,hotspot_username:?string,mac_address:?string}
     */
    private function networkIdentity(CustomerProfile $profile): array
    {
        return [
            'ip'               => $profile->ip_user,
            'pppoe_username'   => $profile->pppoe_username,
            'hotspot_username' => $profile->hotspot_username,
            'mac_address'      => $profile->mac_address,
        ];
    }

    /**
     * Rutas de S3 de TODO lo que cuelga del cliente. Las fotos y actas de una
     * instalación se recogen por `installation_id` y no sólo por
     * `customer_id`: esa columna es nullable desde que las instalaciones
     * pueden colgar de un prospecto, así que filtrar sólo por cliente dejaría
     * fuera justamente las fotos.
     *
     * @return string[]
     */
    private function collectFilePaths(int $customerId): array
    {
        $installationIds = CustomerInstallation::where('customer_id', $customerId)->pluck('id');

        $paths = CustomerDocument::where('customer_id', $customerId)
            ->orWhereIn('installation_id', $installationIds)
            ->pluck('file_path')
            ->all();

        $signatures = CustomerInstallation::whereIn('id', $installationIds)
            ->get(['customer_signature_path', 'technician_signature_path'])
            ->flatMap(fn ($i) => [$i->customer_signature_path, $i->technician_signature_path])
            ->all();

        return array_values(array_unique(array_filter(
            array_merge($paths, $signatures),
            fn ($p) => is_string($p) && trim($p) !== ''
        )));
    }

    /**
     * @param array{ip:?string,pppoe_username:?string,hotspot_username:?string,mac_address:?string} $identity
     */
    private function purgeRouter(CustomerProfile $profile, array $identity): array
    {
        $router = $profile->router;

        if (!$router) {
            return ['success' => true, 'skipped' => true, 'message' => 'El cliente no tenía router asignado.'];
        }

        if (!$router->user_rb || !$router->password_rb) {
            return [
                'success' => false,
                'message' => "El router {$router->name} no tiene credenciales de gestión: hay que borrar la configuración del cliente a mano.",
            ];
        }

        try {
            // Nunca se marca `router->ip` a ciegas: el CORE reasigna una IP del
            // pool en cada reconexión L2TP y el valor guardado se queda viejo.
            $endpoint = $this->endpoints->resolve($router);

            return $this->deprovision->purge(
                $endpoint['ip'],
                $router->user_rb,
                $router->password_rb,
                $identity,
                $endpoint['ssh_port']
            );
        } catch (\Throwable $e) {
            Log::error('[CustomerDeletion] No se pudo limpiar el router', [
                'router_id' => $router->id,
                'identity'  => $identity,
                'error'     => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'No se pudo contactar al router: ' . $e->getMessage()];
        }
    }

    /**
     * Las tres tablas huérfanas se borran a mano ANTES del cliente: no tienen
     * clave foránea a `users` (sólo un índice), así que la cascada de
     * PostgreSQL no las toca y quedarían apuntando a un id inexistente.
     *
     * @return array<string,int>
     */
    private function deleteRecords(int $customerId, User $user, CustomerProfile $profile): array
    {
        $installationIds = CustomerInstallation::where('customer_id', $customerId)->pluck('id');

        // Antes que las instalaciones: sus fotos/actas pueden tener
        // customer_id NULL (instalaciones que vienen de un prospecto) y no las
        // arrastraría ni la cascada ni el borrado por cliente.
        $documents = CustomerDocument::whereIn('installation_id', $installationIds)->delete();
        $installations = CustomerInstallation::where('customer_id', $customerId)->delete();

        $runs = DB::table('bulk_provision_runs')->where('customer_id', $customerId)->delete();

        // El prospecto es un registro comercial propio y sobrevive al cliente:
        // sólo se corta el vínculo, que es lo que quedaría colgando.
        $prospects = DB::table('prospects')
            ->where('converted_user_id', $customerId)
            ->update(['converted_user_id' => null]);

        $profile->delete();
        $user->delete();

        return [
            'instalaciones'          => (int) $installations,
            'documentos_instalacion' => (int) $documents,
            'ejecuciones_alta'       => (int) $runs,
            'prospectos_desligados'  => (int) $prospects,
        ];
    }

    /**
     * @param  string[] $paths
     * @return array{deleted:int,failed:int}
     */
    private function deleteFiles(array $paths, int $customerId): array
    {
        $deleted = 0;
        $failed  = 0;

        foreach ($paths as $path) {
            try {
                // delete() devuelve true también cuando el objeto ya no estaba,
                // que para el propósito de "no dejar residuos" es el mismo
                // resultado.
                Storage::disk('s3')->delete($path);
                $deleted++;
            } catch (\Throwable $e) {
                $failed++;
                // El registro ya se borró y no hay a qué volver: el log es lo
                // único que permite localizar el objeto huérfano después.
                Log::error('[CustomerDeletion] No se pudo borrar un archivo de S3', [
                    'customer_id' => $customerId,
                    'path'        => $path,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
