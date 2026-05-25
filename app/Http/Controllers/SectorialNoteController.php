<?php

namespace App\Http\Controllers;

use App\Models\Sectorial;
use App\Models\SectorialHistory;
use App\Models\SectorialNote;
use Illuminate\Http\Request;

class SectorialNoteController extends Controller
{
    public function index(Request $request, $sectorialId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);

        $notes = $sectorial->notes()
            ->with('user:id,user_name,user_lastname')
            ->get();

        return response()->json($notes);
    }

    public function store(Request $request, $sectorialId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);

        $data = $request->validate([
            'title'   => 'nullable|string|max:255',
            'content' => 'required|string',
        ]);

        $note = SectorialNote::create([
            'sectorial_id' => $sectorial->id,
            'user_id'      => $request->user()?->id,
            'tenant_id'    => $sectorial->tenant_id,
            'title'        => $data['title'] ?? null,
            'content'      => $data['content'],
        ]);

        SectorialHistory::log(
            $sectorial->id,
            'note_added',
            'Se agregó una nota: ' . ($data['title'] ?? 'Sin título'),
            ['note_id' => $note->id]
        );

        return response()->json([
            'message' => 'Nota creada correctamente.',
            'note'    => $note->load('user:id,user_name,user_lastname'),
        ], 201);
    }

    public function update(Request $request, $sectorialId, $noteId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);
        $note = SectorialNote::where('sectorial_id', $sectorial->id)->findOrFail($noteId);

        $data = $request->validate([
            'title'   => 'nullable|string|max:255',
            'content' => 'required|string',
        ]);

        $note->update($data);

        SectorialHistory::log(
            $sectorial->id,
            'note_updated',
            'Se actualizó una nota',
            ['note_id' => $note->id]
        );

        return response()->json([
            'message' => 'Nota actualizada.',
            'note'    => $note->load('user:id,user_name,user_lastname'),
        ]);
    }

    public function destroy(Request $request, $sectorialId, $noteId)
    {
        $sectorial = $this->findScopedSectorial($request, $sectorialId);
        $note = SectorialNote::where('sectorial_id', $sectorial->id)->findOrFail($noteId);
        $note->delete();

        SectorialHistory::log(
            $sectorial->id,
            'note_removed',
            'Se eliminó una nota',
            ['note_id' => (int) $noteId]
        );

        return response()->json(['message' => 'Nota eliminada.']);
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
