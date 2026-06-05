<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * جدول تطابق المعرّفات — العمود الفقري للمشروع.
 *
 * يربط معرّف كل عنصر (تصنيف/منتج/براند...) في المتجر المصدر
 * بمعرّفه الجديد في المتجر الهدف.
 *
 * @property int    $source_store_id
 * @property int    $target_store_id
 * @property string $entity_type     category | product | brand | ...
 * @property string $source_id
 * @property string $target_id
 * @property string $sku             للمنتجات فقط: مفتاح idempotency ثابت
 */
class EntityMapping extends Model
{
    protected $guarded = [];

    // ======== Static Helpers ========

    /**
     * يرجّع الـ target_id المقابل لمعرّف مصدر، أو null لو ما اتنسخش بعد.
     */
    public static function resolveTarget(
        int $sourceStoreId,
        int $targetStoreId,
        string $type,
        string $sourceId
    ): ?string {
        return static::where([
            'source_store_id' => $sourceStoreId,
            'target_store_id' => $targetStoreId,
            'entity_type'     => $type,
            'source_id'       => $sourceId,
        ])->value('target_id');
    }

    /**
     * يرجّع الـ source_id المقابل لمعرّف هدف (عكسي — لربط webhook بالمصدر).
     */
    public static function resolveSource(
        int $sourceStoreId,
        int $targetStoreId,
        string $type,
        string $targetId
    ): ?string {
        return static::where([
            'source_store_id' => $sourceStoreId,
            'target_store_id' => $targetStoreId,
            'entity_type'     => $type,
            'target_id'       => $targetId,
        ])->value('source_id');
    }

    /**
     * يسجّل أو يحدّث تطابق معرّف.
     * idempotent: إعادة الاستدعاء بنفس source_id لن تُنشئ سجلًا مكررًا.
     */
    public static function remember(
        int $sourceStoreId,
        int $targetStoreId,
        string $type,
        string $sourceId,
        string $targetId,
        ?string $sku = null
    ): void {
        static::updateOrCreate(
            [
                'source_store_id' => $sourceStoreId,
                'target_store_id' => $targetStoreId,
                'entity_type'     => $type,
                'source_id'       => $sourceId,
            ],
            ['target_id' => $targetId, 'sku' => $sku]
        );
    }

    /**
     * البحث بالـ SKU (للمنتجات): هل منتج بهذا الـ SKU اتنسخ من قبل؟
     */
    public static function findBySku(
        int $sourceStoreId,
        int $targetStoreId,
        string $sku
    ): ?static {
        return static::where([
            'source_store_id' => $sourceStoreId,
            'target_store_id' => $targetStoreId,
            'entity_type'     => 'product',
            'sku'             => $sku,
        ])->first();
    }
}
