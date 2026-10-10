<?php

namespace App\Console\Commands;

use App\Mail\ApiKeyExpiringMail;
use App\Models\ApiClient;
use App\Models\PersonalAccessToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa por correo de las llaves de la API pública que vencen pronto
 * (P-KEYS-1 / KAN-43).
 *
 * El vencimiento obligatorio de 90 días tumba la integración el día que se
 * cumple, y hasta ahora nada lo anticipaba. Una semana de margen basta para
 * emitir la llave nueva y cambiarla del otro lado.
 *
 * Reglas:
 *  - Una sola vez por llave (`expiry_notified_at`). Si un día no corre el
 *    planificador, la corrida siguiente avisa igual y la de después no repite.
 *  - Sólo llaves vivas: no revocadas, no vencidas, de un ApiClient activo.
 *  - Si la integración ya tiene otra llave viva que dura más allá del margen,
 *    ya rotó y no se avisa: sería ruido sobre un problema resuelto.
 *  - Destinatarios: el contacto de la integración (`api_clients.contact_email`)
 *    y el operador (`api_keys.self_service.notify_email`). Sin ninguno de los
 *    dos, queda un warning en el log y la llave NO se marca, para que el aviso
 *    salga en cuanto se configure un destinatario.
 */
class NotifyExpiringApiKeys extends Command
{
    protected $signature = 'api-keys:expiring
                            {--days=7 : Margen en días antes del vencimiento}
                            {--dry-run : Sólo lista las llaves, sin enviar ni marcar}';

    protected $description = 'Avisa por correo de las llaves de la API pública que vencen en los próximos N días';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        if ($days < 1) {
            $this->error('El margen debe ser de al menos 1 día.');

            return Command::FAILURE;
        }

        $limit = now()->addDays($days);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', ApiClient::class)
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $limit)
            ->orderBy('expires_at')
            ->get();

        $stats = ['candidates' => $tokens->count(), 'notified' => 0, 'rotated' => 0, 'no_recipient' => 0, 'failed' => 0];

        foreach ($tokens as $token) {
            $client = ApiClient::withoutGlobalScopes()->find($token->tokenable_id);

            if (!$client || !$client->is_active) {
                $stats['candidates']--;
                continue;
            }

            if ($this->alreadyRotated($token, $limit)) {
                $stats['rotated']++;
                continue;
            }

            $recipients = $this->recipientsFor($client);

            if ($recipients === []) {
                $stats['no_recipient']++;
                Log::warning('Llave de API por vencer sin nadie a quien avisar', [
                    'token_id'      => $token->getKey(),
                    'api_client_id' => $client->getKey(),
                    'tenant_id'     => $client->tenant_id,
                    'expires_at'    => $token->expires_at?->toIso8601String(),
                ]);
                continue;
            }

            $this->line(sprintf(
                '%s llave #%d «%s» (%s) vence %s → %s',
                $dryRun ? '[dry-run]' : 'Aviso',
                $token->getKey(),
                $token->name,
                $client->name,
                $token->expires_at->toDateString(),
                implode(', ', $recipients)
            ));

            if ($dryRun) {
                continue;
            }

            try {
                Mail::to($recipients)->send(new ApiKeyExpiringMail(
                    integrationName: (string) $client->name,
                    keyName: (string) $token->name,
                    tenantId: (int) $client->tenant_id,
                    expiresAt: $token->expires_at->format('Y-m-d H:i'),
                    daysLeft: max(0, (int) ceil(now()->diffInHours($token->expires_at) / 24)),
                    lastUsedAt: $token->last_used_at?->format('Y-m-d H:i'),
                ));

                $token->forceFill(['expiry_notified_at' => now()])->save();
                $stats['notified']++;
            } catch (Throwable $e) {
                // Sin marcar: la corrida de mañana lo vuelve a intentar.
                $stats['failed']++;
                Log::warning('No se pudo avisar del vencimiento de una llave de API', [
                    'token_id' => $token->getKey(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->table(['Métrica', 'Cantidad'], [
            ['Por vencer (vivas)',       $stats['candidates']],
            ['Avisadas',                 $stats['notified']],
            ['Ya rotadas (sin aviso)',   $stats['rotated']],
            ['Sin destinatario',         $stats['no_recipient']],
            ['Fallaron (se reintenta)',  $stats['failed']],
        ]);

        return Command::SUCCESS;
    }

    /** ¿La integración ya tiene otra llave viva que dura más allá del margen? */
    private function alreadyRotated(PersonalAccessToken $token, \DateTimeInterface $limit): bool
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', ApiClient::class)
            ->where('tokenable_id', $token->tokenable_id)
            ->whereKeyNot($token->getKey())
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $limit))
            ->exists();
    }

    /** @return string[] */
    private function recipientsFor(ApiClient $client): array
    {
        return array_values(array_unique(array_filter([
            $client->contact_email ? strtolower(trim($client->contact_email)) : null,
            ($operator = config('api_keys.self_service.notify_email')) ? strtolower(trim($operator)) : null,
        ])));
    }
}
