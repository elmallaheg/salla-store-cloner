<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_pair_id');
            $table->string('plan_slug');
            $table->unsignedInteger('max_products');
            $table->unsignedInteger('price_sar');
            $table->string('status')->default('trial'); // trial|active|expired|cancelled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamps();

            $table->foreign('store_pair_id')
                ->references('id')
                ->on('store_pairs')
                ->onDelete('cascade');

            $table->index(['store_pair_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
