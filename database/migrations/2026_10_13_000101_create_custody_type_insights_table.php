<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تحليلُ الذكاء لكلِّ صنفِ عهدة** (`CustodyInsights`).
 *
 * صفٌّ واحدٌ لكلِّ `(type_code, scope_key)`: كودُ الصنف (SV · LT …) ومفتاحُ النطاق الذي حُسب عليه
 * التحليل — اليومَ `*` وحدَه (كلُّ عهد الصنف بهويّة الخدمة، ويُعرض لمن يرى الصنفَ كلَّه فقط)؛ والعمودُ
 * قائمٌ ليُضاف تحليلٌ لكلِّ شركةٍ لاحقاً **بلا هجرة**.
 *
 * `facts_hash` بصمةُ الحقائق التي بُني عليها **آخرُ تحليلٍ ناجح** — لا يُعاد التوليدُ ما لم تتغيّر
 * (أو يُطلب يدوياً). و`attempt_hash`/`attempted_at`/`status`/`error_code` لآخر محاولة: **الإخفاقُ لا
 * يمحو التحليلَ السابق** — `analysis` و`model` و`generated_at` تبقى لآخر نجاح.
 *
 * إضافيّةٌ بحتة (CLAUDE.md): جدولٌ جديد لا يمسّ غيرَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custody_type_insights')) return;

        Schema::create('custody_type_insights', function (Blueprint $t) {
            $t->id();
            $t->string('type_code', 20);
            $t->string('scope_key', 64)->default('*');
            $t->string('facts_hash', 64)->nullable();
            $t->string('attempt_hash', 64)->nullable();
            $t->longText('analysis')->nullable();          // JSON: summary · risks · recommendations · data_gaps
            $t->string('model', 120)->nullable();
            $t->string('status', 12)->default('ok');         // ok | failed (آخرُ محاولة)
            $t->string('error_code', 60)->nullable();
            $t->unsignedInteger('items')->default(0);      // عددُ العهد التي حُسبت عليها الحقائق
            $t->timestamp('generated_at')->nullable();     // آخرُ نجاح
            $t->timestamp('attempted_at')->nullable();     // آخرُ محاولة
            $t->timestamps();

            $t->unique(['type_code', 'scope_key'], 'custody_type_insights_uq');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هجرةَ مدمِّرة
    }
};
