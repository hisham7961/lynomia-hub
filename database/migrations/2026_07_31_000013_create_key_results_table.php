<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٦ — النتائج الرئيسية: الوحدة اسمها OKR وفيها O بلا KR — هدفٌ بلا
 * نتائج قابلة للقياس ليس OKR، و`progress` رقمٌ يدويٌّ منفصل عن الواقع رغم
 * أن محرك المؤشرات (`hub_kpi_value`) مكتوبٌ وجاهز يحسب أي تجميعة على أي
 * وحدة باحترام الصلاحيات والنطاق. النتيجة تُقاس يدوياً أو تُشتق من مؤشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_results', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->uuid('objective_id')->index();
            $t->uuid('kpi_id')->nullable()->index();       // مؤشر يحسب القيمة الحالية آلياً
            $t->decimal('start_value', 16, 3)->nullable();
            $t->decimal('target_value', 16, 3)->nullable();
            $t->decimal('current_value', 16, 3)->nullable();
            $t->string('unit', 30)->nullable();
            $t->decimal('weight', 16, 3)->nullable();      // وزن النتيجة داخل الهدف
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_results');
    }
};
