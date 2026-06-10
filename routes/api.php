<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::post('/salla/webhook', [WebhookController::class, 'handle'])
    ->name('salla.webhook');

Route::get('/health', fn() => response()->json(['status' => 'ok', 'timestamp' => now()]));

// Temporary migration runner — protected by secret key
Route::get('/admin/run-migrate/{source}/{target}', function (string $source, string $target) {
    if (request('secret') !== env('APP_KEY')) {
        return response()->json(['error' => 'unauthorized'], 403);
    }
    ob_start();
    Artisan::call('salla:migrate', ['source_store_id' => $source, 'target_store_id' => $target]);
    $output = ob_get_clean() . Artisan::output();
    return response()->json(['output' => $output, 'status' => 'done']);
});
