<?php

namespace App\Services\Salla;

use App\Models\SallaStore;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * غلاف HTTP حول Merchant API: https://api.salla.dev/admin/v2
 *
 * المسؤوليات:
 *  - حقن توكن المتجر تلقائيًا في كل طلب
 *  - إعادة المحاولة عند تجاوز حد الطلبات (HTTP 429) بـ exponential backoff
 *  - ترقيم الصفحات (pagination) تلقائي في paginate()
 *  - رفع ملفات (multipart) لـ POST /products/{id}/images
 *
 * بنية استجابات سلة القياسية:
 *  { "status": 200, "success": true, "data": {...}, "pagination": {...} }
 */
class SallaClient
{
    public function __construct(
        protected SallaStore  $store,
        protected TokenManager $tokens,
    ) {}

    // ======================================================
    // الطرق العامة
    // ======================================================

    /** GET مع retry عند 429 */
    public function get(string $path, array $query = []): array
    {
        return $this->withRetry(fn () => $this->request()->get($path, $query));
    }

    /** POST (JSON body) */
    public function post(string $path, array $body): array
    {
        return $this->withRetry(fn () => $this->request()->post($path, $body));
    }

    /** PUT (JSON body) */
    public function put(string $path, array $body): array
    {
        return $this->withRetry(fn () => $this->request()->put($path, $body));
    }

    /** DELETE */
    public function delete(string $path): array
    {
        return $this->withRetry(fn () => $this->request()->delete($path));
    }

    /**
     * رفع صورة منتج عبر URL (سلة تسحبها هي من الرابط).
     *
     * Endpoint: POST /products/{id}/images
     * الحقل المطلوب: "original" (رابط الصورة)
     * ملاحظة: المنتج يبقى hidden حتى تُرفق صورة — لذلك ارفق الصور فور الإنشاء.
     */
    public function attachProductImage(string|int $productId, string $imageUrl): array
    {
        return $this->post("/products/{$productId}/images", ['original' => $imageUrl]);
    }

    /**
     * يمر على كل صفحات أي List endpoint ويجمع النتائج.
     *
     * بنية pagination في سلة:
     *  { "pagination": { "currentPage": 1, "totalPages": 5, "perPage": 50 } }
     */
    public function paginate(string $path, array $query = []): array
    {
        $all     = [];
        $page    = 1;
        $perPage = config('salla.sync.per_page', 50);

        do {
            $res  = $this->get($path, array_merge($query, ['page' => $page, 'per_page' => $perPage]));
            $data = $res['data'] ?? [];

            // سلة أحيانًا تُرجع الـ data مباشرةً كـ array عوضًا عن كائن مُصنَّف
            if (isset($data[0]) || empty($data)) {
                $all = array_merge($all, $data);
            } else {
                // لو data كائن واحد وليس مصفوفة (endpoint عنصر فردي)
                $all[] = $data;
                break;
            }

            $totalPages = $res['pagination']['totalPages'] ?? $page;
            $page++;
        } while ($page <= $totalPages);

        return $all;
    }

    // ======================================================
    // Private Helpers
    // ======================================================

    /** يبني PendingRequest مع توكن المتجر */
    protected function request(): PendingRequest
    {
        return Http::baseUrl(config('salla.api_base', 'https://api.salla.dev/admin/v2'))
            ->withToken($this->tokens->validAccessToken($this->store))
            ->acceptJson()
            ->timeout(30);
    }

    /**
     * ينفّذ الطلب مع إعادة محاولة عند HTTP 429.
     *
     * استراتيجية backoff:
     *  - إذا وُجد رأس Retry-After: انتظر القيمة المحددة
     *  - إذا لم يُوجد: base_secs * 2^attempt (exponential)
     */
    protected function withRetry(callable $call): array
    {
        $attempt  = 0;
        $maxRetry = config('salla.sync.max_retries', 5);
        $baseSecs = config('salla.sync.retry_base_secs', 2);

        while (true) {
            /** @var Response $response */
            $response = $call();

            if ($response->status() === 429 && $attempt < $maxRetry) {
                $retryAfter = $response->header('Retry-After');
                $wait = $retryAfter
                    ? (int) $retryAfter
                    : $baseSecs * (2 ** $attempt);

                Log::warning('Rate limit — انتظار قبل إعادة المحاولة', [
                    'store_id' => $this->store->store_id,
                    'attempt'  => $attempt + 1,
                    'wait_sec' => $wait,
                ]);

                sleep($wait);
                $attempt++;
                continue;
            }

            // HTTP 401: التوكن انتهى — نحاول تحديثه مرة واحدة
            if ($response->status() === 401 && $attempt === 0) {
                Log::warning('توكن منتهي (401) — محاولة تحديث', ['store_id' => $this->store->store_id]);
                // TokenManager سيُجبر الـ refresh في المرة القادمة
                $this->store->update(['token_expires_at' => now()->subMinute()]);
                $attempt++;
                continue;
            }

            // أي خطأ آخر يُلقي استثناء
            $response->throw();

            return $response->json() ?? [];
        }
    }
}
