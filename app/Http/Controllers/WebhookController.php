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

class WebhookController extends Controller
{
    public function __construct(protected TokenManager $tokens) {}

    public function handle(Request $request): JsonResponse
    {
        $event = $request->input('event');

        // app.store.authorize: حدث التثبيت — التوكن نفسه دليل المصدر، لا يحتاج توقيعًا
        // جميع الأحداث الأخرى تتطلب توقيع HMAC-SHA256 صالحًا
        if ($event !== 'app.store.authorize' && !$this->verifySignature($request)) {
            Log::error('Webhook: توقيع غير صالح', [
                'ip'    => $request->ip(),
                'event' => $event,
            ]);
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $merchantId = (int) $request->input('merchant');
        $data       = $request->input('data', []);
        $data['_merchant'] = $merchantId;

        Log::info('Webhook received', ['event' => $event, 'merchant' => $merchantId]);

        match (true) {
            $event === 'app.store.authorize' => $this->onAuthorize($data, $merchantId),
            in_array($event, ['product.created', 'product.updated']) => $this->onProductChange($merchantId, $data),
            $event === 'category.created' => $this->onCategoryChange($merchantId, $data, 'created'),
            $event === 'category.updated' => $this->onCategoryChange($merchantId, $data, 'updated'),
            $event === 'app.store.uninstall' => $this->onUninstall($merchantId),
            default => Log::info('Webhook: unhandled event', ['event' => $event]),
        };

        return response()->json(['ok' => true]);
    }

    protected function onAuthorize(array $data, int $merchantId): void
    {
        try {
            $store = $this->tokens->storeFromAuthorizeEvent($data);
            Log::info('App installed/updated', [
                'store_id'   => $merchantId,
                'expires_at' => $store->token_expires_at,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to save store token', ['merchant' => $merchantId, 'error' => $e->getMessage()]);
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
        Log::info('App uninstalled', ['store_id' => $merchantId]);
    }

    protected function verifySignature(Request $request): bool
    {
        $secret = config('salla.oauth.webhook_secret');

        if (!$secret) {
            if (app()->isProduction()) {
                Log::error('SALLA_WEBHOOK_SECRET not set in production!');
                return false;
            }
            return true;
        }

        $signature = $request->header('X-Salla-Signature');
        if (!is_string($signature)) {
            Log::error('Webhook: X-Salla-Signature header missing');
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        return hash_equals($expected, $signature);
    }
}
