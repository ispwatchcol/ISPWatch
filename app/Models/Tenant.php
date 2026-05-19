<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property string $uuid Unique public identifier for the tenant (UUID v4)
 * @property string $domain URL-friendly slug (e.g. "ispwatch-pruebas")
 * @property string|null $legal_name Legal or corporate name of the Colombian company
 * @property string|null $trade_name Commercial or trade name of the company
 * @property string|null $nit Tax Identification Number (Número de Identificación Tributaria)
 * @property string|null $nit_verification_digit Verification digit for the NIT
 * @property string|null $tax_regime Tax regime classification in Colombia
 * @property string|null $economic_activity Primary economic activity
 * @property string|null $billing_email Email address for electronic billing and communications
 * @property string|null $billing_phone Contact phone number for billing purposes
 * @property string|null $billing_address Physical address for billing
 * @property string|null $city City of the billing address
 * @property string|null $department Department or state of the billing address
 * @property string|null $country Two-letter country code
 * @property string|null $google_maps_api_key Per-tenant Google Maps JavaScript API key for the customer map
 */
class Tenant extends Model
{
    use HasFactory;

    protected $table = 'tenant';

    protected $fillable = [
        'uuid',
        'name',
        'domain',
        'status',
        'max_customers',
        'logo',
        'email_tenant',
        'tel_tenant',
        'address_tenant',
        'zone_tenant',
        'currency_tenant',
        'timezone',
        'currency',
        'next_invoice_number',
        'legal_name',
        'trade_name',
        'nit',
        'nit_verification_digit',
        'tax_regime',
        'economic_activity',
        'billing_email',
        'billing_phone',
        'billing_address',
        'city',
        'department',
        'country',
        'google_maps_api_key',
    ];

    /** Auto-generate UUID on creation if not provided */
    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if (empty($tenant->uuid)) {
                $tenant->uuid = (string) Str::uuid();
            }
        });
    }
}

