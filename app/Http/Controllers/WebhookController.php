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
 *
 * سلة ترسل POST إلى URL واحد بهذه البنية:
 *  {
 *    "event":    "product.updated",
 *    "merchant": <store_id>,
 *    "data":     { ... تفاصيل الحدث ... }
 *  }
 *
 * الأمان: التحقق من توقيع HMAC-SHA256 قبل أي معالجة.
 * الأداء: المعالجة الثقيلة تُدفع لـ Queue فورًا — الرد يعود خلال ميلي ثواني.
 */
class WebhookController extends Controller
{
    public function __construct(protected TokenManager $tokens) {}

    public function handle(Request $request): JsonResponse
    {
        // ======== 1. التحقق من التوقيع (أمان) ========
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

        // نُضيف merchant إلى data للاستخدام في المعالجة
        $data['_merchant'] = $merchantId;

        Log::debug('Webhook استُقبل', ['event' => $event, 'merchant' => $merchantId]);

        // ======== 2. توجيه الحدث ========
        match (true) {
            // Easy Mode: يصل التوكن عبر هذا الحدث عند التثبيت/التحديث
            $event === 'app.store.authorize' => $this->onAuthorize($data, $merchantId),

            // أحداث المنتجات
            in_array($event, ['product.created', 'product.updated']) => $this->onProductChange($merchantId, $data),

            // أحداث التصنيفات
            $event === 'category.created' => $this->onCategoryChange($merchantId, $data, 'created'),
            $event === 'category.updated' => $this->onCategoryChange($merchantId, $data, 'updated'),

            // أحداث إلغاء التثبيت
            $event === 'app.store.uninstall' => $this->onUninstall($merchantId),

            // أحداث أخرى: نتجاهلها مع تسجيل
            default => Log::debug('Webhook: حدث غير مُعالَج', ['event' => $event]),
        };

        // سلة تتوقع ردًّا 200 سريعًا — المعالجة تمت بالـ Queue
        return response()->json(['ok' => true]);
    }

    // ======================================================
    // معالجات الأحداث
    // ======================================================

    /** يخزّن توكن المتجر فور التثبيت (Easy Mode) */
    protected function onAuthorize(array $data, int $merchantId): void
    {
        try {
            // payload الحدث: { merchant, access_token, refresh_token, expires }
            $store = $this->tokens->storeFromAuthorizeEvent($data);
            Log::info('تطبيق مُثبَّت/مُحدَّث', [
                'store_id'   => $merchantId,
                'expires_at' => $store->token_expires_at,
            ]);
        } catch (\Throwable $e) {
            Log::error('فشل حفظ توكن المتجر', ['merchant' => $merchantId, 'error' => $e->getMessage()]);
        }
    }

    /** يدفع تحديث المنتج للـ Queue لكل الأزواج النشطة */
    protected function onProductChange(int $sourceStoreId, array $productData): void
    {
        $pairs = StorePair::activePairsForSource($sourceStoreId);

        if ($pairs->isEmpty()) {
            return; // هذا المتجر ليس مصدرًا لأي زوج نشط
        }

        foreach ($pairs as $pair) {
            if (!$pair->sync_products) {
                continue;
            }

            SyncProductJob::dispatch(
                $sourceStoreId,
                $pair->target_store_id,
                $productData
            );

            Log::debug('SyncProductJob مُوضوع في Queue', [
                'source' => $sourceStoreId,
                'target' => $pair->target_store_id,
                'product_id' => $productData['id'] ?? null,
            ]);
        }
    }

    /** يدفع تحديث التصنيف للـ Queue لكل الأزواج النشطة */
    protected function onCategoryChange(int $sourceStoreId, array $catData, string $action): void
    {
        $pairs = StorePair::activePairsForSource($sourceStoreId);

        foreach ($pairs as $pair) {
            if (!$pair->sync_categories) {
                continue;
            }

            SyncCategoryJob::dispatch(
                $sourceStoreId,
                $pair->target_store_id,
                $catData,
                $action
            );
        }
    }

    /** يعطّل الزوج عند إلغاء تثبيت التطبيق */
    protected function onUninstall(int $merchantId): void
    {
        // عطّل الأزواج حيث هذا المتجر مصدر أو هدف
        StorePair::where('source_store_id', $merchantId)
            ->orWhere('target_store_id', $merchantId)
            ->update(['active' => false]);

        // اختياري: احذف/عطّل المتجر نفسه
        SallaStore::where('store_id', $merchantId)
            ->update(['role' => 'unassigned']);

        Log::info('تطبيق مُلغى التثبيت', ['store_id' => $merchantId]);
    }

    // ======================================================
    // الأمان
    // ======================================================

    /**
     * يتحقق من توقيع سلة (Signature strategy / HMAC-SHA256).
     *
     * سلة ترسل التوقيع في رأس: X-Salla-Signature
     * يُحسب: HMAC-SHA256 على الـ raw body بسر التطبيق.
     *
     * مرجع: https://docs.salla.dev/ → Webhooks → Signature Verification
     */
    protected function verifySignature(Request $request): bool
    {
        $secret = config('salla.oauth.webhook_secret');

        // في بيئة التطوير بدون سر: نقبل كل الطلبات (⚠️ ممنوع في الإنتاج)
        if (!$secret) {
            if (app()->isProduction()) {
                Log::error('SALLA_WEBHOOK_SECRET غير مضبوط في بيئة الإنتاج!');
                return false;
            }
            return true;
        }

        $signature = $request->header('X-Salla-Signature');
        if (!is_string($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        // hash_equals يمنع timing attacks
        return hash_equals($expected, $signature);
    }
}
