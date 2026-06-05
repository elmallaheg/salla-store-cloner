<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول أزواج المتاجر: يحدّد من هو المصدر ومن هو الهدف لكل عملية مزامنة.
 *
 * لماذا جدول منفصل؟
 *  - تطبيق واحد قد يدير عدة أزواج (متجر A → B وكذلك B → C)
 *  - كل زوج له إعداداته الخاصة (ما الذي يُزامَن)
 *  - يُعالج سيناريو تاجر واحد أو تاجرين مختلفين بوضوح
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_pairs', function (Blueprint $table) {
            $table->id();
            // store_id (ليس id الداخلي) للمتجرين
            $table->unsignedBigInteger('source_store_id');
            $table->unsignedBigInteger('target_store_id');
            // هل المتجران لنفس التاجر؟ (يُؤثر على ما يُسمح بمزامنته)
            $table->boolean('same_merchant')->default(false);
            // ماذا نزامن؟
            $table->boolean('sync_categories')->default(true);
            $table->boolean('sync_products')->default(true);
            // العملاء: معطّل دائمًا ما لم يُفعَّل صراحةً + same_merchant=true (PDPL)
            $table->boolean('sync_customers')->default(false);
            // هل هذا الزوج نشط؟ (يمكن تعطيل زوج دون حذفه)
            $table->boolean('active')->default(true);
            $table->timestamps();

            // زوج واحد فقط لكل مصدر-هدف
            $table->unique(['source_store_id', 'target_store_id'], 'uniq_store_pair');
            $table->index('source_store_id', 'idx_pair_source');
            $table->index('target_store_id', 'idx_pair_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pairs');
    }
};
