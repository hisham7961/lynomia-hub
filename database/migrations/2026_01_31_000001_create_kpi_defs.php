<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** مؤشرات مخصصة: معادلة مُهيكَلة آمنة (لا نص حر يُقيَّم) فوق وحدات النظام */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_defs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 190);
            $t->string('unit', 30)->nullable();      // وحدة العرض: د.ك، ٪، عدد…
            $t->json('formula');                     // {a:{agg,module,col,st}, b:{...}, combine}
            $t->decimal('target', 16, 2)->nullable();
            $t->string('good', 10)->default('up');   // up: الأعلى أفضل · down: الأقل أفضل
            $t->integer('sort')->default(0);
            $t->uuid('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_defs');
    }
};
