<?php

namespace Tests\Unit;

use App\Models\EntityMapping;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use App\Services\Sync\CategorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اختبارات وحدة CategorySyncService.
 *
 * ما نختبره:
 *  1. ترتيب التصنيفات (الأب قبل الابن)
 *  2. idempotency (عدم التكرار عند إعادة التشغيل)
 *  3. ترجمة parent_id
 *  4. تسجيل الـ EntityMapping
 */
class CategorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function makeService(): CategorySyncService
    {
        // Mock SallaClient لا يُجري طلبات HTTP حقيقية
        $source = $this->createMock(SallaClient::class);
        $target = $this->createMock(SallaClient::class);

        return new CategorySyncService($source, $target, 1, 2);
    }

    // ======================================================

    public function test_sorts_categories_parents_before_children(): void
    {
        $service = $this->makeService();

        $categories = [
            ['id' => 10, 'name' => 'ملابس أطفال', 'parent_id' => 5],
            ['id' => 1,  'name' => 'جذر 1',       'parent_id' => null],
            ['id' => 5,  'name' => 'ملابس',        'parent_id' => 1],
            ['id' => 2,  'name' => 'جذر 2',        'parent_id' => null],
        ];

        // نستدعي sortByDepth عبر reflection
        $method = new \ReflectionMethod($service, 'sortByDepth');
        $method->setAccessible(true);
        $sorted = $method->invoke($service, $categories);

        $ids = array_column($sorted, 'id');

        // الجذور أولًا، ثم الأبناء، ثم الأحفاد
        $this->assertLessThan(array_search(5, $ids), array_search(1, $ids));
        $this->assertLessThan(array_search(10, $ids), array_search(5, $ids));
    }

    public function test_skips_already_synced_categories(): void
    {
        // تسجيل تصنيف موجود بالفعل
        EntityMapping::remember(1, 2, 'category', '100', '200');

        $source = $this->createMock(SallaClient::class);
        $target = $this->createMock(SallaClient::class);

        // يجب ألا يُستدعى post على الهدف
        $target->expects($this->never())->method('post');

        $service = new CategorySyncService($source, $target, 1, 2);
        $result  = $service->syncOne(['id' => 100, 'name' => 'تصنيف موجود']);

        $this->assertFalse($result); // false = تخطّاه لأنه موجود
    }

    public function test_translates_parent_id_to_target(): void
    {
        // الأب موجود في الـ mapping
        EntityMapping::remember(1, 2, 'category', '5', '99');

        $source = $this->createMock(SallaClient::class);
        $target = $this->createMock(SallaClient::class);

        // التحقق أن parent_id = 99 (الهدف) وليس 5 (المصدر)
        $target->expects($this->once())
            ->method('post')
            ->with('/categories', $this->callback(function ($payload) {
                return ($payload['parent_id'] ?? null) === 99;
            }))
            ->willReturn(['data' => ['id' => 200, 'name' => 'ابن']]);

        $service = new CategorySyncService($source, $target, 1, 2);
        $service->syncOne(['id' => 10, 'name' => 'ابن', 'parent_id' => 5]);

        $this->assertDatabaseHas('entity_mappings', [
            'source_store_id' => 1,
            'target_store_id' => 2,
            'entity_type'     => 'category',
            'source_id'       => '10',
            'target_id'       => '200',
        ]);
    }

    public function test_registers_mapping_after_creation(): void
    {
        $source = $this->createMock(SallaClient::class);
        $target = $this->createMock(SallaClient::class);

        $target->method('post')->willReturn(['data' => ['id' => 999]]);

        $service = new CategorySyncService($source, $target, 1, 2);
        $service->syncOne(['id' => 50, 'name' => 'تصنيف جديد']);

        $this->assertDatabaseHas('entity_mappings', [
            'source_id' => '50',
            'target_id' => '999',
            'entity_type' => 'category',
        ]);
    }
}
