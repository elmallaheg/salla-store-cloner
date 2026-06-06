<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * اشتراك زوج متاجر في إحدى الباقات.
 *
 * @property int    $store_pair_id
 * @property string $plan_slug
 * @property int    $max_products
 * @property int    $price_sar
 * @property string $status          trial|active|expired|cancelled
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $current_period_ends_at
 */
class Subscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'trial_ends_at'          => 'datetime',
        'current_period_ends_at' => 'datetime',
    ];

    // ======== الباقات المتاحة ========

    public static function plans(): array
    {
        return [
            'starter'      => ['max_products' => 50,   'price_sar' => 159,  'label' => 'المبتدئ'],
            'basic'        => ['max_products' => 100,  'price_sar' => 249,  'label' => 'الأساسي'],
            'professional' => ['max_products' => 250,  'price_sar' => 499,  'label' => 'الاحترافي'],
            'business'     => ['max_products' => 700,  'price_sar' => 999,  'label' => 'الأعمال'],
            'enterprise'   => ['max_products' => 9999, 'price_sar' => 1699, 'label' => 'المؤسسي (1000+)'],
        ];
    }

    public static function planData(string $slug): ?array
    {
        return static::plans()[$slug] ?? null;
    }

    // ======== Status Constants ========

    const STATUS_TRIAL     = 'trial';
    const STATUS_ACTIVE    = 'active';
    const STATUS_EXPIRED   = 'expired';
    const STATUS_CANCELLED = 'cancelled';

    // ======== Relationships ========

    public function storePair(): BelongsTo
    {
        return $this->belongsTo(StorePair::class);
    }

    // ======== Helpers ========

    public function isActive(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return !$this->current_period_ends_at || $this->current_period_ends_at->isFuture();
        }
        if ($this->status === self::STATUS_TRIAL) {
            return !$this->trial_ends_at || $this->trial_ends_at->isFuture();
        }
        return false;
    }

    public function isAtLimit(int $currentProductCount): bool
    {
        return $currentProductCount >= $this->max_products;
    }

    public static function startTrial(StorePair $pair, string $planSlug = 'starter'): static
    {
        $plan = static::planData($planSlug);
        if (!$plan) {
            throw new \InvalidArgumentException("باقة غير معروفة: {$planSlug}");
        }
        return static::updateOrCreate(
            ['store_pair_id' => $pair->id],
            [
                'plan_slug'     => $planSlug,
                'max_products'  => $plan['max_products'],
                'price_sar'     => $plan['price_sar'],
                'status'        => self::STATUS_TRIAL,
                'trial_ends_at' => now()->addDays(7),
            ]
        );
    }

    public function activate(string $planSlug): void
    {
        $plan = static::planData($planSlug);
        if (!$plan) {
            throw new \InvalidArgumentException("باقة غير معروفة: {$planSlug}");
        }
        $this->update([
            'plan_slug'              => $planSlug,
            'max_products'           => $plan['max_products'],
            'price_sar'              => $plan['price_sar'],
            'status'                 => self::STATUS_ACTIVE,
            'current_period_ends_at' => now()->addMonth(),
        ]);
    }
}
