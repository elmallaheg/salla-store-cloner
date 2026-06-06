<?php

namespace App\Services\Salla;

use App\Models\SallaStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * يدير توكنز كل متجر ويحدّثها بأمان.
 *
 * ⚠️ تحذير سلة الأمني:
 *   الـ refresh token "single-use". لو اتعمله refresh مرتين بالتوازي،
 *   سلة بتلغي كل التوكنز وتطلب إعادة تثبيت التطبيق.
 *   الحل: قفل ذرّي (atomic Cache lock) يضمن طلب تحديث واحد فقط لكل متجر في اللحظة.
 */
class TokenManager
{
    /**
     * يخزّن التوكنز القادمة من حدث app.store.authorize (Easy Mode).
     *
     * بنية الـ payload المتوقعة:
     *  {
     *    "merchant":      <store_id>,
     *    "access_token":  "...",
     *    "refresh_token": "...",
     *    "expires":       <unix_timestamp>   ← صلاحية access token (أسبوعان)
     *  }
     */
    public function storeFromAuthorizeEvent(array $payload): SallaStore
    {
        // الـ expires في هذا الحدث يأتي كـ unix timestamp (مؤكّد من توثيق سلة)
        $expiresAt = isset($payload['expires'])
            ? Carbon::createFromTimestamp((int) $payload['expires'])
            : now()->addDays(14); // قيمة افتراضية آمنة لو ما وُجد الحقل

        $storeId = $payload['merchant'] ?? $payload['store_id'] ?? null;

        if (!$storeId) {
            throw new \InvalidArgumentException('payload لا يحوي معرّف المتجر (merchant/store_id)');
        }

        Log::info('حفظ توكن متجر جديد/محدَّث', ['store_id' => $storeId]);

        return SallaStore::updateOrCreate(
            ['store_id' => $storeId],
            [
                'access_token'     => $payload['access_token'],
                'refresh_token'    => $payload['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * يرجّع access token صالح للمتجر.
     * يعمل refresh تلقائيًا لو التوكن انتهى أو قرب ينتهي (أقل من 12 ساعة).
     */
    public function validAccessToken(SallaStore $store): string
    {
        if ($store->hasValidToken()) {
            return $store->access_token;
        }

        return $this->refresh($store);
    }

    /**
     * يحدّث التوكن بقفل ذرّي لمنع التحديث المتوازي.
     *
     * السيناريو المحمي: طلبان متزامنان يقرآن نفس الـ refresh token ثم يرسلانه لسلة.
     * الأول ينجح والثاني يُبطَل (لأن سلة استخدمت الـ refresh بالفعل) فيُلغي سلة
     * كل التوكنز. القفل يمنع هذا بتسلسل الطلبات.
     */
    protected function refresh(SallaStore $store): string
    {
        // مفتاح القفل فريد لكل متجر
        $lock = Cache::lock("salla_token_refresh_{$store->store_id}", 60);

        return $lock->block(30, function () use ($store) {
            // بعد الحصول على القفل: أعد قراءة المتجر من القاعدة
            // (يمكن أن طلبًا آخر حدّث التوكن بينما كنا ننتظر)
            $store->refresh();

            if ($store->hasValidToken()) {
                return $store->access_token; // حُدِّث بالفعل من طلب آخر
            }

            if (!$store->refresh_token) {
                throw new \RuntimeException(
                    "المتجر {$store->store_id} لا يملك refresh_token — أعد تثبيت التطبيق"
                );
            }

            Log::info('تحديث توكن المتجر', ['store_id' => $store->store_id]);

            $response = Http::asForm()->post(config('salla.oauth.token_url'), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $store->refresh_token,
                'client_id'     => config('salla.oauth.client_id'),
                'client_secret' => config('salla.oauth.client_secret'),
            ]);

            if ($response->failed()) {
                Log::error('فشل تحديث التوكن', [
                    'store_id' => $store->store_id,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);
                throw new \RuntimeException(
                    "فشل تحديث توكن المتجر {$store->store_id}: {$response->body()}"
                );
            }

            $data = $response->json();

            // سلة ترجع refresh_token جديد مع كل تحديث (single-use)
            $store->update([
                'access_token'     => $data['access_token'],
                'refresh_token'    => $data['refresh_token'] ?? $store->refresh_token,
                'token_expires_at' => isset($data['expires_in'])
                    ? now()->addSeconds((int) $data['expires_in'])
                    : now()->addDays(14),
            ]);

            return $store->access_token;
        });
    }
}
