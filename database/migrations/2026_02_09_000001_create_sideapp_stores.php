<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مخزن التطبيقات الجانبية المعزولة (QuoteFlow وأشباهه القادمة).
 * صفٌّ واحد لكل تطبيق يحمل حالته كاملةً JSON — لا علاقة له بجداول النظام،
 * فالتطبيق الجانبي جزيرة: يدخل بصلاحية، ويحفظ هنا، ولا يلمس شيئاً آخر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sideapp_stores', function (Blueprint $t) {
            $t->string('app', 40)->primary();
            $t->longText('data');
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sideapp_stores');
    }
};
