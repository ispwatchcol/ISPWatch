<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     * Authorization logic is handled in the controller via role checks.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // ── General ────────────────────────────────────────────────────────
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', 'max:50'],
            'max_customers' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:10'],
            'next_invoice_number' => ['sometimes', 'nullable', 'integer', 'min:1'],

            // ── Contact / Legacy ────────────────────────────────────────────────
            'email_tenant' => ['sometimes', 'nullable', 'email', 'max:255'],
            'tel_tenant' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address_tenant' => ['sometimes', 'nullable', 'string', 'max:500'],
            'zone_tenant' => ['sometimes', 'nullable', 'string', 'max:100'],
            'currency_tenant' => ['sometimes', 'nullable', 'string', 'max:10'],

            // ── Colombian Company Info ──────────────────────────────────────────
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Colombian NIT: digits only, optional dash before verification digit
            // e.g. "900123456" or "900123456-7"
            'nit' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^\d+(-\d)?$/'],
            'nit_verification_digit' => ['sometimes', 'nullable', 'string', 'max:5'],
            'tax_regime' => ['sometimes', 'nullable', 'string', 'max:100'],
            'economic_activity' => ['sometimes', 'nullable', 'string', 'max:255'],

            // ── Billing ─────────────────────────────────────────────────────────
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'billing_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'billing_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],

            // ── Integrations ────────────────────────────────────────────────────
            'google_maps_api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Custom human-readable attribute names for error messages.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'domain' => 'dominio',
            'email_tenant' => 'correo de contacto',
            'tel_tenant' => 'teléfono de contacto',
            'address_tenant' => 'dirección de contacto',
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'nit' => 'NIT',
            'nit_verification_digit' => 'dígito de verificación',
            'tax_regime' => 'régimen tributario',
            'economic_activity' => 'actividad económica',
            'billing_email' => 'correo de facturación',
            'billing_phone' => 'teléfono de facturación',
            'billing_address' => 'dirección de facturación',
            'city' => 'ciudad',
            'department' => 'departamento',
            'country' => 'país',
            'google_maps_api_key' => 'clave de API de Google Maps',
        ];
    }

    /**
     * Custom error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nit.regex' => 'El NIT debe contener solo dígitos con guion opcional (ej: 900123456 o 900123456-7).',
            'country.size' => 'El código de país debe tener exactamente 2 caracteres (ISO 3166-1 alpha-2).',
        ];
    }
}
