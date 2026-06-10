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
            'status' => 'done',
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ], 500);
    }
});

// Debug: fetch one product from source & try creating in target
Route::get('/admin/debug-product/{source}/{target}', function (string $source, string $target) {
    if (request('secret') !== 'salla-migrate-2026') {
        return response()->json(['error' => 'unauthorized'], 403);
    }
    try {
        $sourceStore = SallaStore::where('store_id', $source)->firstOrFail();
        $targetStore = SallaStore::where('store_id', $target)->firstOrFail();
        $tokens = app(TokenManager::class);
        $sourceClient = new SallaClient($sourceStore, $tokens);
        $targetClient = new SallaClient($targetStore, $tokens);

        $products = $sourceClient->get('/products', ['per_page' => 1, 'page' => 1]);
        $product = $products['data'][0] ?? null;
        if (!$product) return response()->json(['error' => 'no products found']);

        $full = $sourceClient->get('/products/' . $product['id']);
        $productData = $full['data'] ?? $product;

        $price = is_array($productData['price'] ?? null)
            ? ($productData['price']['amount'] ?? 0)
            : ($productData['price'] ?? 0);

        $payload = [
            'name'         => $productData['name'],
            'price'        => (float) $price,
            'product_type' => $productData['type'] ?? 'product',
            'description'  => $productData['description'] ?? '',
            'quantity'     => (int) ($productData['quantity'] ?? 0),
        ];

        return response()->json([
            'product_sample' => $productData,
            'payload_to_send' => $payload,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => class_basename($e->getFile()),
            'line' => $e->getLine(),
        ], 500);
    }
});
