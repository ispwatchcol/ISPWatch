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

            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se pudo determinar el tenant del usuario autenticado.',
                ], 403);
            }

            // Get current month dates
            $startOfMonth = Carbon::now()->startOfMonth();
            $endOfMonth = Carbon::now()->endOfMonth();

            // Customer counts: users with a "Cliente" role for this tenant (tenant-specific or global).
            $customerRoleIds = \App\Models\Role::idsByName('Cliente', $tenantId);

            $customersQuery = CustomerProfile::query()
                ->join('users', 'customer_profile.user_id', '=', 'users.id')
                ->whereIn('users.role_id', $customerRoleIds)
                ->where('users.status', true)
                ->where('users.tenant_id', $tenantId);

            $totalCustomers  = (clone $customersQuery)->count();
            $activeCustomers = (clone $customersQuery)->where('customer_profile.status', true)->count();

            // SECURITY FIX (OWASP A04): All queries scoped to tenant
            $totalSectorials = Sectorial::where('tenant_id', $tenantId)->count();
            $totalRouters = Router::where('tenant_id', $tenantId)->count();

            // Support tickets scoped to tenant
            $openTickets = SupportTicket::where('tenant_id', $tenantId)
                ->whereIn('status', ['open', 'in_progress'])->count();
            $urgentTickets = SupportTicket::where('tenant_id', $tenantId)
                ->where('priority', 'urgent')
                ->whereIn('status', ['open', 'in_progress'])
                ->count();

            // Monthly revenue from invoices scoped to tenant
            $monthlyRevenue = Invoice::where('tenant_id', $tenantId)
                ->where('status', 'paid')
                ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                ->sum('total') ?? 0;

            // Monthly payments received scoped to tenant
            $monthlyPayments = Payment::where('tenant_id', $tenantId)
                ->where('status', 'completed')
                ->whereBetween('payment_date', [$startOfMonth, $endOfMonth])
                ->sum('amount') ?? 0;

            // Pending invoices balance: 'issued' = unpaid awaiting payment, 'overdue' = past due
            $pendingBalance = Invoice::where('tenant_id', $tenantId)
                ->whereIn('status', ['issued', 'overdue'])
                ->sum('balance_due') ?? 0;

            // Collection rate: paid payments vs total invoiced this month
            $totalInvoicedThisMonth = Invoice::where('tenant_id', $tenantId)
                ->whereNotIn('status', ['void', 'cancelled'])
                ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
                ->sum('total') ?? 0;
            $collectionRate = $totalInvoicedThisMonth > 0
                ? round(($monthlyPayments / $totalInvoicedThisMonth) * 100, 1)
                : 0;

            // Recent activities - combine multiple sources
            $activities = $this->getRecentActivities($tenantId);

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
    private function getRecentActivities(int $tenantId)
    {
        $activities = collect();

        // Recent customers scoped to tenant (customer_profile has no timestamps; use users.created_at)
        $recentCustomers = CustomerProfile::with('user')
            ->join('users', 'customer_profile.user_id', '=', 'users.id')
            ->where('users.tenant_id', $tenantId)
            ->select('customer_profile.*', 'users.created_at as user_created_at')
            ->orderBy('users.created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($customer) {
                $createdAt = $customer->user_created_at ? \Carbon\Carbon::parse($customer->user_created_at) : now();
                return [
                    'action' => 'Cliente ' . ($customer->name ?? 'Nuevo') . ' ' . ($customer->last_name ?? '') . ' registrado',
                    'user' => 'Sistema',
                    'time' => $createdAt->diffForHumans(),
                    'type' => 'success',
                    'created_at' => $createdAt,
                ];
            });
        $activities = $activities->merge($recentCustomers);

        // Recent tickets scoped to tenant
        $recentTickets = SupportTicket::with('user')
            ->where('tenant_id', $tenantId)
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

        // Recent payments scoped to tenant
        $recentPayments = Payment::with('customer')
            ->where('tenant_id', $tenantId)
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
