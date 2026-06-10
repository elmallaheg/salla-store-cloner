<?php

use App\Http\Controllers\WebhookController;
use App\Models\SallaStore;
use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::post('/salla/webhook', [WebhookController::class, 'handle'])
    ->name('salla.webhook');

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

// Temporary migration runner
Route::get('/admin/run-migrate/{source}/{target}', function (string $source, string $target) {
    if (request('secret') !== 'salla-migrate-2026') {
        return response()->json(['error' => 'unauthorized'], 403);
    }
    try {
        $exitCode = Artisan::call('salla:migrate', [
            'source' => $source,
            'target' => $target,
        ]);
        return response()->json([
            'status'    => 'done',
            'exit_code' => $exitCode,
            'output'    => Artisan::output(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'failed',
            'error'  => $e->getMessage(),
            'file'   => $e->getFile(),
            'line'   => $e->getLine(),
        ], 500);
    }
});

// Debug: try creating one product and show raw Salla API response
Route::get('/admin/test-product/{source}/{target}', function (string $source, string $target) {
    if (request('secret') !== 'salla-migrate-2026') {
        return response()->json(['error' => 'unauthorized'], 403);
    }
    try {
        $sourceStore = SallaStore::where('store_id', $source)->firstOrFail();
        $targetStore = SallaStore::where('store_id', $target)->firstOrFail();
        $tokens      = app(TokenManager::class);
        $srcClient   = new SallaClient($sourceStore, $tokens);
        $tgtClient   = new SallaClient($targetStore, $tokens);

        // جلب أول منتج
        $list    = $srcClient->get('/products', ['per_page' => 1, 'page' => 1]);
        $summary = $list['data'][0] ?? null;
        if (!$summary) return response()->json(['error' => 'no products']);

        $full    = $srcClient->get('/products/' . $summary['id']);
        $product = $full['data'] ?? $summary;

        $price = is_array($product['price'] ?? null)
            ? ($product['price']['amount'] ?? 0)
            : ($product['price'] ?? 0);

        $name = $product['name'] ?? '';
        if (is_array($name)) $name = $name['ar'] ?? $name['en'] ?? reset($name) ?? '';

        // Payload: minimal test — just name + price
        $payload = [
            'name'  => $name,
            'price' => (float) $price,
        ];

        // محاولة الإنشاء وإرجاع الـ response الخام
        try {
            $http = \Illuminate\Support\Facades\Http::withToken($tgtClient->getAccessToken())
                ->post('https://api.salla.dev/admin/v2/products', $payload);

            return response()->json([
                'payload'    => $payload,
                'status'     => $http->status(),
                'response'   => $http->json(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'payload' => $payload,
                'error'   => $e->getMessage(),
            ], 500);
        }
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file'  => class_basename($e->getFile()),
            'line'  => $e->getLine(),
        ], 500);
    }
});
