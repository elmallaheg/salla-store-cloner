<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * يربط متجر مصدر بمتجر هدف لعملية نسخ/مزامنة.
 *
 * يدعم سيناريوهَين:
 *  - متجران لنفس التاجر (same_merchant = true)
 *  - متجران لتاجرين مختلفين (same_merchant = false) — العملاء ممنوعون هنا (PDPL)
 *
 * @property int    $source_store_id  store_id (ليس id) للمتجر المصدر
 * @property int    $target_store_id  store_id للمتجر الهدف
 * @property bool   $same_merchant
 * @property bool   $sync_categories  هل نزامن التصنيفات؟
 * @property bool   $sync_products    هل نزامن المنتجات؟
 * @property bool   $sync_customers   هل نزامن العملاء؟ (false دائمًا إلا بموافقة صريحة)
 * @property bool   $active
 */
class StorePair extends Model
{
    protected $guarded = [];

    protected $casts = [
        'same_merchant'   => 'boolean',
        'sync_categories' => 'boolean',
        'sync_products'   => 'boolean',
        'sync_customers'  => 'boolean',
        'active'          => 'boolean',
    ];

    // ======== العلاقات ========

    public function sourceStore(): BelongsTo
    {
        return $this->belongsTo(SallaStore::class, 'source_store_id', 'store_id');
    }

    public function targetStore(): BelongsTo
    {
        return $this->belongsTo(SallaStore::class, 'target_store_id', 'store_id');
    }

    // ======== Helpers ========

    /**
     * يرجّع كل الأزواج النشطة للمتجر المصدر المحدد.
     *
     * @return \Illuminate\Database\Eloquent\Collection<static>
     */
    public static function activePairsForSource(int $sourceStoreId)
    {
        return static::where('source_store_id', $sourceStoreId)
            ->where('active', true)
            ->get();
    }

    /**
     * هل يُسمح بمزامنة العملاء في هذا الزوج؟
     * القيد: نفس التاجر + التطبيق مفعّل + إعداد الزوج مفعّل.
     */
    public function canSyncCustomers(): bool
    {
        return $this->same_merchant
            && $this->sync_customers
            && config('salla.sync.enable_customer_sync', false);
    }
}
