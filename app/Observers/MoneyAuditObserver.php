<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Billing;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Bitácora automática de todo lo que mueve plata.
 *
 * Está montado como observer de Eloquent y no como instrumentación de los
 * controladores por una razón concreta: el episodio que originó esto entró por
 * dos puertas distintas. El precio del plan de Tocaima se cambió desde
 * PlanController, pero los planes equivocados de Chaguaní se reasignaron en
 * masa desde CustomersUpdateImport, que no pasa por ningún controlador de
 * planes. Instrumentar controladores habría dejado ciega justo la mitad del
 * problema. Un observer atrapa las cuatro puertas: panel, API, carga masiva y
 * consola (incluido tinker).
 *
 * Cada modelo declara qué atributos se vigilan. La lista es corta a propósito:
 * si se auditara todo, el volumen haría la bitácora inútil para leer.
 *
 * credit_balance NO se vigila aquí: tiene su propio libro de movimientos en
 * customer_credits, con mucho más detalle del que cabría en un audit_log.
 */
class MoneyAuditObserver
{
    /**
     * Atributos vigilados por modelo, con su etiqueta para el texto legible.
     */
    protected const WATCHED = [
        Plan::class => [
            'cost_product'              => 'precio',
            'name'                      => 'nombre',
            'is_courtesy'               => 'cortesía',
            'first_invoice_mode'        => 'modo de primera factura',
            'first_invoice_free_months' => 'meses gratis',
        ],
        CustomerProfile::class => [
            'service_id'           => 'plan',
            'exclude_from_billing' => 'excluido de facturación',
            'service_status'       => 'estado del servicio',
        ],
        Payment::class => [
            'amount'       => 'monto',
            'payment_date' => 'fecha',
            'status'       => 'estado',
            'method'       => 'método',
        ],
        Invoice::class => [
            // balance_due queda fuera: cambia en cada pago y esos ya dejan
            // asiento en payment_allocations o en customer_credits.
            'total' => 'total',
        ],
        Billing::class => [
            'create_invoice'        => 'día de facturación',
            'create_invoice_time'   => 'hora de facturación',
            'cut_day'               => 'día de corte',
            'cut_time'              => 'hora de corte',
            'payment_reminder'      => 'día de recordatorio',
            'payment_reminder_time' => 'hora de recordatorio',
            'overdue_invoices'      => 'facturas vencidas para corte',
            'stop_invoicing_extra'  => 'tope extra de facturación',
            'first_invoice_policy'  => 'política de primera factura',
            'billing_mode'          => 'modo de facturación',
        ],
    ];

    /** Modelos cuya alta y baja también interesa registrar. */
    protected const LIFECYCLE = [Plan::class, Payment::class];

    public function created(Model $model): void
    {
        if (!in_array(get_class($model), self::LIFECYCLE, true)) {
            return;
        }

        $this->write($model, 'created', null, $this->watchedValues($model), $this->describeLifecycle($model, 'creado'));
    }

    public function updated(Model $model): void
    {
        $watched = self::WATCHED[get_class($model)] ?? null;
        if (!$watched) {
            return;
        }

        $old = [];
        $new = [];

        foreach (array_keys($watched) as $attribute) {
            if (!$model->wasChanged($attribute)) {
                continue;
            }

            $old[$attribute] = $this->scalar($model->getOriginal($attribute));
            $new[$attribute] = $this->scalar($model->getAttribute($attribute));
        }

        if (!$old) {
            return;
        }

        $this->write($model, 'updated', $old, $new, $this->describeChange($model, $watched, $old, $new));
    }

    public function deleted(Model $model): void
    {
        if (!in_array(get_class($model), self::LIFECYCLE, true)) {
            return;
        }

        $this->write($model, 'deleted', $this->watchedValues($model), null, $this->describeLifecycle($model, 'eliminado'));
    }

    // ─── Interno ────────────────────────────────────────────────────────────

    /**
     * La bitácora nunca puede tumbar la operación que está auditando.
     *
     * Un pago tiene que quedar registrado aunque el log falle: perder la
     * trazabilidad de un movimiento es malo, pero perder el movimiento es peor.
     *
     * En PostgreSQL además no es solo el registro que se pierde: una excepción
     * dentro de la transacción la deja abortada, y a partir de ahí toda consulta
     * revienta con «current transaction is aborted». Es decir, sin este try el
     * observer puede tumbar en cadena todo lo que venga detrás. SQLite no tiene
     * ese estado, así que un fallo así pasaría inadvertido en local.
     *
     * Y atrapar la excepción NO basta: en PostgreSQL la transacción queda
     * abortada igual, porque sólo un ROLLBACK la recupera. Por eso la escritura
     * va dentro de `transaction()`, que emite un SAVEPOINT cuando ya hay una
     * transacción abierta y hace ROLLBACK TO SAVEPOINT si falla: el daño queda
     * acotado a la bitácora y la transacción de negocio sigue utilizable. Sin
     * esto el `try` da falsa confianza — el pago se pierde de todos modos.
     */
    protected function write(Model $model, string $verb, ?array $old, ?array $new, string $description): void
    {
        $entry = [
            'tenant_id'   => $this->tenantIdFor($model),
            'action'      => $this->actionName($model) . '.' . $verb,
            'model_type'  => get_class($model),
            'model_id'    => $model->getKey(),
            'old_values'  => $old,
            'new_values'  => $new,
            'description' => $description,
        ];

        try {
            AuditLog::query()->getConnection()->transaction(
                static fn () => AuditLog::log($entry)
            );
        } catch (\Throwable $e) {
            Log::error('Audit: no se pudo registrar el cambio en la bitácora.', [
                'model'     => get_class($model),
                'model_id'  => $model->getKey(),
                'verb'      => $verb,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** `App\Models\Plan` → `plan`, para acciones tipo `plan.updated`. */
    protected function actionName(Model $model): string
    {
        return \Illuminate\Support\Str::snake(class_basename($model));
    }

    protected function tenantIdFor(Model $model): ?int
    {
        if ($model instanceof CustomerProfile) {
            return AuditContext::tenantIdForCustomer((int) $model->user_id);
        }

        $tenantId = $model->getAttribute('tenant_id');

        return $tenantId ? (int) $tenantId : AuditContext::currentTenantId();
    }

    protected function watchedValues(Model $model): array
    {
        $watched = self::WATCHED[get_class($model)] ?? [];
        $values  = [];

        foreach (array_keys($watched) as $attribute) {
            $values[$attribute] = $this->scalar($model->getAttribute($attribute));
        }

        return $values;
    }

    /**
     * Los valores viajan a una columna JSON: fechas y objetos casteados tienen
     * que salir como texto o el encode los deja irreconocibles.
     */
    protected function scalar($value)
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * Texto legible para el visor. El objetivo es que alguien que no sabe SQL
     * pueda leer "el precio pasó de 56.000 a 60.000" sin abrir el JSON.
     */
    protected function describeChange(Model $model, array $watched, array $old, array $new): string
    {
        $parts = [];

        foreach ($old as $attribute => $before) {
            $parts[] = sprintf(
                '%s: %s → %s',
                $watched[$attribute],
                $this->readable($model, $attribute, $before),
                $this->readable($model, $attribute, $new[$attribute])
            );
        }

        return $this->subject($model) . ' — ' . implode('; ', $parts);
    }

    protected function describeLifecycle(Model $model, string $verb): string
    {
        return $this->subject($model) . " {$verb}";
    }

    /** Nombre humano del registro tocado. */
    protected function subject(Model $model): string
    {
        return match (true) {
            $model instanceof Plan            => "Plan «{$model->name}»",
            $model instanceof CustomerProfile => 'Cliente ' . trim("{$model->name} {$model->last_name}"),
            $model instanceof Payment         => "Pago #{$model->id}",
            $model instanceof Invoice         => "Factura {$model->number}",
            $model instanceof Billing         => "Configuración de facturación #{$model->id}",
            default                           => class_basename($model) . " #{$model->getKey()}",
        };
    }

    /**
     * Un `service_id` de 40 no le dice nada a nadie; el nombre del plan sí.
     * Igual con los booleanos, que en JSON salen como 1/0.
     */
    protected function readable(Model $model, string $attribute, $value): string
    {
        if ($value === null || $value === '') {
            return '(vacío)';
        }

        if ($attribute === 'service_id') {
            $name = Plan::withoutGlobalScope('tenant')->whereKey($value)->value('name');

            return $name ? "«{$name}» (#{$value})" : "#{$value}";
        }

        if (is_bool($value)) {
            return $value ? 'sí' : 'no';
        }

        if (in_array($attribute, ['cost_product', 'amount', 'total'], true)) {
            return '$' . number_format((float) $value, 0, ',', '.');
        }

        return (string) $value;
    }
}
