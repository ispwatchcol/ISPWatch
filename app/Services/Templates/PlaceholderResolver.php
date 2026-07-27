<?php

namespace App\Services\Templates;

use App\Models\CustomerInstallation;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Prospect;
use App\Models\Tenant;
use App\Models\User;

/**
 * Builds the {{namespace.campo}} => valor maps for each document type (see
 * config/document_placeholders.php for the closed whitelist) and applies
 * them to a tenant's stored template body.
 *
 * apply() never evaluates the stored HTML as Blade/PHP: it only does a
 * plain-text token substitution, so a tenant's body_html can never execute
 * server-side code regardless of what it contains.
 */
class PlaceholderResolver
{
    public function forInvoice(Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $customer = $invoice->customer;
        $profile = $customer?->customerProfile;

        return [
            'empresa.nombre'            => $tenant?->legal_name ?: $tenant?->name ?: '',
            'empresa.nombre_comercial'  => $tenant?->trade_name ?: '',
            'empresa.nit'               => $this->formatNit($tenant),
            'empresa.direccion'         => $tenant?->billing_address ?: $tenant?->address_tenant ?: '',
            'empresa.telefono'          => $tenant?->billing_phone ?: $tenant?->tel_tenant ?: '',
            'empresa.email'             => $tenant?->billing_email ?: $tenant?->email_tenant ?: '',
            'empresa.ciudad'            => $tenant?->city ?: $tenant?->zone_tenant ?: '',
            'cliente.nombre'            => (string) ($customer?->name ?: ''),
            'cliente.cedula'            => (string) ($profile?->cedula ?: ''),
            'cliente.direccion'         => (string) ($profile?->address ?: ''),
            'cliente.email'             => (string) ($customer?->email ?: ''),
            'cliente.telefono'          => (string) ($customer?->tel ?: ''),
            'factura.numero'            => (string) ($invoice->number ?? ''),
            'factura.fecha_emision'     => $invoice->issue_date?->format('d/m/Y') ?: '',
            'factura.fecha_vencimiento' => $invoice->due_date?->format('d/m/Y') ?: '',
            'factura.periodo'           => $this->formatPeriod($invoice),
            'factura.subtotal'          => number_format((float) $invoice->subtotal, 2),
            'factura.impuestos'         => number_format((float) $invoice->tax, 2),
            'factura.total'             => number_format((float) $invoice->total, 2),
            'factura.saldo'             => number_format((float) $invoice->balance_due, 2),
            'factura.estado'            => (string) ($invoice->status ?? ''),
            'factura.notas'             => (string) ($invoice->notes ?? ''),
        ];
    }

    public function forContract(
        User $customer,
        ?CustomerProfile $profile,
        Tenant $tenant,
        ?Plan $plan,
        string $date
    ): array {
        return [
            'empresa.nombre'        => $tenant->legal_name ?: $tenant->trade_name ?: $tenant->name ?: '',
            'empresa.nit'           => $this->formatNit($tenant),
            'cliente.nombre'        => (string) ($customer->name ?: ''),
            'cliente.cedula'        => (string) ($profile?->cedula ?: ''),
            'cliente.direccion'     => (string) ($profile?->address ?: ''),
            'cliente.email'         => (string) ($customer->email ?: ''),
            'cliente.telefono'      => (string) ($customer->tel ?: ''),
            'cliente.ip'            => (string) ($profile?->ip_user ?: ''),
            'plan.nombre'           => (string) ($plan?->name ?: ''),
            'plan.velocidad_bajada' => (string) ($plan?->speed_down ?: ''),
            'plan.velocidad_subida' => (string) ($plan?->speed_up ?: ''),
            'plan.valor_mensual'    => $plan ? number_format((float) $plan->cost_product, 0, ',', '.') : '',
            'contrato.fecha'        => $date,
        ];
    }

    public function forInstallation(
        CustomerInstallation $installation,
        ?User $customer,
        ?CustomerProfile $profile,
        ?Prospect $prospect,
        Tenant $tenant,
        ?User $technician,
        string $date
    ): array {
        $name = $customer
            ? (string) $customer->name
            : trim((string) ($prospect?->name ?? '') . ' ' . (string) ($prospect?->last_name ?? ''));

        return [
            'empresa.nombre'            => $tenant->legal_name ?: $tenant->trade_name ?: $tenant->name ?: '',
            'cliente.nombre'            => $name,
            'cliente.cedula'            => (string) ($profile?->cedula ?: $prospect?->cedula ?: ''),
            'cliente.direccion'         => (string) ($installation->address ?: $profile?->address ?: $prospect?->address ?: ''),
            'instalacion.numero'        => (string) $installation->id,
            'instalacion.fecha'         => $date,
            'instalacion.tecnico'       => (string) ($technician?->name ?: $installation->technician ?: ''),
            'instalacion.equipo'        => (string) ($installation->equipment ?? ''),
            'instalacion.observaciones' => (string) ($installation->notes ?? ''),
        ];
    }

    /**
     * Replaces every known {{namespace.campo}} token with its value and
     * blanks out any token not present in $values (unknown/mistyped
     * placeholders never break rendering).
     */
    public function apply(string $html, array $values): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static fn (array $m) => $values[$m[1]] ?? '',
            $html
        );

        return $result ?? '';
    }

    private function formatNit(?Tenant $tenant): string
    {
        if (!$tenant?->nit) {
            return '';
        }

        return $tenant->nit . ($tenant->nit_verification_digit ? '-' . $tenant->nit_verification_digit : '');
    }

    private function formatPeriod(Invoice $invoice): string
    {
        if (!$invoice->period_start || !$invoice->period_end) {
            return '';
        }

        return $invoice->period_start->format('d/m/Y') . ' al ' . $invoice->period_end->format('d/m/Y');
    }
}
