<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Models\SectorialHistory;
use App\Models\SectorialPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SectorialPhotoController extends Controller
{
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
            $filePath = $file->storeAs(
                "sectorial_photos/{$sectorial->id}",
                $fileName,
                'public'
            );

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
            Storage::disk('public')->delete($photo->file_path);
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

    private function findScopedSectorial(Request $request, $sectorialId): Sectorial
    {
        $query = Sectorial::where('id', $sectorialId);
        if ($tenantId = $request->user()?->tenant_id) {
            $query->where('tenant_id', $tenantId);
        }
        return $query->firstOrFail();
    }
}
