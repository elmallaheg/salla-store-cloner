<?php

namespace App\Services\Sync;

use App\Models\EntityMapping;
use App\Models\SyncJob;
use App\Services\Salla\SallaClient;
use Illuminate\Support\Facades\Log;

/**
 * نسخ ومزامنة المنتجات من متجر مصدر إلى متجر هدف.
 */
class ProductSyncService
{
    public function __construct(
        protected SallaClient $source,
        protected SallaClient $target,
        protected int         $sourceStoreId,
        protected int         $targetStoreId,
    ) {}

    public function run(?SyncJob $job = null, ?int $maxProducts = null): int
    {
        $products = $this->source->paginate('/products');
        $count    = 0;

        foreach ($products as $summary) {
            if ($maxProducts !== null && $count >= $maxProducts) {
                Log::info('وصل لحد الباقة — توقف النسخ', [
                    'max' => $maxProducts, 'synced' => $count,
                ]);
                break;
            }

            try {
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

    public function syncOne(array $product): void
    {
        $sourceId = (string) $product['id'];
        $sku      = $product['sku'] ?? null;

        $existingTargetId = $this->resolveExistingTarget($sourceId, $sku);
        $payload = $this->buildPayload($product);

        if ($existingTargetId) {
            $this->target->put("/products/{$existingTargetId}", $payload);
            Log::debug('منتج مُحدَّث', ['source_id' => $sourceId, 'target_id' => $existingTargetId]);
        } else {
            $created = $this->target->post('/products', $payload);
            $newId   = $created['data']['id'] ?? $created['id'] ?? null;

            if (!$newId) {
                throw new \RuntimeException(
                    "استجابة إنشاء المنتج لم تُرجع id. الاستجابة: " . json_encode($created)
                );
            }

            EntityMapping::remember(
                $this->sourceStoreId, $this->targetStoreId,
                'product', $sourceId, (string) $newId, $sku
            );

            $this->attachImages((string) $newId, $product['images'] ?? []);
            Log::debug('منتج مُنشَأ', ['source_id' => $sourceId, 'target_id' => $newId]);
        }
    }

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

    protected function resolveExistingTarget(string $sourceId, ?string $sku): ?string
    {
        $targetId = EntityMapping::resolveTarget(
            $this->sourceStoreId, $this->targetStoreId, 'product', $sourceId
        );
        if ($targetId) return $targetId;

        if ($sku) {
            $mapping = EntityMapping::findBySku($this->sourceStoreId, $this->targetStoreId, $sku);
            if ($mapping) {
                if ($mapping->source_id !== $sourceId) {
                    $mapping->update(['source_id' => $sourceId]);
                }
                return $mapping->target_id;
            }
        }

        return null;
    }

    protected function buildPayload(array $product): array
    {
        $price = is_array($product['price'] ?? null)
            ? ($product['price']['amount'] ?? 0)
            : ($product['price'] ?? 0);

        $targetCategories = $this->translateCategories($product['categories'] ?? []);

        // استخراج الاسم كنص بسيط (سلة ترجع name كـ string أو object)
        $name = $product['name'] ?? '';
        if (is_array($name)) {
            $name = $name['ar'] ?? $name['en'] ?? reset($name) ?? '';
        }

        // استخراج الوصف كنص بسيط
        $description = $product['description'] ?? '';
        if (is_array($description)) {
            $description = $description['ar'] ?? $description['en'] ?? reset($description) ?? '';
        }
        // إزالة HTML tags إن وجدت
        $description = strip_tags((string) $description);

        $payload = [
            'name'        => $name,
            'price'       => (float) $price,
            'type'        => $product['type'] ?? 'product',
            'description' => $description,
            'quantity'    => (int) ($product['quantity'] ?? 0),
            'categories'  => $targetCategories,
        ];

        if (!empty($product['sku']))         $payload['sku']        = $product['sku'];
        if (isset($product['sale_price'])) {
            $sp = is_array($product['sale_price']) ? ($product['sale_price']['amount'] ?? null) : $product['sale_price'];
            if ($sp !== null) $payload['sale_price'] = (float) $sp;
        }
        if (!empty($product['weight']))      $payload['weight']     = $product['weight'];
        if (isset($product['status']))       $payload['status']     = $product['status'];
        if (!empty($product['tags']))        $payload['tags']       = $product['tags'];

        return $payload;
    }

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

    protected function attachImages(string $targetProductId, array $images): void
    {
        foreach ($images as $img) {
            $url = is_array($img) ? ($img['url'] ?? $img['image'] ?? $img['original'] ?? null) : $img;
            if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;

            try {
                $this->target->attachProductImage($targetProductId, $url);
            } catch (\Throwable $e) {
                Log::warning('فشل رفع صورة للمنتج', [
                    'product_id' => $targetProductId,
                    'url'        => $url,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }
}
