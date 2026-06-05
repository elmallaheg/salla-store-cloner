<?php

namespace App\Jobs;

use App\Models\SallaStore;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use App\Services\Sync\ProductSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job للمزامنة الحيّة للمنتجات عبر Queue.
 *
 * يُطلَق من WebhookController عند استقبال أحداث:
 *  - product.created
 *  - product.updated
 *
 * لماذا Queue وليس معالجة مباشرة؟
 *  1. سلة تتوقع ردًّا سريعًا (< 5 ثواني) على الـ webhook.
 *  2. مزامنة منتج (صور + كمية + API calls) قد تأخذ ثوانٍ متعددة.
 *  3. Queue تُعيد المحاولة تلقائيًا عند الفشل (backoff + maxRetries).
 */
class SyncProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * عدد مرات إعادة المحاولة قبل تسجيل الفشل النهائي.
     * مناسب لـ Rate Limit وأعطال مؤقتة في سلة.
     */
    public int $tries = 5;

    /**
     * وقت الانتظار (بالثواني) بين المحاولات (exponential backoff).
     * 10s → 20s → 40s → 80s → 160s
     */
    public function backoff(): array
    {
        return [10, 20, 40, 80, 160];
    }

    /**
     * مدة timeout القصوى للـ job (بالثواني).
     */
    public int $timeout = 120;

    public function __construct(
        /** معرّف المتجر المصدر (store_id في سلة، ليس id الداخلي) */
        public readonly int   $sourceStoreId,
        /** معرّف المتجر الهدف */
        public readonly int   $targetStoreId,
        /** بيانات المنتج الكاملة من الـ webhook payload */
        public readonly array $productData,
    ) {}

    public function handle(TokenManager $tokenManager): void
    {
        // 1) التحقق من وجود المتجرين
        $sourceStore = SallaStore::where('store_id', $this->sourceStoreId)->first();
        $targetStore = SallaStore::where('store_id', $this->targetStoreId)->first();

        if (!$sourceStore || !$targetStore) {
            Log::warning('SyncProductJob: متجر غير موجود', [
                'source' => $this->sourceStoreId,
                'target' => $this->targetStoreId,
            ]);
            return; // لا نُعيد المحاولة — البيانات خاطئة
        }

        // 2) بناء الـ SallaClient لكل متجر
        $source = new SallaClient($sourceStore, $tokenManager);
        $target = new SallaClient($targetStore, $tokenManager);

        // 3) تنفيذ المزامنة
        (new ProductSyncService($source, $target, $this->sourceStoreId, $this->targetStoreId))
            ->syncOne($this->productData);

        Log::info('SyncProductJob: اكتملت مزامنة المنتج', [
            'product_id' => $this->productData['id'] ?? null,
            'source'     => $this->sourceStoreId,
            'target'     => $this->targetStoreId,
        ]);
    }

    /** يُسجّل الفشل النهائي بعد استنفاد كل المحاولات */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncProductJob: فشل نهائي بعد كل المحاولات', [
            'product_id' => $this->productData['id'] ?? null,
            'source'     => $this->sourceStoreId,
            'target'     => $this->targetStoreId,
            'error'      => $exception->getMessage(),
        ]);
    }
}
