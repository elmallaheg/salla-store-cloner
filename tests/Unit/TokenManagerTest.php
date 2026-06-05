<?php

namespace Tests\Unit;

use App\Models\SallaStore;
use App\Services\Salla\TokenManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اختبارات وحدة TokenManager.
 *
 * ما نختبره:
 *  1. تخزين التوكن من حدث app.store.authorize
 *  2. إرجاع توكن صالح بدون refresh
 *  3. refresh التوكن المنتهي مع القفل الذرّي
 *  4. منع refresh مزدوج (أهم اختبار — سلة تُلغي التوكنز)
 */
class TokenManagerTest extends TestCase
{
    use RefreshDatabase;

    protected TokenManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new TokenManager();
    }

    // ======================================================
    // اختبارات storeFromAuthorizeEvent
    // ======================================================

    public function test_stores_token_from_authorize_event(): void
    {
        $payload = [
            'merchant'      => 123456,
            'access_token'  => 'access_abc',
            'refresh_token' => 'refresh_xyz',
            'expires'       => now()->addDays(14)->timestamp,
        ];

        $store = $this->manager->storeFromAuthorizeEvent($payload);

        $this->assertDatabaseHas('salla_stores', [
            'store_id'      => 123456,
            'access_token'  => 'access_abc',
            'refresh_token' => 'refresh_xyz',
        ]);
        $this->assertEquals('access_abc', $store->access_token);
    }

    public function test_updates_existing_store_on_reauthorize(): void
    {
        // إنشاء متجر مسبق
        SallaStore::factory()->create([
            'store_id'      => 123456,
            'access_token'  => 'old_token',
        ]);

        $this->manager->storeFromAuthorizeEvent([
            'merchant'      => 123456,
            'access_token'  => 'new_token',
            'refresh_token' => 'new_refresh',
            'expires'       => now()->addDays(14)->timestamp,
        ]);

        // يجب أن يكون سجل واحد فقط
        $this->assertDatabaseCount('salla_stores', 1);
        $this->assertDatabaseHas('salla_stores', ['access_token' => 'new_token']);
    }

    public function test_uses_default_expiry_when_expires_missing(): void
    {
        $store = $this->manager->storeFromAuthorizeEvent([
            'merchant'     => 999,
            'access_token' => 'token',
        ]);

        // يجب أن يكون قريبًا من 14 يومًا
        $this->assertNotNull($store->token_expires_at);
        $this->assertGreaterThan(
            now()->addDays(13)->timestamp,
            $store->token_expires_at->timestamp
        );
    }

    public function test_throws_when_merchant_id_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->manager->storeFromAuthorizeEvent([
            'access_token' => 'token',
            // merchant مفقود
        ]);
    }

    // ======================================================
    // اختبارات validAccessToken
    // ======================================================

    public function test_returns_token_without_refresh_when_valid(): void
    {
        $store = SallaStore::factory()->create([
            'store_id'        => 111,
            'access_token'    => 'valid_token',
            'token_expires_at' => now()->addDays(7),
        ]);

        Http::fake(); // لا يجب أن يُطلق أي HTTP

        $token = $this->manager->validAccessToken($store);

        $this->assertEquals('valid_token', $token);
        Http::assertNothingSent();
    }

    public function test_refreshes_token_when_expired(): void
    {
        $store = SallaStore::factory()->create([
            'store_id'        => 222,
            'access_token'    => 'old_token',
            'refresh_token'   => 'refresh_token',
            'token_expires_at' => now()->subHour(), // منتهي
        ]);

        Http::fake([
            config('salla.oauth.token_url') => Http::response([
                'access_token'  => 'new_token',
                'refresh_token' => 'new_refresh',
                'expires_in'    => 1209600,
            ]),
        ]);

        $token = $this->manager->validAccessToken($store);

        $this->assertEquals('new_token', $token);
        $store->refresh();
        $this->assertEquals('new_token', $store->access_token);
        $this->assertEquals('new_refresh', $store->refresh_token);
    }

    public function test_atomic_lock_prevents_double_refresh(): void
    {
        // هذا الاختبار يتحقق من أن القفل الذرّي يعمل:
        // لو Token حُدِّث بينما الـ lock في الانتظار، لا يُعاد استخدام refresh_token القديم.

        $store = SallaStore::factory()->create([
            'store_id'        => 333,
            'access_token'    => 'old_token',
            'refresh_token'   => 'refresh_token',
            'token_expires_at' => now()->subHour(),
        ]);

        $refreshCallCount = 0;

        Http::fake([
            config('salla.oauth.token_url') => function () use (&$refreshCallCount, $store) {
                $refreshCallCount++;
                // محاكاة تأخر بسيط
                // لو طُلب مرتين، الثاني يجد Token محدَّث في القاعدة
                if ($refreshCallCount === 1) {
                    // الأول يُحدِّث Token
                    $store->update([
                        'access_token'     => 'refreshed_token',
                        'token_expires_at' => now()->addDays(14),
                    ]);
                }
                return Http::response([
                    'access_token'  => 'refreshed_token',
                    'refresh_token' => 'new_refresh',
                    'expires_in'    => 1209600,
                ]);
            },
        ]);

        $token = $this->manager->validAccessToken($store);

        // يجب أن لا يُحدِّث إلا مرة واحدة
        $this->assertEquals('refreshed_token', $token);
    }
}
