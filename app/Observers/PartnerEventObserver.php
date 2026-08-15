<?php

namespace App\Observers;

use App\Models\CustomerProfile;
use App\Models\PartnerEvent;
use App\Models\User;
use App\Models\UserService;
use Illuminate\Database\Eloquent\Model;

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
 * LÍMITE CONOCIDO: LAS ACTUALIZACIONES MASIVAS POR QUERY BUILDER NO DISPARAN
 * ---------------------------------------------------------------------------
 * `Modelo::where(...)->update([...])` no pasa por Eloquent y por lo tanto no
 * emite evento. Hoy los caminos que mueven estado comercial usan instancias
 * (`$profile->save()`, `$service->update()`), incluida la carga masiva, así que
 * están cubiertos. Si algún día se agrega un camino masivo por query builder,
 * hay que emitir el evento a mano — no es opcional: el integrador quedaría
 * desincronizado sin ninguna señal.
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
        }
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

        $identity = [];
        foreach (self::IDENTITY_FIELDS as $field) {
            if ($profile->wasChanged($field)) {
                $identity[] = $field;
            }
        }

        if ($identity) {
            $this->emit($profile, PartnerEvent::CUSTOMER_UPDATED, ['fields' => $identity]);
        }
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
