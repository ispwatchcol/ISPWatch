<?php

namespace App\Services\Templates;

use App\Models\Billing;
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
            'cliente.nombre'            => (string) ($customer?->user_name ?: ''),
            'cliente.apellido'          => (string) ($customer?->user_lastname ?: ''),
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
        string $date,
        ?string $contractNumber = null
    ): array {
        return [
            'empresa.nombre'        => $tenant->legal_name ?: $tenant->trade_name ?: $tenant->name ?: '',
            'empresa.nit'           => $this->formatNit($tenant),
            'cliente.nombre'        => (string) ($customer->user_name ?: ''),
            'cliente.apellido'      => (string) ($customer->user_lastname ?: ''),
            'cliente.cedula'        => (string) ($profile?->cedula ?: ''),
            'cliente.direccion'     => (string) ($profile?->address ?: ''),
            'cliente.email'         => (string) ($customer->email ?: ''),
            'cliente.telefono'      => (string) ($customer->tel ?: ''),
            'cliente.ip'            => (string) ($profile?->ip_user ?: ''),
            'plan.nombre'           => (string) ($plan?->name ?: ''),
            'plan.velocidad_bajada' => (string) ($plan?->speed_down ?: ''),
            'plan.velocidad_subida' => (string) ($plan?->speed_up ?: ''),
            'plan.valor_mensual'    => $plan ? number_format((float) $plan->cost_product, 0, ',', '.') : '',
            'contrato.numero'       => (string) ($contractNumber ?: ''),
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
        string $date,
        ?Plan $plan = null
    ): array {
        $firstName = $customer ? (string) $customer->user_name : (string) ($prospect?->name ?? '');
        $lastName  = $customer ? (string) $customer->user_lastname : (string) ($prospect?->last_name ?? '');

        $billing = $profile?->router?->billing;

        return [
            'empresa.nombre'            => $tenant->legal_name ?: $tenant->trade_name ?: $tenant->name ?: '',
            'cliente.nombre'            => $firstName,
            'cliente.apellido'          => $lastName,
            'cliente.cedula'            => (string) ($profile?->cedula ?: $prospect?->cedula ?: ''),
            'cliente.direccion'         => (string) ($installation->address ?: $profile?->address ?: $prospect?->address ?: ''),
            'instalacion.numero'        => (string) $installation->id,
            'instalacion.fecha'         => $date,
            'instalacion.tecnico'       => (string) ($technician?->name ?: $installation->technician ?: ''),
            'instalacion.equipo'        => (string) ($installation->equipment ?? ''),
            'instalacion.observaciones' => (string) ($installation->notes ?? ''),
            'servicio.plan'             => (string) ($plan?->name ?: ''),
            'servicio.velocidad_bajada' => (string) ($plan?->speed_down ?: ''),
            'servicio.velocidad_subida' => (string) ($plan?->speed_up ?: ''),
            'servicio.ip'               => (string) ($profile?->ip_user ?: ''),
            'servicio.usuario_pppoe'    => (string) ($profile?->pppoe_username ?: ''),
            'servicio.punto_acceso'     => (string) ($profile?->sectorial?->name ?: ''),
            'servicio.dia_pago'         => $billing ? (string) (Billing::dayOf($billing->payment_day) ?? '') : '',
            'servicio.dia_corte'        => $billing ? (string) ($billing->cut_day_of_month ?? '') : '',
        ];
    }

    /**
     * Replaces every known {{namespace.campo}} token with its HTML-escaped
     * value and blanks out any token not present in $values (unknown/mistyped
     * placeholders never break rendering).
     *
     * Escaping happens here, at substitution time, rather than via a later
     * full-document sanitize pass (see TemplateRenderer::compile()): this is
     * what makes it safe to call apply() on HTML that already contains
     * trusted, unescaped block markup — a second sanitize pass would corrupt
     * that markup, but an escaped scalar value is inert in both content and
     * attribute position regardless of what else is on the page.
     */
    public function apply(string $html, array $values): string
    {
        $result = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static fn (array $m) => htmlspecialchars($values[$m[1]] ?? '', ENT_QUOTES, 'UTF-8'),
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
