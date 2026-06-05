<?php

namespace App\Console\Commands;

use App\Models\SallaStore;
use App\Models\StorePair;
use App\Models\SyncJob;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use App\Services\Sync\CategorySyncService;
use App\Services\Sync\ProductSyncService;
use Illuminate\Console\Command;

/**
 * أمر النسخة الأولية الكاملة.
 *
 * الاستخدام:
 *   php artisan salla:migrate {source_store_id} {target_store_id} [--dry-run]
 *
 * الترتيب مهم جدًّا:
 *  1. التصنيفات أولًا (يبني جدول الـ mapping)
 *  2. المنتجات ثانيًا (تحتاج الـ mapping لترجمة معرّفات التصنيفات)
 *
 * idempotent: تشغيل الأمر مرتين لن يُنشئ تكرارًا (SKU + EntityMapping).
 */
class RunInitialMigration extends Command
{
    protected $signature = 'salla:migrate
                            {source : store_id للمتجر المصدر}
                            {target : store_id للمتجر الهدف}
                            {--dry-run : عرض ما سيتم دون تنفيذ فعلي}
                            {--categories-only : نسخ التصنيفات فقط}
                            {--products-only : نسخ المنتجات فقط}';

    protected $description = 'النسخة الأولية الكاملة: نسخ التصنيفات والمنتجات من متجر إلى آخر';

    public function __construct(protected TokenManager $tokens) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourceId = (int) $this->argument('source');
        $targetId = (int) $this->argument('target');
        $dryRun   = $this->option('dry-run');

        // ======== التحقق من وجود المتجرين ========
        $sourceStore = SallaStore::where('store_id', $sourceId)->first();
        $targetStore = SallaStore::where('store_id', $targetId)->first();

        if (!$sourceStore) {
            $this->error("المتجر المصدر (store_id={$sourceId}) غير موجود. ثبّت التطبيق عليه أولًا.");
            return self::FAILURE;
        }
        if (!$targetStore) {
            $this->error("المتجر الهدف (store_id={$targetId}) غير موجود. ثبّت التطبيق عليه أولًا.");
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] وضع المعاينة — لن تُنفَّذ أي تعديلات');
            $this->line("المصدر: {$sourceStore->store_name} ({$sourceId})");
            $this->line("الهدف:  {$targetStore->store_name} ({$targetId})");
            return self::SUCCESS;
        }

        // ======== بناء الـ Clients ========
        $source = new SallaClient($sourceStore, $this->tokens);
        $target = new SallaClient($targetStore, $this->tokens);

        // ======== سجل عملية المزامنة ========
        $job = SyncJob::start($sourceId, $targetId, 'initial_migration');

        $this->info("بدء النسخة الأولية: {$sourceId} → {$targetId}");
        $this->line(str_repeat('─', 50));

        $categoriesOnly = $this->option('categories-only');
        $productsOnly   = $this->option('products-only');

        // ======== 1. التصنيفات ========
        if (!$productsOnly) {
            $this->info('1/2 نسخ التصنيفات...');
            $catJob  = SyncJob::start($sourceId, $targetId, 'initial_migration', 'category');
            $catSvc  = new CategorySyncService($source, $target, $sourceId, $targetId);
            $catCount = $catSvc->run($catJob);
            $catJob->finish();
            $this->info("   ✓ تصنيفات: {$catCount} منسوخة ({$catJob->failed} فشلت)");
        }

        if ($categoriesOnly) {
            $job->finish();
            $this->info('اكتمل نسخ التصنيفات ✅');
            return self::SUCCESS;
        }

        // ======== 2. المنتجات ========
        if (!$categoriesOnly) {
            $this->info('2/2 نسخ المنتجات...');
            $prodJob  = SyncJob::start($sourceId, $targetId, 'initial_migration', 'product');
            $prodSvc  = new ProductSyncService($source, $target, $sourceId, $targetId);
            $prodCount = $prodSvc->run($prodJob);
            $prodJob->finish();
            $this->info("   ✓ منتجات: {$prodCount} منسوخة ({$prodJob->failed} فشلت)");
        }

        // ======== إنهاء ========
        $job->finish();
        $this->line(str_repeat('─', 50));
        $this->info('اكتملت النسخة الأولية ✅');

        // تأكد أن زوج المتاجر مسجَّل
        StorePair::firstOrCreate(
            ['source_store_id' => $sourceId, 'target_store_id' => $targetId],
            [
                'same_merchant'   => false,
                'sync_categories' => true,
                'sync_products'   => true,
                'sync_customers'  => false,
                'active'          => true,
            ]
        );

        return self::SUCCESS;
    }
}
