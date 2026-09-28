<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **مؤشّراتٌ ذكيّة** (`App\Support\Ai\Kpi\KpiInsights` — docs/ai-hub/47 §٢ العمود هـ — المرحلة ٦).
 *
 * صفٌّ لكلِّ مؤشّرٍ خارج هدفه أو له تاريخٌ يكفي لاقتراح هدف (`kind=kpi`): تفسيرُ الانحراف، وإجراءاتٌ مقترحة،
 * وهدفٌ مقترحٌ بسببه يعتمده صاحبُ صلاحيّة المؤشّرات أو يرفضه. وصفٌّ واحدٌ (`kind=catalog`) لمؤشّراتٍ ناقصة
 * يقترحها الذكاء. القيمةُ الفعليّة للمؤشّر **محسوبةٌ من البيانات ولا تُمسّ** — التعديلُ هدفٌ لا رقم.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_insights')) return;

        Schema::create('kpi_insights', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 10)->default('kpi');              // kpi · catalog
            $t->uuid('kpi_id')->nullable()->unique();
            $t->text('explanation')->nullable();
            $t->json('actions')->nullable();                     // [نصّ]
            $t->json('target')->nullable();                      // {value, why}
            $t->json('target_decision')->nullable();             // {action: applied|rejected, by, at, value}
            $t->json('ideas')->nullable();                       // catalog: [{name, why, module}]
            $t->string('hash', 64)->nullable();
            $t->string('status', 10)->default('ok');
            $t->string('error_code', 60)->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();

            $t->index(['kind', 'generated_at'], 'kpi_insights_kind_idx');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
