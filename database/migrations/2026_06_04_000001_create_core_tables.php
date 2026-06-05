<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الجداول الأساسية لتطبيق نسخ ومزامنة سلة.
 *
 * الجداول:
 *  1. salla_stores       — المتاجر المثبَّل عليها التطبيق + توكناتها
 *  2. entity_mappings    — تطابق المعرّفات (العمود الفقري)
 *  3. sync_jobs          — سجل عمليات المزامنة
 */
return new class extends Migration
{
    public function up(): void
    {
        // ======================================================
        // 1) المتاجر + التوكنز
        // ======================================================
        Schema::create('salla_stores', function (Blueprint $table) {
            $table->id();
            // معرّف المتجر في سلة (ليس الـ id الداخلي)
            $table->unsignedBigInteger('store_id')->unique();
            $table->string('store_name')->nullable();
            // التوكن يصل من حدث app.store.authorize
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            // الـ expires في الحدث يأتي كـ unix timestamp — نحوّله لـ datetime
            $table->timestamp('token_expires_at')->nullable();
            // الدور: مصدر أو هدف أو غير محدد (يُضبط بأمر salla:pair)
            $table->enum('role', ['source', 'target', 'unassigned'])->default('unassigned');
            $table->timestamps();
        });

        // ======================================================
        // 2) تطابق المعرّفات — قلب المشروع
        //    كل عنصر يُنشأ في الهدف يأخذ id مختلف؛ هذا الجدول يربطهم.
        // ======================================================
        Schema::create('entity_mappings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_store_id');
            $table->unsignedBigInteger('target_store_id');
            // النوع: category | product | brand | customer_group ...
            $table->string('entity_type', 50);
            $table->string('source_id');    // المعرّف في المصدر
            $table->string('target_id');    // المعرّف الجديد في الهدف
            // للمنتجات: مفتاح ثابت يضمن idempotency عبر إعادة التشغيل
            $table->string('sku')->nullable();
            $table->timestamps();

            // منع تكرار نفس العنصر لنفس الزوج
            $table->unique(
                ['source_store_id', 'target_store_id', 'entity_type', 'source_id'],
                'uniq_entity_mapping'
            );
            // فهرس للبحث السريع بالـ SKU
            $table->index(['source_store_id', 'target_store_id', 'entity_type', 'sku'], 'idx_mapping_sku');
        });

        // ======================================================
        // 3) سجل عمليات المزامنة
        // ======================================================
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_store_id');
            $table->unsignedBigInteger('target_store_id');
            // initial_migration | webhook_event
            $table->string('type', 30);
            // category | product | null (لو العملية تشمل الكل)
            $table->string('entity_type', 50)->nullable();
            // pending | running | done | failed
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            // تفاصيل/أخطاء: { errors: [{id, msg}], error: "..." }
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['source_store_id', 'target_store_id', 'status'], 'idx_sync_jobs_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_jobs');
        Schema::dropIfExists('entity_mappings');
        Schema::dropIfExists('salla_stores');
    }
};
