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
