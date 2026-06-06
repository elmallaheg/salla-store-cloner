<?php

namespace App\Console\Commands;

use App\Models\SallaStore;
use App\Models\StorePair;
use App\Models\Subscription;
use Illuminate\Console\Command;

/**
 * ربط متجر مصدر بمتجر هدف لعملية المزامنة.
 *
 * الاستخدام:
 *   php artisan salla:pair {source} {target} [--same-merchant] [--no-categories] [--no-products]
 */
class PairStores extends Command
{
    protected $signature = 'salla:pair
                            {source : store_id للمتجر المصدر}
                            {target : store_id للمتجر الهدف}
                            {--same-merchant : المتجران لنفس التاجر}
                            {--no-categories : لا تزامن التصنيفات}
                            {--no-products : لا تزامن المنتجات}
                            {--enable-customers : مزامنة العملاء (يتطلب --same-merchant + PDPL)}
                            {--deactivate : تعطيل الزوج بدل التفعيل}';

    protected $description = 'ربط متجر مصدر بمتجر هدف للمزامنة التلقائية عبر Webhooks';

    public function handle(): int
    {
        $sourceId = (int) $this->argument('source');
        $targetId = (int) $this->argument('target');

        $sourceStore = SallaStore::where('store_id', $sourceId)->first();
        $targetStore = SallaStore::where('store_id', $targetId)->first();

        if (!$sourceStore || !$targetStore) {
            $this->error('أحد المتجرين أو كلاهما غير موجود. تحقق من تثبيت التطبيق عليهما.');
            return self::FAILURE;
        }

        $enableCustomers = $this->option('enable-customers');
        $sameMerchant    = $this->option('same-merchant');

        if ($enableCustomers && !$sameMerchant) {
            $this->error('مزامنة العملاء تتطلب --same-merchant (PDPL).');
            return self::FAILURE;
        }

        if ($enableCustomers && !config('salla.sync.enable_customer_sync', false)) {
            $this->warn('تحذير: SALLA_ENABLE_CUSTOMER_SYNC=false في .env — مزامنة العملاء لن تعمل.');
        }

        $active = !$this->option('deactivate');

        $pair = StorePair::updateOrCreate(
            ['source_store_id' => $sourceId, 'target_store_id' => $targetId],
            [
                'same_merchant'   => $sameMerchant,
                'sync_categories' => !$this->option('no-categories'),
                'sync_products'   => !$this->option('no-products'),
                'sync_customers'  => $enableCustomers,
                'active'          => $active,
            ]
        );

        $sourceStore->update(['role' => 'source']);
        $targetStore->update(['role' => 'target']);

        // بدء تجربة مجانية (7 أيام - باقة starter)
        if ($active) {
            $sub = Subscription::startTrial($pair, 'starter');
            $this->info("تجربة مجانية مُفعَّلة: {$sub->max_products} منتج لمدة 7 أيام");
        }

        $this->line(str_repeat('─', 50));
        $this->info($active ? 'زوج مُسجَّل ✅' : 'زوج مُعطَّل ⏸');
        $this->table(
            ['الخاصية', 'القيمة'],
            [
                ['المصدر',           "{$sourceStore->store_name} ({$sourceId})"],
                ['الهدف',            "{$targetStore->store_name} ({$targetId})"],
                ['نفس التاجر',       $sameMerchant ? 'نعم' : 'لا'],
                ['مزامنة التصنيفات', $pair->sync_categories ? 'نعم' : 'لا'],
                ['مزامنة المنتجات',  $pair->sync_products ? 'نعم' : 'لا'],
                ['مزامنة العملاء',   $pair->sync_customers ? 'نعم ⚠️' : 'لا (PDPL)'],
                ['الحالة',           $active ? 'نشط' : 'معطّل'],
            ]
        );

        if ($active) {
            $this->line('');
            $this->comment('للبدء بالنسخة الأولية الكاملة:');
            $this->line("  php artisan salla:migrate {$sourceId} {$targetId}");
        }

        return self::SUCCESS;
    }
}
