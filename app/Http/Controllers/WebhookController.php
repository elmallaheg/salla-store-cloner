<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCategoryJob;
use App\Jobs\SyncProductJob;
use App\Models\SallaStore;
use App\Models\StorePair;
use App\Services\Salla\TokenManager;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * نقطة استقبال كل الـ Webhooks الواردة من سلة.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected TokenManager        $tokens,
        protected SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');

        if ($event !== 'app.store.authorize' && !$this->verifySignature($request)) {
            Log::error('Webhook: توقيع غير صالح', ['ip' => $request->ip(), 'event' => $event]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $merchantId = (int) $request->input('merchant');
        $data       = $request->input('data', []);
        $data['merchant'] = $merchantId;

        Log::debug('Webhook استُقبل', ['event' => $event, 'merchant' => $merchantId]);

        match (true) {
            $event === 'app.store.authorize'
                => $this->onAuthorize($data, $merchantId),

            in_array($event, ['product.created', 'product.updated'])
                => $this->onProductChange($merchantId, $data),

            $event === 'category.created'
                => $this->onCategoryChange($merchantId, $data, 'created'),

            $event === 'category.updated'
                => $this->onCategoryChange($merchantId, $data, 'updated'),

            $event === 'app.store.uninstall'
                => $this->onUninstall($merchantId),

            str_starts_with($event, 'app.subscription.')
                => $this->onSubscriptionEvent($event, $merchantId, $data),

            default => Log::debug('Webhook: حدث غير مُعالَج', ['event' => $event]),
        };

        return response()->json(['ok' => true]);
    }

    protected function onAuthorize(array $data, int $merchantId): void
    {
        try {
            $store = $this->tokens->storeFromAuthorizeEvent($data);
            Log::info('تطبيق مُثبَّت/مُحدَّث', ['store_id' => $merchantId, 'expires_at' => $store->token_expires_at]);
        } catch (\Throwable $e) {
            Log::error('فشل حفظ توكن المتجر', ['merchant' => $merchantId, 'error' => $e->getMessage()]);
        }
    }

    protected function onProductChange(int $sourceStoreId, array $productData): void
    {
        $pairs = StorePair::activePairsForSource($sourceStoreId);
        if ($pairs->isEmpty()) return;

        foreach ($pairs as $pair) {
            if (!$pair->sync_products) continue;
            SyncProductJob::dispatch($sourceStoreId, $pair->target_store_id, $productData);
            Log::debug('SyncProductJob مُوضوع في Queue', [
                'source' => $sourceStoreId, 'target' => $pair->target_store_id,
                'product_id' => $productData['id'] ?? null,
            ]);
        }
    }

    protected function onCategoryChange(int $sourceStoreId, array $catData, string $action): void
    {
        $pairs = StorePair::activePairsForSource($sourceStoreId);
        foreach ($pairs as $pair) {
            if (!$pair->sync_categories) continue;
            SyncCategoryJob::dispatch($sourceStoreId, $pair->target_store_id, $catData, $action);
        }
    }

    protected function onUninstall(int $merchantId): void
    {
        $this->subscriptions->cancelAll($merchantId);

        StorePair::where('source_store_id', $merchantId)
            ->orWhere('target_store_id', $merchantId)
            ->update(['active' => false]);

        SallaStore::where('store_id', $merchantId)->update(['role' => 'unassigned']);
        Log::info('تطبيق مُلغى التثبيت', ['store_id' => $merchantId]);
    }

    protected function onSubscriptionEvent(string $event, int $merchantId, array $data): void
    {
        Log::info('Webhook: حدث اشتراك', ['event' => $event, 'merchant' => $merchantId]);

        try {
            match ($event) {
                'app.subscription.charge.created'
                    => $this->handleSuccessfulCharge($merchantId, $data),
                'app.subscription.charge.failed'
                    => Log::warning('Webhook: فشلت دفعة الاشتراك', ['merchant' => $merchantId, 'data' => $data]),
                'app.subscription.cancelled'
                    => $this->subscriptions->cancelAll($merchantId),
                default => null,
            };
        } catch (\Throwable $e) {
            Log::error('Webhook: خطأ في معالجة حدث الاشتراك', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    protected function handleSuccessfulCharge(int $merchantId, array $data): void
    {
        $planSlug = $data['plan_id'] ?? $data['subscription']['plan_id'] ?? $data['plan'] ?? null;

        if (!$planSlug) {
            Log::warning('Webhook: لم يُعثر على plan_id في بيانات الدفع', ['data' => $data]);
            return;
        }

        $pairs = StorePair::where('source_store_id', $merchantId)
            ->orWhere('target_store_id', $merchantId)->get();

        foreach ($pairs as $pair) {
            $this->subscriptions->activatePlan($pair, $planSlug);
            Log::info('تم تفعيل الباقة المدفوعة', ['pair_id' => $pair->id, 'plan' => $planSlug, 'merchant' => $merchantId]);
        }
    }

    protected function verifySignature(Request $request): bool
    {
        $secret = config('salla.oauth.webhook_secret');

        if (!$secret) {
            if (app()->isProduction()) {
                Log::error('SALLA_WEBHOOK_SECRET غير مضبوط في بيئة الإنتاج!');
                return false;
            }
            return true;
        }

        $signature = $request->header('X-Salla-Signature');
        $expected  = hash_hmac('sha256', $request->getContent(), $secret);

        Log::debug('Webhook signature check', [
            'has_signature_header' => $signature !== null,
            'match'                => $signature === $expected,
        ]);

        if (!is_string($signature)) {
            Log::warning('Webhook: X-Salla-Signature header missing');
            return false;
        }

        return hash_equals($expected, $signature);
    }
}
