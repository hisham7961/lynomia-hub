<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نواة المقاييس الزمنية — سلسلةٌ عامة لأي وحدةٍ وأي مقياس.
 * كل رقمٍ متحرّك في النظام كان لقطةً واحدة تُدهس بالتي بعدها: «المتابعون
 * ١٢٠٠٠» بلا ذاكرةٍ لما كانوا أمس، فلا نمو ولا اتجاه ولا رسم. تُغذّى آلياً
 * من n8n أو أي مصدر عبر مفاتيح API القائمة، وتُقرأ بمساعِدات موحّدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_points', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('module', 40);                  // مفتاح الوحدة في سجل hub.modules
            $t->uuid('record_id');                     // السجل المقيس
            $t->string('metric', 40);                  // followers | downloads | rating | ...
            $t->decimal('value', 18, 4);
            $t->timestamp('at');                       // زمن القياس لا زمن الإدخال
            $t->string('source', 24)->default('manual');   // manual | n8n | api | sync
            $t->json('meta')->nullable();
            $t->timestamps();

            // نقطةٌ واحدة لكل (وحدة، سجل، مقياس، لحظة) — إعادة الدفع تُحدِّث لا تكرّر
            $t->unique(['module', 'record_id', 'metric', 'at'], 'metric_points_point_unique');
            $t->index(['module', 'record_id', 'metric', 'at'], 'metric_points_series_idx');
            $t->index(['metric', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_points');
    }
};
