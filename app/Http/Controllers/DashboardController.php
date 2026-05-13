<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\CustomerProfile;
use App\Models\Sectorial;
use App\Models\SupportTicket;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Router;
use App\Models\User;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function stats(Request $request)
    {
        try {
            $tenantId = $request->user()?->tenant_id;

            // Get current month dates
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            // Customer counts: only role=customer (3), active user accounts, scoped to tenant.
            // Mirrors the visibility rules of the Customers UI so the dashboard count
            // matches what the user actually sees in /customers.
            $customersQuery = CustomerProfile::query()
                ->join('users', 'customer_profile.user_id', '=', 'users.id')
                ->where('users.role_id', 3)
                ->where('users.status', true);

            if ($tenantId) {
                $customersQuery->where('users.tenant_id', $tenantId);
            }

            $totalCustomers  = (clone $customersQuery)->count();
            $activeCustomers = (clone $customersQuery)->where('customer_profile.status', true)->count();

            // Sectorial/Antenna counts
            $totalSectorials = Sectorial::count();

            // Router counts
            $totalRouters = Router::count();

            // Support tickets (open/pending)
            $openTickets = SupportTicket::whereIn('status', ['open', 'in_progress'])->count();
            $urgentTickets = SupportTicket::where('priority', 'urgent')
                ->whereIn('status', ['open', 'in_progress'])
                ->count();

            // Monthly revenue from invoices
            $monthlyRevenue = Invoice::where('status', 'paid')
                ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                ->sum('total') ?? 0;

            // Monthly payments received
            $monthlyPayments = Payment::where('status', 'completed')
                ->whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0;

            // Pending invoices balance
            $pendingBalance = Invoice::whereIn('status', ['pending', 'overdue'])
                ->sum('balance_due') ?? 0;

            // Calculate collection rate
            $totalInvoicedThisMonth = Invoice::whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                ->sum('total') ?? 0;
            $collectionRate = $totalInvoicedThisMonth > 0
                ? round(($monthlyPayments / $totalInvoicedThisMonth) * 100, 1)
                : 0;

            // Recent activities - combine multiple sources
            $activities = $this->getRecentActivities();

            return response()->json([
                'success' => true,
                'data' => [
                    'cards' => [
                        'customers' => [
                            'total' => $totalCustomers,
                            'active' => $activeCustomers,
                            'suspended' => $totalCustomers - $activeCustomers,
                        ],
                        'revenue' => [
                            'monthly' => $monthlyPayments,
                            'pending' => $pendingBalance,
                            'collection_rate' => $collectionRate,
                        ],
                        'tickets' => [
                            'open' => $openTickets,
                            'urgent' => $urgentTickets,
                        ],
                        'infrastructure' => [
                            'sectoriales' => $totalSectorials,
                            'routers' => $totalRouters,
                        ],
                    ],
                    'activities' => $activities,
                    'system_status' => [
                        'network' => [
                            'status' => 'operational',
                            'label' => 'Operativa',
                        ],
                        'servers' => [
                            'status' => 'stable',
                            'label' => 'Estables',
                        ],
                        'coverage' => [
                            'sectoriales' => $totalSectorials,
                            'label' => $totalSectorials . ' antenas activas',
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching dashboard stats: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get recent activities from multiple sources
     */
    private function getRecentActivities()
    {
        $activities = collect();

        // Recent customers (last 7 days)
        $recentCustomers = CustomerProfile::with('user')
            ->orderBy('user_id', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($customer) {
                return [
                    'action' => 'Cliente ' . ($customer->name ?? 'Nuevo') . ' ' . ($customer->last_name ?? '') . ' registrado',
                    'user' => 'Sistema',
                    'time' => 'Reciente',
                    'type' => 'success',
                    'created_at' => now(),
                ];
            });
        $activities = $activities->merge($recentCustomers);

        // Recent tickets
        $recentTickets = SupportTicket::with('user')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($ticket) {
                $statusType = match ($ticket->status) {
                    'open' => 'warning',
                    'resolved', 'closed' => 'success',
                    default => 'warning',
                };
                return [
                    'action' => 'Ticket: ' . ($ticket->subject ?? 'Sin asunto'),
                    'user' => optional($ticket->user)->user_name ?? 'Usuario',
                    'time' => $ticket->created_at ? $ticket->created_at->diffForHumans() : 'Reciente',
                    'type' => $statusType,
                    'created_at' => $ticket->created_at ?? now(),
                ];
            });
        $activities = $activities->merge($recentTickets);

        // Recent payments
        $recentPayments = Payment::with('customer')
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($payment) {
                $customerName = optional($payment->customer)->user_name ?? 'Cliente';
                $amount = (float) ($payment->amount ?? 0);
                return [
                    'action' => 'Pago recibido: $' . number_format($amount, 0, ',', '.'),
                    'user' => $customerName,
                    'time' => $payment->created_at ? $payment->created_at->diffForHumans() : 'Reciente',
                    'type' => 'success',
                    'created_at' => $payment->created_at ?? now(),
                ];
            });
        $activities = $activities->merge($recentPayments);

        // Sort by created_at and take top 6
        return $activities
            ->sortByDesc('created_at')
            ->take(6)
            ->values()
            ->map(function ($activity) {
                unset($activity['created_at']);
                return $activity;
            })
            ->toArray();
    }
}
