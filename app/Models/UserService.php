<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserService extends Model
{
    protected $table = 'user_services';

    /** Billable service: the monthly job invoices these. */
    public const STATUS_ACTIVE = 'active';

    /** Courtesy plan: kept on record but never auto-invoiced. */
    public const STATUS_GRATIS = 'gratis';

    protected $fillable = [
        'user_id',
        'service_plan_id',
        'start_date',
        'end_date',
        'status',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function servicePlan()
    {
        return $this->belongsTo(Plan::class, 'service_plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Status a service should carry for the given plan: courtesy plans are
     * 'gratis' (excluded from billing), everything else is 'active'.
     */
    public static function statusForPlan(?Plan $plan): string
    {
        return $plan && $plan->is_courtesy ? self::STATUS_GRATIS : self::STATUS_ACTIVE;
    }

    /**
     * Ensure a customer has exactly one current service row pointing at the
     * given plan, with the status that plan implies. Used by the customer
     * create/update flows so manually-managed customers are billable too
     * (historically user_services was only written by the Excel import).
     */
    public static function syncForCustomer(int $userId, ?int $servicePlanId): void
    {
        if (!$servicePlanId) {
            return;
        }

        $plan = Plan::find($servicePlanId);
        $status = self::statusForPlan($plan);

        $current = self::where('user_id', $userId)
            ->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_GRATIS])
            ->first();

        if ($current) {
            $current->update([
                'service_plan_id' => $servicePlanId,
                'status' => $status,
            ]);
            return;
        }

        self::create([
            'user_id' => $userId,
            'service_plan_id' => $servicePlanId,
            'status' => $status,
            'start_date' => now(),
        ]);
    }
}
