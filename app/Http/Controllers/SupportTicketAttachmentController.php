<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entrega de adjuntos de un ticket, con autorización por petición.
 *
 * POR QUÉ EXISTE ESTE CONTROLADOR
 *
 * Los adjuntos se servían por `asset('storage/…')`, es decir una URL pública y
 * sin sesión. Eso fallaba y además filtraba:
 *
 *   · FALLABA porque el despliegue de App Platform no ejecuta `storage:link`,
 *     así que `public/storage` no existe y la ruta devolvía 404. Y aunque
 *     existiera, el sistema de archivos del contenedor es EFÍMERO y por
 *     instancia: el archivo subido desaparece en el siguiente despliegue. Es
 *     exactamente lo que se vio con `sp1.jpg` — la fila seguía en la base, la
 *     lista lo mostraba, y la imagen salía rota.
 *
 *   · FILTRABA porque cualquiera con la ruta podía leer el adjunto de otro ISP
 *     sin autenticarse. Las rutas son adivinables: `support_attachments/{id}/…`.
 *
 * Ahora el archivo vive en el disco `s3` —el mismo que ya usan los documentos
 * de cliente— y se entrega por aquí, comprobando en CADA petición que el ticket
 * pertenece al tenant de quien pide.
 *
 * POR QUÉ NO UNA URL FIRMADA TEMPORAL
 *
 * Es lo que hace `CustomerDocument`, y para descargar sirve. Aquí hacía falta
 * además controlar `Content-Disposition`: una URL de Supabase no permite decidir
 * si el navegador pinta la imagen o la descarga, y la vista previa necesita
 * `inline`. Pasar los bytes por la aplicación cuesta un poco más pero deja la
 * autorización y las cabeceras donde se pueden razonar.
 */
class SupportTicketAttachmentController extends Controller
{
    /**
     * Tipos que el navegador pinta y que es seguro devolver en línea.
     *
     * Es una lista blanca, no una comprobación de `image/*`. `mime_type` se
     * guardó al subir el archivo y no se vuelve a verificar; devolver en línea
     * lo que diga esa columna permitiría servir `text/html` desde nuestro propio
     * dominio, que es un XSS almacenado. Lo que no esté aquí se descarga.
     */
    private const VISUALIZABLES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ];

    /** Vista previa: en línea si el navegador puede pintarlo. */
    public function show(Request $request, $ticketId, $attachmentId): Response
    {
        return $this->entregar($request, $ticketId, $attachmentId, forzarDescarga: false);
    }

    /** Descarga explícita, siempre como adjunto. */
    public function download(Request $request, $ticketId, $attachmentId): Response
    {
        return $this->entregar($request, $ticketId, $attachmentId, forzarDescarga: true);
    }

    private function entregar(Request $request, $ticketId, $attachmentId, bool $forzarDescarga): Response
    {
        // `findOrFail` pasa por el scope global de BelongsToTenant, así que un
        // ticket de otro ISP da 404 —no 403— y ni siquiera confirma que exista.
        $ticket = SupportTicket::findOrFail($ticketId);

        // El adjunto se busca DENTRO del ticket. Sin este `where`, adivinar un id
        // de adjunto y colgarlo de un ticket propio serviría el archivo de otro.
        $adjunto = SupportTicketAttachment::where('ticket_id', $ticket->id)
            ->where('id', $attachmentId)
            ->firstOrFail();

        [$disco, $ruta] = $this->localizar($adjunto);

        if ($disco === null) {
            // La fila existe pero el archivo no. Pasa con todo lo subido antes
            // de este cambio: se fue con el contenedor. Un 404 con mensaje es
            // mejor que un icono roto sin explicación.
            return response()->json([
                'message' => 'El archivo adjunto ya no está disponible.',
            ], 404);
        }

        $tipo = (string) ($adjunto->mime_type ?: 'application/octet-stream');
        $enLinea = !$forzarDescarga && in_array($tipo, self::VISUALIZABLES, true);

        return Storage::disk($disco)->download(
            $ruta,
            $adjunto->file_name,
            [
                'Content-Type'        => $enLinea ? $tipo : 'application/octet-stream',
                'Content-Disposition' => $enLinea
                    ? 'inline; filename="' . addslashes($adjunto->file_name) . '"'
                    : 'attachment; filename="' . addslashes($adjunto->file_name) . '"',
                // El adjunto es de un solo tenant: que no acabe en una caché
                // compartida ni en un proxy intermedio.
                'Cache-Control'       => 'private, max-age=0, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Disco donde está realmente el archivo, o `null` si ya no está.
     *
     * Se mira `s3` primero y `public` después porque las filas anteriores a este
     * cambio apuntan al disco local. En producción esos archivos ya no existen
     * —el contenedor se reemplazó— pero en desarrollo sí, y no hay razón para
     * romperlos.
     *
     * @return array{0: ?string, 1: string}
     */
    private function localizar(SupportTicketAttachment $adjunto): array
    {
        $ruta = (string) $adjunto->file_path;

        foreach (['s3', 'public'] as $disco) {
            if (Storage::disk($disco)->exists($ruta)) {
                return [$disco, $ruta];
            }
        }

        return [null, $ruta];
    }
}
