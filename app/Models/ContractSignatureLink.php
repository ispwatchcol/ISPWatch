<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Link de firma remota del contrato. Ver la migración
 * 2026_08_13_150000_create_contract_signature_links para el porqué del diseño.
 */
class ContractSignatureLink extends Model
{
    protected $table = 'contract_signature_links';

    /** Vigencia por defecto del link, en horas. */
    public const DEFAULT_TTL_HOURS = 72;

    /** Intentos de verificación de cédula antes de quemar el link. */
    public const MAX_FAILED_ATTEMPTS = 5;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'token_hash',
        'expires_at',
        'created_by',
        'sent_channel',
        'sent_to',
        'sent_at',
        'reminder_sent_at',
        'opened_at',
        'verified_at',
        'failed_attempts',
        'signed_at',
        'signer_ip',
        'signer_user_agent',
        'document_id',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at'       => 'datetime',
        'sent_at'          => 'datetime',
        'reminder_sent_at' => 'datetime',
        'opened_at'        => 'datetime',
        'verified_at'      => 'datetime',
        'signed_at'        => 'datetime',
        'revoked_at'       => 'datetime',
        'failed_attempts'  => 'integer',
    ];

    /**
     * El token en claro NUNCA se persiste — sólo existe en memoria el instante
     * en que se genera y viaja al operador para armar la URL.
     */
    protected $hidden = ['token_hash'];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function document()
    {
        return $this->belongsTo(CustomerDocument::class, 'document_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Token en claro para la URL. Su hash es lo único que se guarda. */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isLockedOut(): bool
    {
        return $this->failed_attempts >= self::MAX_FAILED_ATTEMPTS;
    }

    /** ¿Se puede firmar con este link ahora mismo? */
    public function isUsable(): bool
    {
        return !$this->isSigned()
            && !$this->isRevoked()
            && !$this->isExpired()
            && !$this->isLockedOut();
    }

    /**
     * Motivo por el que el link no sirve, en el orden en que le importa a quien
     * abre la página. Devuelve null si el link es usable.
     */
    public function unusableReason(): ?string
    {
        return match (true) {
            $this->isSigned()    => 'signed',
            $this->isRevoked()   => 'revoked',
            $this->isExpired()   => 'expired',
            $this->isLockedOut() => 'locked',
            default              => null,
        };
    }

    public function scopeUsable($query)
    {
        return $query->whereNull('signed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where('failed_attempts', '<', self::MAX_FAILED_ATTEMPTS);
    }
}
