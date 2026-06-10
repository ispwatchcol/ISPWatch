<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use BelongsToTenant;

    protected $table = 'router';
    protected $fillable = [
        'name',
        'tenant_id',
        'ip',
        'ipv6',
        'failover',
        'external_id',
        'user_rb',
        'password_rb',
        'user_rb_encrypted',
        'password_rb_encrypted',
        'puerto_api',
        'puerto_www',
        'lan_interface',
        'wan_interface',
        'vpn_username',
        'vpn_password',
        'vpn_username_encrypted',
        'vpn_password_encrypted',
        'comments',
        'rangos_ip',
        'cut_type_id',
        'billing_router_id',
        'firmware_version',
        'status',
        'coordinates',
        'agregar_cliente_mkt',
        'historial_trafico',
        'simple_queue',
        'control_pcq',
        'hotspot',
        'pppoe',
        'pppoe_limit_mode',
        'ip_bindings',
        'amarre',
        'dhcp_leases',
        'falla_general',
    ];

    public $timestamps = true;

    protected $casts = [
        'coordinates' => 'json',
        'agregar_cliente_mkt' => 'boolean',
        'historial_trafico' => 'boolean',
        'simple_queue' => 'boolean',
        'control_pcq' => 'boolean',
        'hotspot' => 'boolean',
        'pppoe' => 'boolean',
        'ip_bindings' => 'boolean',
        'amarre' => 'boolean',
        'dhcp_leases' => 'boolean',
        'falla_general' => 'boolean',
        // NOTE: the *_encrypted columns are deliberately NOT cast as 'encrypted'.
        // Migration 2026_05_14_000001_encrypt_router_credentials copied PLAINTEXT
        // into them via raw SQL (the comment there wrongly assumed the cast would
        // encrypt on access). The 'encrypted' cast decrypts on read, so it threw
        // DecryptException on every read in EVERY environment, breaking VPN script
        // generation and provisioning. These columns hold plaintext (same as the
        // intact legacy user_rb/password_rb/vpn_* columns); treat them as plaintext.
        // Re-introduce real at-rest encryption later via a correct re-save migration.
    ];


    public function cutType()
    {
        return $this->belongsTo(CutType::class, 'cut_type_id');
    }

    public function billingConfig()
    {
        return $this->belongsTo(Billing::class, 'billing_router_id');
    }

    /**
     * Alias of billingConfig() that serializes under the `billing` key, which
     * is the shape the router add/edit form expects (data.billing.*).
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class, 'billing_router_id');
    }

    public function suspensionLogs()
    {
        return $this->hasMany(SuspensionActionLog::class);
    }

    public function customers()
    {
        return $this->hasMany(CustomerProfile::class, 'router_id');
    }
}
