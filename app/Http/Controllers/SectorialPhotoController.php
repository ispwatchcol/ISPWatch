<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Models\SectorialHistory;
use App\Models\SectorialPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class SectorialPhotoController extends Controller
{
    /** Tipos que el navegador pinta y que es seguro devolver en línea. */
    private const VISUALIZABLES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function index(Request $request, $sectorialId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);

        $photos = $sectorial->photos()
            ->with('user:id,user_name,user_lastname')
            ->get();

        return response()->json($photos);
    }

    public function store(Request $request, $sectorialId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);

        $request->validate([
            'photos'   => 'required|array|min:1',
            'photos.*' => 'file|image|max:10240|mimes:jpg,jpeg,png,webp,gif',
            'caption'  => 'nullable|string|max:255',
        ]);

        $created = [];

        foreach ($request->file('photos') as $file) {
            $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file->getClientOriginalName());
            // `s3`, no `public`: el disco local de App Platform es efímero y el
            // despliegue no ejecuta `storage:link` (P-40 / KAN-96).
            $filePath = $file->storeAs(
                "sectorial_photos/{$sectorial->id}",
                $fileName,
                's3'
            );

            if ($filePath === false) {
                // El disco s3 va con `throw => false`: un fallo de subida vuelve
                // como false. Sin esta salida quedaría una fila que apunta a nada.
                return response()->json(['message' => 'No se pudo guardar la foto. Intenta de nuevo.'], 502);
            }

            $photo = SectorialPhoto::create([
                'sectorial_id' => $sectorial->id,
                'user_id'      => $request->user()?->id,
                'tenant_id'    => $sectorial->tenant_id,
                'file_name'    => $file->getClientOriginalName(),
                'file_path'    => $filePath,
                'file_size'    => $file->getSize(),
                'mime_type'    => $file->getMimeType(),
                'caption'      => $request->input('caption'),
            ]);

            $created[] = $photo->load('user:id,user_name,user_lastname');
        }

        SectorialHistory::log(
            $sectorial->id,
            'photo_added',
            'Se agregaron ' . count($created) . ' foto(s)',
            ['count' => count($created)]
        );

        return response()->json([
            'message' => 'Fotos subidas correctamente.',
            'photos'  => $created,
        ], 201);
    }

    public function destroy(Request $request, $sectorialId, $photoId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);
        $photo = SectorialPhoto::where('sectorial_id', $sectorial->id)->findOrFail($photoId);

        if ($photo->file_path) {
            // Los dos discos: las filas anteriores a P-40 apuntan a `public`.
            foreach (['s3', 'public'] as $disco) {
                Storage::disk($disco)->delete($photo->file_path);
            }
        }
        $photo->delete();

        SectorialHistory::log(
            $sectorial->id,
            'photo_removed',
            'Se eliminó una foto',
            ['photo_id' => (int) $photoId]
        );

        return response()->json(['message' => 'Foto eliminada.']);
    }

    /**
     * Entrega una foto con autorización por petición (P-40 / KAN-96).
     *
     * Mismo patrón que SupportTicketAttachmentController: el sectorial se busca
     * en el tenant de quien pide (otro ISP → 404, sin confirmar que existe) y
     * la foto DENTRO de ese sectorial, para que colgar un id ajeno de un
     * sectorial propio no sirva el archivo de otro.
     */
    public function show(Request $request, $sectorialId, $photoId): Response
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);
        $photo = SectorialPhoto::where('sectorial_id', $sectorial->id)
            ->where('id', $photoId)
            ->firstOrFail();

        $ruta  = (string) $photo->file_path;
        $disco = collect(['s3', 'public'])->first(
            fn (string $d) => $ruta !== '' && Storage::disk($d)->exists($ruta)
        );

        if ($disco === null) {
            // Lo subido antes de este cambio se fue con el contenedor.
            return response()->json(['message' => 'La foto ya no está disponible.'], 404);
        }

        // Lista blanca, no `image/*`: `mime_type` se guardó al subir y no se
        // vuelve a verificar. Lo que no esté aquí se descarga, nunca en línea.
        $tipo    = (string) ($photo->mime_type ?: 'application/octet-stream');
        $enLinea = in_array($tipo, self::VISUALIZABLES, true);
        $nombre  = addslashes((string) $photo->file_name);

        return Storage::disk($disco)->download($ruta, $photo->file_name, [
            'Content-Type'           => $enLinea ? $tipo : 'application/octet-stream',
            'Content-Disposition'    => ($enLinea ? 'inline' : 'attachment') . "; filename=\"{$nombre}\"",
            'Cache-Control'          => 'private, max-age=0, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function findScopedSectorial(Request $request, $sectorialId): Sectorial
    {
        $query = Sectorial::where('id', $sectorialId);
        if ($tenantId = $request->user()?->tenant_id) {
            $query->where('tenant_id', $tenantId);
        }
        return $query->firstOrFail();
    }
}
