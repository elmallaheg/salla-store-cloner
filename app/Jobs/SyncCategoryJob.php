<?php

namespace App\Jobs;

use App\Models\SallaStore;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use App\Services\Sync\CategorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job للمزامنة الحيّة للتصنيفات عبر Queue.
 *
 * يُطلَق من WebhookController عند أحداث:
 *  - category.created
 *  - category.updated
 */
class SyncCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 20, 40, 80, 160];
    }

    public int $timeout = 60;

    public function __construct(
        public readonly int    $sourceStoreId,
        public readonly int    $targetStoreId,
        public readonly array  $categoryData,
        /** نوع العملية: created | updated */
        public readonly string $action = 'created',
    ) {}

    public function handle(TokenManager $tokenManager): void
    {
        $sourceStore = SallaStore::where('store_id', $this->sourceStoreId)->first();
        $targetStore = SallaStore::where('store_id', $this->targetStoreId)->first();

        if (!$sourceStore || !$targetStore) {
            Log::warning('SyncCategoryJob: متجر غير موجود', [
                'source' => $this->sourceStoreId,
                'target' => $this->targetStoreId,
            ]);
            return;
        }

        $source  = new SallaClient($sourceStore, $tokenManager);
        $target  = new SallaClient($targetStore, $tokenManager);
        $service = new CategorySyncService($source, $target, $this->sourceStoreId, $this->targetStoreId);

        if ($this->action === 'updated') {
            $service->updateOne($this->categoryData);
        } else {
            $service->syncOne($this->categoryData);
        }

        Log::info('SyncCategoryJob: اكتملت مزامنة التصنيف', [
            'category_id' => $this->categoryData['id'] ?? null,
            'action'      => $this->action,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncCategoryJob: فشل نهائي', [
            'category_id' => $this->categoryData['id'] ?? null,
            'error'       => $exception->getMessage(),
        ]);
    }
}
