<?php

use App\Http\Controllers\WebhookController;
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
    Artisan::call('salla:migrate', ['source_store_id' => $source, 'target_store_id' => $target]);
    return response()->json(['output' => Artisan::output(), 'status' => 'done']);
});
