<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Salla Webhooks Route
|--------------------------------------------------------------------------
|
| نقطة الاستقبال الوحيدة للـ Webhooks من سلة.
|
| هذا الـ Route يجب أن يُستثنى من CSRF verification (مُضاف في
| app/Http/Middleware/VerifyCsrfToken.php → $except أو في
| bootstrap/app.php إذا كنت تستخدم Laravel 11+).
|
| URL المسجَّل في بوابة الشركاء: https://yourdomain.com/api/salla/webhook
|
*/

Route::post('/salla/webhook', [WebhookController::class, 'handle'])
    ->name('salla.webhook');
