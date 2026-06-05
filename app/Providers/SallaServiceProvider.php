<?php

namespace App\Providers;

use App\Services\Salla\SallaClient;
use App\Services\Salla\TokenManager;
use Illuminate\Support\ServiceProvider;

/**
 * تسجيل خدمات سلة في حاقن الاعتماديات (DI Container).
 *
 * لا ننسى تسجيل هذا الـ Provider في:
 *  - Laravel 10: config/app.php → providers[]
 *  - Laravel 11+: bootstrap/providers.php
 */
class SallaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // TokenManager: singleton — نفس الـ instance طوال عمر الطلب
        $this->app->singleton(TokenManager::class);

        // SallaClient: يحتاج store + tokenManager → يُبنى يدويًا في كل استخدام
        // لذلك لا نربطه كـ singleton هنا — نستخدم app(SallaClient::class, ['store' => $store])
    }

    public function boot(): void
    {
        // تسجيل Artisan Commands إضافية (اختياري — سلة CLI قد يسجّلها تلقائيًا)
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\RunInitialMigration::class,
                \App\Console\Commands\PairStores::class,
            ]);
        }
    }
}
