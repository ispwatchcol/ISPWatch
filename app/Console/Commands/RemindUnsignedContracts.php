<?php

namespace App\Console\Commands;

use App\Exceptions\ContractAlreadySignedException;
use App\Mail\ContractSignatureLinkMail;
use App\Models\ContractSignatureLink;
use App\Models\CustomerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContractSigningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Recuerda al cliente que su contrato sigue sin firmar.
 *
 * POR QUÉ EXISTE
 * --------------
 * Lo que mata este flujo no es que el cliente se niegue a firmar: es que abre
 * el correo en la calle, lo deja para después y el link vence a las 72 h. Sin
 * este recordatorio el ISP se entera semanas más tarde, cuando alguien nota que
 * el cliente lleva meses instalado y sin contrato.
 *
 * LO QUE **NO** HACE: reenviar el mismo link. El token sólo se guarda hasheado,
 * así que ni este comando puede recuperarlo. Cada recordatorio EMITE UN LINK
 * NUEVO (lo que revoca el anterior) y por eso extiende también la vigencia —
 * que es justo lo que hace falta cuando el original está por vencer.
 *
 * UN SOLO recordatorio por link (reminder_sent_at). Insistir a diario a alguien
 * que no quiere firmar es la forma más rápida de acabar en la carpeta de spam
 * del dominio del ISP, que arrastraría también las facturas y los avisos de
 * corte.
 */
class RemindUnsignedContracts extends Command
{
    /**
     * php artisan contracts:remind-unsigned
     * php artisan contracts:remind-unsigned --after=48 --dry-run
     */
    protected $signature = 'contracts:remind-unsigned
                            {--after=24 : Horas que debe llevar el link sin firmarse antes de recordar}
                            {--dry-run : Muestra a quién se le escribiría, sin enviar nada}';

    protected $description = 'Envía UN recordatorio por correo a los clientes con un link de firma enviado y sin usar.';

    public function handle(ContractSigningService $signing): int
    {
        $afterHours = max(1, (int) $this->option('after'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subHours($afterHours);

        // Sólo los que se mandaron POR CORREO desde el sistema: de un link
        // entregado por WhatsApp desde el teléfono del operador no sabemos si
        // llegó, y de uno copiado a mano no sabemos ni a dónde fue.
        $links = ContractSignatureLink::usable()
            ->whereNotNull('sent_at')
            ->where('sent_channel', 'email')
            ->whereNull('reminder_sent_at')
            ->where('sent_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($links->isEmpty()) {
            $this->info('No hay contratos pendientes que recordar.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;

        foreach ($links as $link) {
            $customer = User::find($link->customer_id);

            if (!$customer || !$customer->email) {
                $skipped++;
                continue;
            }

            $profile = CustomerProfile::where('user_id', $customer->id)->first();
            $tenant  = Tenant::find($link->tenant_id);

            if ($dryRun) {
                $this->line("  → {$customer->email} (link #{$link->id})");
                $sent++;
                continue;
            }

            try {
                // Link nuevo: el viejo puede estar a horas de vencer y no hay
                // forma de reenviarlo (sólo existe su hash). issueLink() revoca
                // el anterior dentro de la misma transacción.
                ['link' => $fresh, 'token' => $token] = $signing->issueLink(
                    $customer,
                    createdBy: $link->created_by,
                );

                Mail::to($customer->email)->send(new ContractSignatureLinkMail(
                    customerName: trim(($profile?->name ?? $customer->user_name) . ' ' . ($profile?->last_name ?? $customer->user_lastname ?? '')),
                    companyName: $tenant?->trade_name ?: ($tenant?->legal_name ?: ($tenant?->name ?: 'tu proveedor de internet')),
                    signingUrl: ContractSigningService::signingUrl($token),
                    expiresAt: $fresh->expires_at->timezone(config('app.timezone'))->format('d/m/Y \a \l\a\s H:i'),
                    isReminder: true,
                ));

                // El sello va en el link NUEVO: es el que queda vivo, y sin
                // esto el próximo pase volvería a recordarle mañana.
                $fresh->forceFill([
                    'sent_channel'     => 'email',
                    'sent_to'          => $customer->email,
                    'sent_at'          => now(),
                    'reminder_sent_at' => now(),
                ])->save();

                $sent++;
            } catch (ContractAlreadySignedException) {
                // El ISP acabó firmándolo presencialmente. No es un fallo: el
                // link sobra y se quema para que no vuelva a aparecer aquí.
                $link->forceFill(['revoked_at' => now()])->save();
                $skipped++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Falló el recordatorio de contrato sin firmar.', [
                    'customer_id' => $customer->id,
                    'link_id'     => $link->id,
                    'exception'   => $e->getMessage(),
                ]);
            }
        }

        $verb = $dryRun ? 'Se enviarían' : 'Enviados';
        $this->info("{$verb} {$sent} recordatorio(s)." . ($skipped ? " Omitidos: {$skipped}." : ''));

        return self::SUCCESS;
    }
}
