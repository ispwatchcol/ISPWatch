<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Auth;
use App\Mail\SendTicketNotification;

class SupportTicketController extends Controller
{
    /**
     * Display a listing of support tickets.
     */
    public function index(Request $request)
    {
        $query = SupportTicket::with(['user', 'staff', 'messages', 'attachments']);

        // Filtros
        if ($request->has('status') && $request->status != 'all') {
            $query->where('status', $request->status);
        }

        if ($request->has('priority') && $request->priority != 'all') {
            $query->where('priority', $request->priority);
        }

        if ($request->has('category') && $request->category != 'all') {
            $query->where('category', $request->category);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Búsqueda
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $tickets = $query->orderBy('created_at', 'desc')->get();

        return response()->json($tickets);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'nullable|in:technical,billing,services,general',
            'user_id' => 'required|exists:users,id', // Customer selection required
            'attachments.*' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

        DB::beginTransaction();

        try {
            // Crear el ticket
            $ticket = SupportTicket::create([
                'user_id' => $data['user_id'], // Required from request
                'staff_id' => null, // Not assigned on creation
                'tenant_id' => $request->tenant ?? 1,
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? 'general', // Default to 'general' instead of null
                'priority' => SupportTicket::PRIORITY_MEDIUM, // Default: medium
                'status' => SupportTicket::STATUS_OPEN, // Pending
            ]);

            // Subir archivos adjuntos si existen
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = $file->storeAs(
                        "support_attachments/{$ticket->id}",
                        $fileName,
                        'public'
                    );

                    SupportTicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $data['user_id'] ?? 1,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            // Recargar relaciones
            $ticket->load(['user', 'staff', 'attachments']);

            // Enviar email de notificación (opcional, no debe fallar la creación)
            /*
            try {
                Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'created'));
            } catch (\Exception $e) {
                \Log::error('Error sending ticket notification email: ' . $e->getMessage());
            }
            */

            return response()->json([
                'message' => 'Ticket creado correctamente. ✅',
                'ticket' => $ticket
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear el ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified ticket.
     */
    public function show($id)
    {
        $ticket = SupportTicket::with([
            'user',
            'staff',
            'messages.user',
            'attachments.user'
        ])->findOrFail($id);

        return response()->json($ticket);
    }

    /**
     * Update the specified ticket.
     */
    public function update(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'subject' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'category' => 'sometimes|in:technical,billing,services,general',
            'priority' => 'sometimes|in:low,medium,high,urgent',
            'status' => 'sometimes|in:open,in_progress,resolved,closed',
            'staff_id' => 'sometimes|nullable|exists:users,id',
            'attachments.*' => 'nullable|file|max:10240|mimes:jpg,jpeg,png,pdf,doc,docx,txt',
        ]);

        DB::beginTransaction();

        try {
            $oldStatus = $ticket->status;

            $ticket->update($data);

            // Si cambia a resuelto, actualizar resolved_at
            if (isset($data['status']) && $data['status'] === SupportTicket::STATUS_RESOLVED && $oldStatus !== SupportTicket::STATUS_RESOLVED) {
                $ticket->resolved_at = now();
                $ticket->save();
            }

            // Subir archivos adjuntos si existen en update
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $fileName = time() . '_' . $file->getClientOriginalName();
                    $filePath = $file->storeAs(
                        "support_attachments/{$ticket->id}",
                        $fileName,
                        'public'
                    );

                    SupportTicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => Auth::id() ?? 1,
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $filePath,
                        'file_size' => $file->getSize(),
                        'mime_type' => $file->getMimeType(),
                    ]);
                }
            }

            DB::commit();

            $ticket->load(['user', 'staff', 'messages', 'attachments']);

            // Enviar email si cambió el estado
            if (isset($data['status']) && $oldStatus !== $data['status']) {
                try {
                    Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'updated'));
                } catch (\Exception $e) {
                    \Log::error('Error sending ticket notification email: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Ticket actualizado correctamente. ✅',
                'ticket' => $ticket
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified ticket.
     */
    public function destroy($id)
    {
        $ticket = SupportTicket::findOrFail($id);

        DB::beginTransaction();

        try {
            // Eliminar archivos del storage
            foreach ($ticket->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            $ticket->delete();

            DB::commit();

            return response()->json([
                'message' => 'Ticket eliminado correctamente. ✅'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar el ticket.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get support statistics.
     */
    public function statistics()
    {
        $totalTickets = SupportTicket::count();
        $openTickets = SupportTicket::where('status', SupportTicket::STATUS_OPEN)->count();
        $inProgressTickets = SupportTicket::where('status', SupportTicket::STATUS_IN_PROGRESS)->count();

        // Tickets resueltos este mes
        $startOfMonth = now()->startOfMonth();
        $resolvedThisMonth = SupportTicket::where('status', SupportTicket::STATUS_RESOLVED)
            ->where('resolved_at', '>=', $startOfMonth)
            ->count();

        // Tiempo promedio de resolución (en días)
        $resolvedTickets = SupportTicket::whereNotNull('resolved_at')->get();
        $avgResolutionTime = 0;
        if ($resolvedTickets->count() > 0) {
            $totalDays = 0;
            foreach ($resolvedTickets as $ticket) {
                $totalDays += $ticket->created_at->diffInDays($ticket->resolved_at);
            }
            $avgResolutionTime = round($totalDays / $resolvedTickets->count(), 1);
        }

        // Distribución por prioridad (only if priority exists)
        $byPriority = SupportTicket::select('priority', DB::raw('count(*) as count'))
            ->whereNotNull('priority')
            ->groupBy('priority')
            ->get()
            ->map(function ($item) {
                return [
                    'priority' => ucfirst($item->priority),
                    'count' => $item->count
                ];
            });

        // Distribución por estado
        $byStatus = SupportTicket::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => ucfirst(str_replace('_', ' ', $item->status)),
                    'count' => $item->count
                ];
            });

        // Distribución por categoría (only if category exists)
        $byCategory = SupportTicket::select('category', DB::raw('count(*) as count'))
            ->whereNotNull('category')
            ->groupBy('category')
            ->get()
            ->map(function ($item) {
                return [
                    'category' => ucfirst($item->category),
                    'count' => $item->count
                ];
            });

        // Tendencia mensual (últimos 6 meses)
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthStart = now()->subMonths($i)->startOfMonth();
            $monthEnd = now()->subMonths($i)->endOfMonth();

            $count = SupportTicket::whereBetween('created_at', [$monthStart, $monthEnd])->count();

            $monthlyTrend[] = [
                'month' => $monthStart->locale('es')->format('M'),
                'count' => $count
            ];
        }

        // Tickets recientes
        $recentTickets = SupportTicket::with(['user', 'staff'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'total_tickets' => $totalTickets,
            'open_tickets' => $openTickets,
            'in_progress_tickets' => $inProgressTickets,
            'resolved_this_month' => $resolvedThisMonth,
            'avg_resolution_time' => $avgResolutionTime,
            'by_priority' => $byPriority,
            'by_status' => $byStatus,
            'by_category' => $byCategory,
            'monthly_trend' => $monthlyTrend,
            'recent_tickets' => $recentTickets
        ]);
    }

    /**
     * Add a message to a ticket.
     */
    public function addMessage(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'message' => 'required|string',
            'is_internal' => 'boolean',
            'user_id' => 'required|exists:users,id', // Hacer user_id requerido en el request
        ]);

        DB::beginTransaction();

        try {
            $message = SupportTicketMessage::create([
                'ticket_id' => $ticket->id,
                'user_id' => $data['user_id'], // Usar el user_id del request
                'message' => $data['message'],
                'is_internal' => $data['is_internal'] ?? false,
            ]);

            DB::commit();

            $message->load('user');

            // Enviar email si no es nota interna
            if (!$message->is_internal) {
                try {
                    $ticket->load('user', 'staff');
                    Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'message'));
                    if ($ticket->staff) {
                        Mail::to($ticket->staff->email)->send(new SendTicketNotification($ticket, 'message'));
                    }
                } catch (\Exception $e) {
                    \Log::error('Error sending message notification email: ' . $e->getMessage());
                }
            }

            return response()->json([
                'message' => 'Mensaje agregado correctamente. ✅',
                'ticket_message' => $message
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al agregar el mensaje.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update ticket status.
     */
    public function updateStatus(Request $request, $id)
    {
        $ticket = SupportTicket::findOrFail($id);

        $data = $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        DB::beginTransaction();

        try {
            $oldStatus = $ticket->status;
            $ticket->status = $data['status'];

            // Si cambia a resuelto, actualizar resolved_at
            if ($data['status'] === SupportTicket::STATUS_RESOLVED && $oldStatus !== SupportTicket::STATUS_RESOLVED) {
                $ticket->resolved_at = now();
            }

            $ticket->save();

            DB::commit();

            $ticket->load(['user', 'staff']);

            // Enviar email de notificación
            try {
                Mail::to($ticket->user->email)->send(new SendTicketNotification($ticket, 'updated'));
            } catch (\Exception $e) {
                \Log::error('Error sending status update notification email: ' . $e->getMessage());
            }

            return response()->json([
                'message' => 'Estado actualizado correctamente. ✅',
                'ticket' => $ticket
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar el estado.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
