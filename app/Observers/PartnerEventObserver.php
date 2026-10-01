<?php

namespace App\Observers;

use App\Models\CustomerProfile;
use App\Models\PartnerEvent;
use App\Models\Router;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Traduce cambios del modelo comercial a eventos para integradores externos.
 *
 * POR QUÉ OBSERVER Y NO INSTRUMENTAR LOS CONTROLADORES
 * -----------------------------------------------------
 * Por la misma razón documentada en MoneyAuditObserver: el estado comercial se
 * mueve por cuatro puertas —panel, API, carga masiva y consola— y solo un
 * observer las cubre todas. Un cliente suspendido por OverdueSuspensionService
 * (consola) y uno suspendido a mano desde el panel tienen que producir el mismo
 * evento, o el integrador ve una realidad a medias.
 *
 * LÍMITE CONOCIDO: LAS ESCRITURAS MASIVAS POR QUERY BUILDER NO DISPARAN
 * ----------------------------------------------------------------------
 * `Modelo::insert([...])` y `Modelo::where(...)->update([...])` no pasan por
 * Eloquent y por lo tanto no emiten evento. Los escritores que hoy lo hacen
 * emiten a mano con PartnerEvent::recordMany():
 *
 *   - la carga masiva de clientes (CustomersSheetImport::flush), que inserta en
 *     bloque para no dar 504 — durante un tiempo sus altas no aparecían en el
 *     feed y este comentario decía lo contrario (KAN-111);
 *   - el cambio de RADIUS de un router, que afecta a todos sus clientes sin
 *     tocar sus filas (fromRouter, aquí abajo).
 *
 * La carga masiva de ACTUALIZACIÓN (CustomersUpdateImport) sí usa `save()` y
 * está cubierta. Si se agrega otro camino masivo, el evento se emite a mano —
 * no es opcional: el integrador quedaría desincronizado sin ninguna señal.
 *
 * SOBRE LOS DUPLICADOS
 * --------------------
 * Un cambio de plan toca `customer_profile.service_id` y
 * `user_services.service_plan_id` en la misma operación, así que puede emitir
 * dos PLAN_CHANGED. Es deliberado. El evento es delgado —dice qué cambió, no
 * transporta el estado— y el consumidor re-consulta el recurso, así que un
 * duplicado le cuesta una petición. Suprimirlos exigiría recordar estado entre
 * llamadas, lo que en un worker de cola persiste entre trabajos y terminaría
 * ocultando eventos legítimos. Perder uno es mucho peor que mandar dos.
 */
class PartnerEventObserver
{
    /**
     * Campos de identidad que ameritan avisar. La lista es corta a propósito:
     * si cualquier edición emitiera evento, el feed se volvería ruido y el
     * integrador terminaría ignorándolo.
     */
    private const IDENTITY_FIELDS = [
        'name', 'last_name', 'cedula', 'address', 'city', 'state', 'is_company',
    ];

    /**
     * Atributos de red que un AAA externo usa para autenticar. Columna => nombre
     * con que la API los expone. Van en su propio evento (NETWORK_CHANGED) y
     * SIN valores: la IP no tiene por qué quedar en un log que no se poda.
     */
    private const NETWORK_FIELDS = [
        'ip_user'        => 'ip',
        'pppoe_username' => 'pppoe_username',
    ];

    /** Estados que significan «el servicio está prestándose». */
    private const LIVE_STATUSES = ['activo', 'gratis'];

    /** Estados que significan «baja definitiva». */
    private const ENDED_STATUSES = ['cancelado', 'retirado'];

    public function created(Model $model): void
    {
        if ($model instanceof UserService) {
            $this->emit($model, PartnerEvent::SERVICE_CREATED, [
                'plan_id' => $model->service_plan_id,
                'status'  => $model->status,
            ]);
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof CustomerProfile) {
            $this->fromCustomerProfile($model);
            return;
        }

        if ($model instanceof UserService) {
            $this->fromUserService($model);
            return;
        }

        if ($model instanceof Router) {
            $this->fromRouter($model);
        }
    }

    /**
     * Baja física del cliente (KAN-113).
     *
     * Se escucha en `deleted` del PERFIL y no del usuario porque
     * CustomerDeletionService borra el perfil primero: en ese instante el
     * usuario (de donde sale el tenant) y sus servicios todavía existen. Los
     * servicios caen después por cascada, sin pasar por Eloquent; por eso el
     * evento es uno solo, a nivel cliente, con la lista de `service_ids`.
     *
     * Es la lápida: el recurso ya no existe, así que el evento trae lo que el
     * integrador necesita para revocar sin consultar nada — qué servicios y en
     * qué router. `partner_events` no tiene FK a propósito y sobrevive al
     * borrado.
     */
    public function deleted(Model $model): void
    {
        if (!$model instanceof CustomerProfile) {
            return;
        }

        $routerId = $model->router_id ? (int) $model->router_id : null;

        $this->emit($model, PartnerEvent::CUSTOMER_DELETED, [
            'service_ids' => UserService::where('user_id', $model->user_id)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'router_id'               => $routerId,
            'managed_by_external_aaa' => $this->radiusByRouter([$routerId])[$routerId] ?? false,
        ]);
    }

    // ─── Traducción ─────────────────────────────────────────────────────────

    private function fromCustomerProfile(CustomerProfile $profile): void
    {
        if ($profile->wasChanged('service_status')) {
            $this->emit(
                $profile,
                $this->statusEvent(
                    (string) $profile->getOriginal('service_status'),
                    (string) $profile->service_status
                ),
                [
                    'service_status' => [
                        'from' => $profile->getOriginal('service_status'),
                        'to'   => $profile->service_status,
                    ],
                ]
            );
        }

        // `customer_profile.service_id` es el PLAN, no un servicio. El nombre
        // es una trampa heredada; ver docs/MANUAL_DESARROLLADOR.md.
        if ($profile->wasChanged('service_id')) {
            $this->emit($profile, PartnerEvent::PLAN_CHANGED, [
                'plan_id' => [
                    'from' => $profile->getOriginal('service_id'),
                    'to'   => $profile->service_id,
                ],
            ]);
        }

        // Cambio de router (KAN-114). El `from` es imprescindible: el recurso
        // sólo muestra el router nuevo, y el integrador necesita el anterior
        // para revocar en ese NAS sin guardar estado propio.
        if ($profile->wasChanged('router_id')) {
            $from   = $profile->getOriginal('router_id') ? (int) $profile->getOriginal('router_id') : null;
            $to     = $profile->router_id ? (int) $profile->router_id : null;
            $radius = $this->radiusByRouter([$from, $to]);

            $this->emit($profile, PartnerEvent::ROUTER_CHANGED, [
                'router_id'               => ['from' => $from, 'to' => $to],
                'managed_by_external_aaa' => [
                    'from' => $radius[$from] ?? false,
                    'to'   => $radius[$to] ?? false,
                ],
            ]);
        }

        $network = [];
        foreach (self::NETWORK_FIELDS as $column => $apiName) {
            if ($profile->wasChanged($column)) {
                $network[] = $apiName;
            }
        }

        if ($network) {
            $this->emit($profile, PartnerEvent::NETWORK_CHANGED, ['fields' => $network]);
        }

        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if ($profile->wasChanged($field)) {
                $identity[] = $field;
            }
        }

        // `is_enabled` suele moverse junto con `service_status` y entonces ya
        // salió un SERVICE_*. Si se mueve solo, el integrador que exige las
        // dos señales para dar acceso se quedaría sin aviso.
        if ($profile->wasChanged('status') && !$profile->wasChanged('service_status')) {
            $identity[] = 'is_enabled';
        }

        if ($identity) {
            $this->emit($profile, PartnerEvent::CUSTOMER_UPDATED, ['fields' => $identity]);
        }
    }

    /**
     * Activar o desactivar RADIUS en un router (KAN-114).
     *
     * Cambia `managed_by_external_aaa` de TODOS sus clientes sin tocar una sola
     * de sus filas, así que ningún otro observer se entera. Se emite un
     * ROUTER_CHANGED por cliente, con el mismo router en `from` y `to`, en
     * inserciones por bloque: por fila sería un round-trip por cliente.
     */
    private function fromRouter(Router $router): void
    {
        if (!$router->wasChanged('radius')) {
            return;
        }

        $from = (bool) $router->getOriginal('radius');
        $to   = (bool) $router->radius;

        // null → false no es un cambio de modo.
        if ($from === $to) {
            return;
        }

        // Tabla y no modelo: sin scopes, y el tenant sale del titular de cada
        // perfil, nunca de la sesión (ver tenantIdFor).
        $customers = DB::table('customer_profile')
            ->join('users', 'users.id', '=', 'customer_profile.user_id')
            ->where('customer_profile.router_id', $router->id)
            ->whereNotNull('users.tenant_id')
            ->get(['customer_profile.user_id', 'users.tenant_id']);

        PartnerEvent::recordMany($customers->map(fn ($c) => [
            'tenant_id'   => (int) $c->tenant_id,
            'event_type'  => PartnerEvent::ROUTER_CHANGED,
            'customer_id' => (int) $c->user_id,
            'changes'     => [
                'router_id'               => ['from' => (int) $router->id, 'to' => (int) $router->id],
                'managed_by_external_aaa' => ['from' => $from, 'to' => $to],
            ],
        ])->all());
    }

    /**
     * Modo RADIUS de cada router, por id. Sin el scope de tenant: el observer
     * corre también desde consola, donde no hay sesión.
     *
     * @param array<int,?int> $routerIds
     * @return array<int,bool>
     */
    private function radiusByRouter(array $routerIds): array
    {
        $ids = array_values(array_unique(array_filter($routerIds)));

        if (!$ids) {
            return [];
        }

        return Router::withoutGlobalScope('tenant')
            ->whereIn('id', $ids)
            ->pluck('radius', 'id')
            ->map(fn ($radius) => (bool) $radius)
            ->all();
    }

    private function fromUserService(UserService $service): void
    {
        if ($service->wasChanged('service_plan_id')) {
            $this->emit($service, PartnerEvent::PLAN_CHANGED, [
                'plan_id' => [
                    'from' => $service->getOriginal('service_plan_id'),
                    'to'   => $service->service_plan_id,
                ],
            ]);
        }
    }

    /**
     * Qué transición ocurrió, en el vocabulario del integrador.
     *
     * La distinción entre ACTIVATED y REACTIVATED importa: un cliente que sale
     * de suspensión por pagar no es lo mismo que uno que se da de alta, y del
     * lado del integrador suele disparar acciones distintas.
     */
    private function statusEvent(string $from, string $to): string
    {
        if (in_array($to, self::ENDED_STATUSES, true)) {
            return PartnerEvent::SERVICE_CANCELLED;
        }

        if ($to === 'suspendido') {
            return PartnerEvent::SERVICE_SUSPENDED;
        }

        if (in_array($to, self::LIVE_STATUSES, true)) {
            return $from === 'suspendido'
                ? PartnerEvent::SERVICE_REACTIVATED
                : PartnerEvent::SERVICE_ACTIVATED;
        }

        return PartnerEvent::CUSTOMER_UPDATED;
    }

    // ─── Interno ────────────────────────────────────────────────────────────

    private function emit(Model $model, string $type, array $changes): void
    {
        // Los dos modelos observados cuelgan del titular por `user_id`.
        $customerId = (int) $model->user_id;
        $tenantId   = $this->tenantIdFor($customerId);

        // Sin tenant no hay a quién publicarle, y escribir el evento con
        // tenant nulo lo dejaría invisible para toda llave de API — peor que
        // no escribirlo, porque parecería registrado.
        if (!$tenantId) {
            return;
        }

        PartnerEvent::record([
            'tenant_id'   => $tenantId,
            'event_type'  => $type,
            'customer_id' => $customerId,
            'service_id'  => $model instanceof UserService ? (int) $model->getKey() : null,
            'changes'     => $changes,
        ]);
    }

    /**
     * El tenant sale del usuario dueño del perfil, nunca de la sesión: estos
     * eventos se emiten también desde consola y colas, donde no hay usuario
     * autenticado, y tomarlo de ahí publicaría el cambio en el inquilino
     * equivocado.
     */
    private function tenantIdFor(int $customerId): ?int
    {
        $tenantId = User::withoutGlobalScopes()
            ->whereKey($customerId)
            ->value('tenant_id');

        return $tenantId ? (int) $tenantId : null;
    }
}
