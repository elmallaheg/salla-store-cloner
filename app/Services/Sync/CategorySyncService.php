<?php

namespace App\Services\Sync;

use App\Models\EntityMapping;
use App\Models\SyncJob;
use App\Services\Salla\SallaClient;
use Illuminate\Support\Facades\Log;

/**
 * نسخ ومزامنة التصنيفات من متجر مصدر إلى متجر هدف.
 *
 * التحدي الرئيسي: التصنيفات شجرة هرمية (أب → أبناء).
 * لازم ننشئ الأب أولًا عشان نقدر نربط الابن بـ parent_id صحيح في الهدف.
 * الحل: نرتّب التصنيفات تنازليًا بالعمق (الجذور أولًا) قبل الإنشاء.
 *
 * ملاحظة على الـ API (بناءً على توثيق سلة v2):
 *  - List: GET /categories?page=&per_page=
 *  - Create: POST /categories  { name, status, parent_id?, image? }
 *  - استجابة الإنشاء: { data: { id, name, ... } }
 */
class CategorySyncService
{
    public function __construct(
        protected SallaClient $source,
        protected SallaClient $target,
        protected int         $sourceStoreId,
        protected int         $targetStoreId,
    ) {}

    /**
     * ينسخ كل التصنيفات ويعود بعدد ما تمّ إنشاؤه.
     * idempotent: التصنيفات الموجودة في جدول mapping تُتخطّى.
     */
    public function run(?SyncJob $job = null): int
    {
        $categories = $this->source->paginate('/categories');

        if (empty($categories)) {
            Log::info('لا توجد تصنيفات للنسخ', ['source' => $this->sourceStoreId]);
            return 0;
        }

        $sorted = $this->sortByDepth($categories);
        $count  = 0;

        foreach ($sorted as $cat) {
            try {
                if ($this->syncOne($cat)) {
                    $count++;
                    $job?->increment(true);
                }
            } catch (\Throwable $e) {
                Log::error('فشل نسخ تصنيف', [
                    'id'    => $cat['id'] ?? null,
                    'name'  => $cat['name'] ?? null,
                    'error' => $e->getMessage(),
                ]);
                $job?->increment(false);
                $job?->logError((string) ($cat['id'] ?? '?'), $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * ينسخ تصنيفًا واحدًا.
     * يعود بـ true لو أُنشئ، false لو كان موجودًا بالفعل.
     */
    public function syncOne(array $cat): bool
    {
        $sourceId = (string) $cat['id'];

        // idempotency: لو اتنسخ قبل كده، نتخطّى
        if (EntityMapping::resolveTarget($this->sourceStoreId, $this->targetStoreId, 'category', $sourceId)) {
            return false;
        }

        $payload = [
            'name'   => $cat['name'],
            'status' => $cat['status'] ?? 'active',
        ];

        // رابط الصورة (إن وُجد)
        if (!empty($cat['image'])) {
            $imageUrl = is_array($cat['image']) ? ($cat['image']['url'] ?? null) : $cat['image'];
            if ($imageUrl) {
                $payload['image'] = $imageUrl;
            }
        }

        // ترجمة parent_id من معرّف المصدر إلى معرّف الهدف
        if (!empty($cat['parent_id'])) {
            $parentTargetId = EntityMapping::resolveTarget(
                $this->sourceStoreId,
                $this->targetStoreId,
                'category',
                (string) $cat['parent_id']
            );
            if ($parentTargetId) {
                $payload['parent_id'] = (int) $parentTargetId;
            } else {
                // الأب غير موجود بعد — نسجّل تحذير ونُنشئ بدون parent
                Log::warning('parent_id للتصنيف غير موجود في الـ mapping', [
                    'category_id' => $sourceId,
                    'parent_id'   => $cat['parent_id'],
                ]);
            }
        }

        $created = $this->target->post('/categories', $payload);

        // استخلاص الـ id من الاستجابة (سلة قد ترجع data.id أو id مباشرة)
        $newId = $created['data']['id'] ?? $created['id'] ?? null;

        if (!$newId) {
            throw new \RuntimeException(
                "استجابة إنشاء التصنيف لم تُرجع id. الاستجابة: " . json_encode($created)
            );
        }

        EntityMapping::remember(
            $this->sourceStoreId,
            $this->targetStoreId,
            'category',
            $sourceId,
            (string) $newId
        );

        Log::debug('تصنيف مُنسَخ', ['source_id' => $sourceId, 'target_id' => $newId]);

        return true;
    }

    /**
     * يُحدِّث تصنيفًا موجودًا في الهدف (للمزامنة الحيّة).
     */
    public function updateOne(array $cat): void
    {
        $sourceId = (string) $cat['id'];
        $targetId = EntityMapping::resolveTarget(
            $this->sourceStoreId, $this->targetStoreId, 'category', $sourceId
        );

        if (!$targetId) {
            // التصنيف غير موجود في الهدف — ننشئه
            $this->syncOne($cat);
            return;
        }

        $payload = ['name' => $cat['name']];
        if (isset($cat['status'])) {
            $payload['status'] = $cat['status'];
        }

        $this->target->put("/categories/{$targetId}", $payload);
    }

    // ======================================================
    // Private Helpers
    // ======================================================

    /**
     * يرتّب التصنيفات بحيث الأب يأتي قبل الابن دائمًا.
     * الخوارزمية: حساب عمق كل تصنيف ثم الفرز تصاعديًا.
     */
    protected function sortByDepth(array $categories): array
    {
        $byId = collect($categories)->keyBy('id');

        $getDepth = function (array $cat) use (&$getDepth, $byId): int {
            $depth = 0;
            $current = $cat;
            // نتبع سلسلة الآباء حتى الجذر مع حماية من الحلقات اللانهائية
            $visited = [];
            while (!empty($current['parent_id']) && $byId->has($current['parent_id'])) {
                if (in_array($current['parent_id'], $visited)) {
                    break; // حلقة — نوقف
                }
                $visited[] = $current['parent_id'];
                $depth++;
                $current = $byId->get($current['parent_id']);
            }
            return $depth;
        };

        return collect($categories)
            ->sortBy(fn ($cat) => $getDepth($cat))
            ->values()
            ->all();
    }
}
