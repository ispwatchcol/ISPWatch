<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;

/**
 * Congela nombre y documento del titular en la fila que lo lleva.
 *
 * POR QUÉ EXISTE
 *
 * Desde P-43 las claves foráneas de dinero hacia `users` están en
 * `ON DELETE SET NULL`: dar de baja a un cliente ya no destruye sus facturas ni
 * sus pagos, pero sí les quita el vínculo con el titular. Sin un nombre
 * congelado, el histórico conservado sería una lista de cifras que no se pueden
 * atribuir a nadie — exactamente igual de inservible para un cierre contable
 * que haberlas borrado.
 *
 * CUÁNDO SE CONGELA
 *
 * Al CREAR la fila, no al borrar al cliente. Es lo correcto para un documento
 * contable: la factura debe decir a quién se le facturó **entonces**, no cómo se
 * llama hoy quien la recibió. Si alguien corrige un apellido mal escrito, las
 * facturas ya emitidas siguen diciendo lo que se imprimió y se envió.
 *
 * El servicio de borrado vuelve a pasar por aquí como red de seguridad, para las
 * filas anteriores a esta función que todavía no tuvieran el snapshot.
 *
 * DE DÓNDE SALEN LOS DATOS
 *
 * Nombre de `users` y documento de `customer_profile`, que es de donde los toma
 * `PlaceholderResolver::forInvoice()` para imprimirlos. El snapshot tiene que
 * decir lo mismo que dice el papel.
 *
 * POR QUÉ `DB::table()` Y NO LOS MODELOS
 *
 * Por dos razones. Una consulta con `join` en vez de dos `find`, que importa
 * porque esto corre una vez por factura y la facturación mensual crea cientos
 * de golpe. Y sobre todo porque `User` y `CustomerProfile` llevan el global
 * scope de tenant: resolver el titular desde un contexto de otro tenant —el
 * scheduler, un comando de consola— devolvería `null` y el snapshot quedaría
 * vacío **en silencio**, que es el peor de los fallos posibles para un dato que
 * sólo se echa de menos años después.
 *
 * POR QUÉ NO HAY CACHÉ, HABIÉNDOLO INTENTADO
 *
 * La primera versión guardaba los titulares ya resueltos en un array estático,
 * para ahorrarse la consulta en la facturación mensual. Duró lo que tardó la
 * suite completa en delatarlo: un titular resuelto en una prueba anterior se
 * colaba en la siguiente, porque cada una reconstruye la base con los mismos
 * identificadores.
 *
 * Eso era el síntoma, no el problema. El problema es que un caché estático en
 * un worker de cola de vida larga sirve el nombre que leyó la primera vez: si
 * alguien corrige un apellido, las facturas siguientes de ese proceso lo
 * congelarían mal. Cambiar un dato contable por ahorrarse una búsqueda por
 * clave primaria —dentro de un job que ya hace bastantes más por factura— es un
 * mal negocio.
 */
trait FreezesCustomerSnapshot
{
    protected static function bootFreezesCustomerSnapshot(): void
    {
        static::creating(function ($model) {
            $model->freezeCustomerSnapshot();
        });
    }

    /**
     * Rellena `customer_name` y `customer_document` si están vacíos.
     *
     * No pisa un snapshot existente salvo que se pida: una factura emitida ya
     * dijo a quién se le facturó y eso no se reescribe.
     */
    public function freezeCustomerSnapshot(bool $sobrescribir = false): void
    {
        $id = (int) ($this->customer_id ?? 0);

        if ($id <= 0) {
            return;
        }

        if (!$sobrescribir && ($this->customer_name !== null || $this->customer_document !== null)) {
            return;
        }

        $titular = $this->resolverTitular($id);

        if ($titular === null) {
            return;
        }

        $this->customer_name     = $titular['name'];
        $this->customer_document = $titular['document'];
    }

    /**
     * Nombre del titular para mostrar: el de hoy si sigue existiendo, el
     * congelado si no.
     *
     * ESE ORDEN Y NO EL CONTRARIO. En un listado en pantalla lo útil es el
     * nombre actual del cliente, y el snapshot puede ser de hace años. El
     * congelado entra sólo cuando ya no hay a quién preguntar — que es
     * exactamente para lo que se guardó.
     *
     * (Lo impreso en un PDF sí usa el snapshot con preferencia: el documento
     * debe seguir diciendo lo que decía. Eso lo resuelve `PlaceholderResolver`.)
     */
    public function customerDisplayName(): string
    {
        $perfil = $this->customer?->customerProfile;
        $nombre = trim(($perfil->name ?? '') . ' ' . ($perfil->last_name ?? ''));

        if ($nombre === '') {
            $nombre = trim((string) ($this->customer?->user_name ?? ''));
        }

        return $nombre !== '' ? $nombre : (string) ($this->customer_name ?? '');
    }

    /**
     * @return array{name: ?string, document: ?string}|null
     */
    private function resolverTitular(int $id): ?array
    {
        $fila = DB::table('users')
            ->leftJoin('customer_profile', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.id', $id)
            ->first([
                'users.user_name',
                'users.user_lastname',
                'users.email',
                'customer_profile.name as perfil_nombre',
                'customer_profile.last_name as perfil_apellido',
                'customer_profile.cedula',
            ]);

        if (!$fila) {
            // El titular no existe: no hay nada que congelar y tampoco es un
            // error. Ocurre al crear una fila con un `customer_id` que se está
            // borrando en la misma transacción.
            return null;
        }

        $nombre = trim(($fila->user_name ?? '') . ' ' . ($fila->user_lastname ?? ''));

        if ($nombre === '') {
            $nombre = trim(($fila->perfil_nombre ?? '') . ' ' . ($fila->perfil_apellido ?? ''));
        }

        if ($nombre === '') {
            $nombre = (string) ($fila->email ?? '');
        }

        $documento = trim((string) ($fila->cedula ?? ''));

        return [
            'name'     => $nombre !== '' ? mb_substr($nombre, 0, 160) : null,
            'document' => $documento !== '' ? mb_substr($documento, 0, 40) : null,
        ];
    }
}
