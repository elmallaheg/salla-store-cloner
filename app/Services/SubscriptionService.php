<?php

namespace App\Services;

use App\Models\StorePair;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/**
 * يتحقق من حدود الاشتراك قبل مزامنة المنتجات.
 */
class SubscriptionService
{
    public function assertCanSync(StorePair $pair, int $newCount = 1, int $current = 0): void
    {
        $subscription = $this->getOrCreateSubscription($pair);

        if (!$subscription->isActive()) {
            throw new \RuntimeException(
                "انتهى اشتراك المتجر. يرجى تجديد الاشتراك لمتابعة المزامنة."
            );
        }

        $total = $current + $newCount;
        if ($total > $subscription->max_products) {
            $plans = $this->upgradeOptions($subscription->plan_slug);
            throw new \RuntimeException(
                "وصلت للحد الأقصى للباقة ({$subscription->max_products} منتج). " .
                "الباقة الحالية: {$subscription->plan_slug}. " .
                "خيارات الترقية: {$plans}"
            );
        }
    }

    public function getOrCreateSubscription(StorePair $pair): Subscription
    {
        $sub = Subscription::where('store_pair_id', $pair->id)->latest()->first();

        if (!$sub) {
            Log::info('بدء تجربة مجانية للزوج', ['pair_id' => $pair->id]);
            $sub = Subscription::startTrial($pair, 'starter');
        }

        return $sub;
    }

    public function activatePlan(StorePair $pair, string $planSlug): Subscription
    {
        $sub = $this->getOrCreateSubscription($pair);
        $sub->activate($planSlug);

        Log::info('تفعيل باقة مدفوعة', [
            'pair_id' => $pair->id,
            'plan'    => $planSlug,
        ]);

        return $sub->fresh();
    }

    public function cancelAll(int $storeId): void
    {
        $pairIds = StorePair::where('source_store_id', $storeId)
            ->orWhere('target_store_id', $storeId)
            ->pluck('id');

        Subscription::whereIn('store_pair_id', $pairIds)
            ->update(['status' => Subscription::STATUS_CANCELLED]);
    }

    protected function upgradeOptions(string $currentSlug): string
    {
        $plans   = Subscription::plans();
        $slugs   = array_keys($plans);
        $current = array_search($currentSlug, $slugs);

        $options = [];
        foreach (array_slice($slugs, $current + 1) as $slug) {
            $p = $plans[$slug];
            $options[] = "{$p['label']} ({$p['max_products']} منتج / {$p['price_sar']} ر.س)";
        }

        return empty($options) ? 'لا توجد باقات أعلى' : implode(' | ', $options);
    }
}
