<?php

namespace App\Http\Controllers;

use App\Jobs\SyncCategoryJob;
use App\Jobs\SyncProductJob;
use App\Models\SallaStore;
use App\Models\StorePair;
use App\Services\Salla\TokenManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * نقطة استقبال كل الـ Webhooks الواردة من سلة.
 */
class WebhookController extends Controller
{
    public function __construct(protected TokenManager $tokens) {}

    public function handle(Request $request): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Webhook: توقيع غير صالح', [
                'ip'    => $request->ip(),
                'event' => $request->input('event'),
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $event      = $request->input('event');
        $merchantId = (int) $request->input('merchant');
        $data       = $request->input('data', []);
        $data['_merchant'] = $merchantId;

        Log::debug('Webhook استُقبل', ['event' => $event, 'merchant' => $merchantId]);

        match (true) {
            $event === 'app.store.authorize' => $this->onAuthorize($data, $merchantId),
            in_array($event, ['product.created', 'product.updated']) => $this->onProductChange($merchantId, $data),
            $event === 'category.created' => $this->onCategoryChange($merchantId, $data, 'created'),
            $event === 'category.updated' => $this->onCategoryChange($merchantId, $data, 'updated'),
            $event === 'app.store.uninstall' => $this->onUninstall($merchantId),
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
        StorePair::where('source_store_id', $merchantId)
            ->orWhere('target_store_id', $merchantId)
            ->update(['active' => false]);
        SallaStore::where('store_id', $merchantId)->update(['role' => 'unassigned']);
        Log::info('تطبيق مُلغى التثبيت', ['store_id' => $merchantId]);
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
            'has_header' => $signature !== null,
            'sig_prefix' => $signature ? substr($signature, 0, 16) . '...' : null,
            'exp_prefix' => substr($expected, 0, 16) . '...',
            'match'      => $signature === $expected,
        ]);

        if (!is_string($signature)) {
            Log::warning('Webhook: X-Salla-Signature header missing');
            return false;
        }

        return hash_equals($expected, $signature);
    }
}
