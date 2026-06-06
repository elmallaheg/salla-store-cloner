<?php

namespace App\Services\Sync;

use App\Models\EntityMapping;
use App\Models\SyncJob;
use App\Services\Salla\SallaClient;
use Illuminate\Support\Facades\Log;

/**
 * نسخ ومزامنة المنتجات من متجر مصدر إلى متجر هدف.
 *
 * التحديات التي يعالجها هذا الـ service:
 *
 *  1. ترجمة معرّفات التصنيفات:
 *     المنتج يُشير لتصنيفات بمعرّفات المصدر — نُحوّلها لمعرّفات الهدف.
 *     لذلك CategorySyncService يجب أن يعمل أولًا.
 *
 *  2. idempotency بالـ SKU + EntityMapping:
 *     - لو منتج موجود في الـ mapping: نُحدّثه.
 *     - لو SKU موجود بالـ mapping بدون source_id مطابق: نتعامل معه كتحديث.
 *     - لو جديد كليًا: ننشئه.
 *
 *  3. حالة المنتج بعد الإنشاء:
 *     - يبقى "hidden" حتى تُرفق صورة → نرفق الصور فورًا بعد الإنشاء.
 *     - يبقى "out" حتى تُضاف كمية → الكمية تُرسَل في الـ payload.
 *
 *  4. الصور:
 *     POST /products/{id}/images مع { "original": "<url>" } لكل صورة.
 *
 * ملاحظة: حقول سلة v2 قد تتغير — راجع التوثيق الرسمي عند أي تعارض.
 */
class ProductSyncService
{
    public function __construct(
        protected SallaClient $source,
        protected SallaClient $target,
        protected int         $sourceStoreId,
        protected int         $targetStoreId,
    ) {}

    /**
     * ينسخ كل المنتجات من المصدر.
     * idempotent: المنتجات الموجودة في الـ mapping تُحدَّث لا تُكرَّر.
     */
    public function run(?SyncJob $job = null): int
    {
        $products = $this->source->paginate('/products');
        $count    = 0;

        foreach ($products as $summary) {
            try {
                // بعض الـ list endpoints ترجع بيانات مبسّطة — نجيب الكامل
                $product = $this->fetchFullProduct($summary);
                $this->syncOne($product);
                $count++;
                $job?->tick(true);
            } catch (\Throwable $e) {
                Log::error('فشل نسخ منتج', [
                    'id'    => $summary['id'] ?? null,
                    'sku'   => $summary['sku'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $job?->tick(false);
                $job?->logError((string) ($summary['id'] ?? '?'), $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * ينسخ أو يُحدِّث منتجًا واحدًا.
     * يُستخدم من run() ومن Queue Job للمزامنة الحيّة.
     */
    public function syncOne(array $product): void
    {
        $sourceId = (string) $product['id'];
        $sku      = $product['sku'] ?? null;

        // ======== 1. هل المنتج موجود في الهدف؟ ========
        $existingTargetId = $this->resolveExistingTarget($sourceId, $sku);

        // ======== 2. بناء الـ payload ========
        $payload = $this->buildPayload($product);

        if ($existingTargetId) {
            // تحديث منتج موجود
            $this->target->put("/products/{$existingTargetId}", $payload);
            Log::debug('منتج مُحدَّث', ['source_id' => $sourceId, 'target_id' => $existingTargetId]);
        } else {
            // إنشاء منتج جديد
            $created = $this->target->post('/products', $payload);
            $newId   = $created['data']['id'] ?? $created['id'] ?? null;

            if (!$newId) {
                throw new \RuntimeException(
                    "استجابة إنشاء المنتج لم تُرجع id. الاستجابة: " . json_encode($created)
                );
            }

            // تسجيل الـ mapping
            EntityMapping::remember(
                $this->sourceStoreId, $this->targetStoreId,
                'product', $sourceId, (string) $newId, $sku
            );

            // رفع الصور فورًا (تُغيّر حالة المنتج من hidden)
            $this->attachImages((string) $newId, $product['images'] ?? []);

            Log::debug('منتج مُنشَأ', ['source_id' => $sourceId, 'target_id' => $newId]);
        }
    }

    // ======================================================
    // Private Helpers
    // ======================================================

    /**
     * يجيب التفاصيل الكاملة للمنتج (الـ list أحيانًا مبسّط).
     * لو الـ summary يبدو كاملًا (يحوي images)، نستخدمه مباشرةً.
     */
    protected function fetchFullProduct(array $summary): array
    {
        if (isset($summary['images']) && isset($summary['categories'])) {
            return $summary;
        }

        try {
            $res = $this->source->get("/products/{$summary['id']}");
            return $res['data'] ?? $summary;
        } catch (\Throwable $e) {
            Log::warning('تعذّر جلب تفاصيل المنتج — استخدام الملخص', [
                'id'    => $summary['id'],
                'error' => $e->getMessage(),
            ]);
            return $summary;
        }
    }

    /**
     * يبحث عن target_id موجود:
     *  1. عبر EntityMapping بالـ source_id
     *  2. عبر EntityMapping بالـ SKU (idempotency عند إعادة التشغيل)
     */
    protected function resolveExistingTarget(string $sourceId, ?string $sku): ?string
    {
        // البحث المباشر بالـ source_id
        $targetId = EntityMapping::resolveTarget(
            $this->sourceStoreId, $this->targetStoreId, 'product', $sourceId
        );
        if ($targetId) return $targetId;

        // بحث احتياطي بالـ SKU
        if ($sku) {
            $mapping = EntityMapping::findBySku($this->sourceStoreId, $this->targetStoreId, $sku);
            if ($mapping) {
                // تحديث الـ source_id في الـ mapping لو اختلف (أُعيد إنشاء المنتج)
                if ($mapping->source_id !== $sourceId) {
                    $mapping->update(['source_id' => $sourceId]);
                }
                return $mapping->target_id;
            }
        }

        return null;
    }

    /**
     * يبني payload إنشاء/تحديث المنتج.
     *
     * ⚠️ أسماء الحقول هنا مبنية على توثيق سلة v2.
     *    راجع https://docs.salla.dev/ لو ظهرت أخطاء validation.
     */
    protected function buildPayload(array $product): array
    {
        // السعر: سلة قد يُرجعه كـ { amount, currency } أو عدد مباشر
        $price = is_array($product['price'] ?? null)
            ? ($product['price']['amount'] ?? 0)
            : ($product['price'] ?? 0);

        // التصنيفات: ترجمة معرّفات المصدر → الهدف
        $targetCategories = $this->translateCategories($product['categories'] ?? []);

        $payload = [
            'name'         => $product['name'],
            'price'        => (float) $price,
            'product_type' => $product['type'] ?? 'product',   // product | service | ...
            'description'  => $product['description'] ?? '',
            'quantity'     => (int) ($product['quantity'] ?? 0),
            'categories'   => $targetCategories,
        ];

        // حقول اختيارية
        if (!empty($product['sku'])) {
            $payload['sku'] = $product['sku'];
        }

        if (isset($product['sale_price'])) {
            $salePrice = is_array($product['sale_price'])
                ? ($product['sale_price']['amount'] ?? null)
                : $product['sale_price'];
            if ($salePrice !== null) {
                $payload['sale_price'] = (float) $salePrice;
            }
        }

        if (!empty($product['weight'])) {
            $payload['weight'] = $product['weight'];
        }

        if (isset($product['status'])) {
            $payload['status'] = $product['status'];
        }

        // metadata/tags لو دعمتها الـ API
        if (!empty($product['tags'])) {
            $payload['tags'] = $product['tags'];
        }

        return $payload;
    }

    /**
     * يُحوّل معرّفات تصنيفات المصدر إلى معرّفات الهدف.
     * التصنيفات غير الموجودة في الـ mapping تُتخطّى مع تسجيل تحذير.
     */
    protected function translateCategories(array $categories): array
    {
        $targetIds = [];

        foreach ($categories as $cat) {
            $sourceId = is_array($cat) ? ($cat['id'] ?? null) : $cat;
            if (!$sourceId) continue;

            $targetId = EntityMapping::resolveTarget(
                $this->sourceStoreId, $this->targetStoreId, 'category', (string) $sourceId
            );

            if ($targetId) {
                $targetIds[] = (int) $targetId;
            } else {
                Log::warning('تصنيف المنتج غير موجود في الـ mapping', [
                    'category_source_id' => $sourceId,
                    'source_store'       => $this->sourceStoreId,
                ]);
            }
        }

        return $targetIds;
    }

    /**
     * يرفق صور المنتج في الهدف.
     *
     * الترتيب مهم: الصورة الأولى ستكون الرئيسية.
     * ترفع عبر URL مباشرة (سلة تسحبها) — لا نُرفع الملف يدويًا.
     */
    protected function attachImages(string $targetProductId, array $images): void
    {
        foreach ($images as $img) {
            // قد يكون كائن { url, main } أو رابط نصي
            $url = is_array($img)
                ? ($img['url'] ?? $img['image'] ?? $img['original'] ?? null)
                : $img;

            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            try {
                $this->target->attachProductImage($targetProductId, $url);
            } catch (\Throwable $e) {
                // فشل رفع صورة لا يوقف العملية كلها
                Log::warning('فشل رفع صورة للمنتج', [
                    'product_id' => $targetProductId,
                    'url'        => $url,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
