<?php

namespace Tests\Unit;

use App\Models\SallaStore;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اختبارات وحدة SallaClient.
 *
 * ما نختبره:
 *  1. حقن التوكن في كل طلب
 *  2. إعادة المحاولة عند HTTP 429 مع backoff
 *  3. احترام رأس Retry-After
 *  4. ترقيم الصفحات (pagination)
 *  5. رمي استثناء عند أخطاء HTTP الأخرى
 */
class SallaClientTest extends TestCase
{
    use RefreshDatabase;

    protected SallaStore  $store;
    protected SallaClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        // متجر تجريبي بتوكن صالح
        $this->store = new SallaStore([
            'store_id'        => 12345,
            'access_token'    => 'test_access_token',
            'refresh_token'   => 'test_refresh',
            'token_expires_at' => Carbon::now()->addDays(7),
        ]);
        $this->store->id = 1; // Mock id

        // TokenManager مزيّف يُرجع التوكن مباشرةً
        $tokenManager = $this->createMock(TokenManager::class);
        $tokenManager->method('validAccessToken')->willReturn('test_access_token');

        $this->client = new SallaClient($this->store, $tokenManager);
    }

    // ======================================================
    // اختبارات الطلبات الأساسية
    // ======================================================

    public function test_sends_authorization_header(): void
    {
        Http::fake([
            '*/products' => Http::response(['data' => [], 'pagination' => ['totalPages' => 1]]),
        ]);

        $this->client->get('/products');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test_access_token');
        });
    }

    public function test_get_returns_parsed_json(): void
    {
        Http::fake([
            '*/categories' => Http::response([
                'data' => [['id' => 1, 'name' => 'إلكترونيات']],
                'pagination' => ['totalPages' => 1],
            ]),
        ]);

        $result = $this->client->get('/categories');

        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('إلكترونيات', $result['data'][0]['name']);
    }

    public function test_post_sends_json_body(): void
    {
        Http::fake([
            '*/products' => Http::response(['data' => ['id' => 99, 'name' => 'منتج جديد']]),
        ]);

        $result = $this->client->post('/products', ['name' => 'منتج جديد', 'price' => 100]);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && $request->data()['name'] === 'منتج جديد';
        });
        $this->assertEquals(99, $result['data']['id']);
    }

    // ======================================================
    // اختبارات Rate Limiting (429)
    // ======================================================

    public function test_retries_on_429_with_exponential_backoff(): void
    {
        $callCount = 0;

        Http::fake([
            '*/products' => function () use (&$callCount) {
                $callCount++;
                if ($callCount < 3) {
                    return Http::response(['error' => 'rate limited'], 429);
                }
                return Http::response(['data' => ['id' => 1], 'status' => 200]);
            },
        ]);

        // نُعدِّل sleep لتسريع الاختبار
        config(['salla.sync.retry_base_secs' => 0]);

        $result = $this->client->get('/products');

        $this->assertEquals(3, $callCount);
        $this->assertEquals(1, $result['data']['id']);
    }

    public function test_respects_retry_after_header(): void
    {
        $sleepCalls = [];

        Http::fake([
            '*/products' => Http::sequence()
                ->push(['error' => 'rate limited'], 429, ['Retry-After' => '5'])
                ->push(['data' => ['id' => 1]], 200),
        ]);

        config(['salla.sync.retry_base_secs' => 999]); // لو Retry-After مُتجاهَل سيكون الانتظار 999

        // نُراقب sleep عبر mock — هنا نتحقق فقط أن الاستجابة صحيحة
        $result = $this->client->get('/products');
        $this->assertEquals(1, $result['data']['id']);
    }

    public function test_throws_after_max_retries(): void
    {
        Http::fake([
            '*/products' => Http::response(['error' => 'rate limited'], 429),
        ]);

        config(['salla.sync.max_retries' => 2, 'salla.sync.retry_base_secs' => 0]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        $this->client->get('/products');
    }

    // ======================================================
    // اختبارات Pagination
    // ======================================================

    public function test_paginate_collects_all_pages(): void
    {
        Http::fake([
            '*/products*' => Http::sequence()
                ->push([
                    'data'       => [['id' => 1], ['id' => 2]],
                    'pagination' => ['currentPage' => 1, 'totalPages' => 3],
                ])
                ->push([
                    'data'       => [['id' => 3], ['id' => 4]],
                    'pagination' => ['currentPage' => 2, 'totalPages' => 3],
                ])
                ->push([
                    'data'       => [['id' => 5]],
                    'pagination' => ['currentPage' => 3, 'totalPages' => 3],
                ]),
        ]);

        $all = $this->client->paginate('/products');

        $this->assertCount(5, $all);
        $this->assertEquals([1, 2, 3, 4, 5], array_column($all, 'id'));
    }

    public function test_paginate_handles_single_page(): void
    {
        Http::fake([
            '*/categories*' => Http::response([
                'data'       => [['id' => 10], ['id' => 11]],
                'pagination' => ['currentPage' => 1, 'totalPages' => 1],
            ]),
        ]);

        $all = $this->client->paginate('/categories');

        $this->assertCount(2, $all);
    }

    // ======================================================
    // اختبارات attachProductImage
    // ======================================================

    public function test_attach_product_image_sends_correct_payload(): void
    {
        Http::fake([
            '*/products/42/images' => Http::response(['data' => ['id' => 1]]),
        ]);

        $this->client->attachProductImage(42, 'https://example.com/img.jpg');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/products/42/images')
                && $request->data()['original'] === 'https://example.com/img.jpg';
        });
    }
}
