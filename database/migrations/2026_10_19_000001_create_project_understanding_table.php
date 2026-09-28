<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **ملفُّ فهم المشروع** (`App\Support\Ai\Understanding\ProjectUnderstanding` — docs/ai-hub/47 §العمود ج — المرحلة ٥).
 *
 * صفٌّ لكلِّ مشروع: أقسامُ الفهم (ما هو ولمن · الوعدُ مقابل الواقع · المراحل · المخاطر · الفجوات…) واقتراحاتٌ
 * عمليّة تحت الملخّص، وبصمةُ المصادر التي بُني منها (`sources_hash`) — فلا يُعاد بناؤه ما لم تتغيّر مصادرُه
 * أو يمضِ أسبوع. و`uses_docs`: بُني من وثائق وحدة الملفات ⇒ لا يُعرض إلا لمن يرى تلك الوحدة.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_understanding')) return;

        Schema::create('project_understanding', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->unique();
            $t->json('sections')->nullable();
            $t->json('suggestions')->nullable();                 // [{id, type, text, basis: [...]}]
            $t->json('dismissed')->nullable();                   // {suggestion_id: {by, at, reason}}
            $t->json('converted')->nullable();                   // {suggestion_id: {by, at}}
            $t->boolean('uses_docs')->default(false);
            $t->string('sources_hash', 64)->nullable();
            $t->string('model', 190)->nullable();
            $t->string('status', 10)->default('ok');             // ok · failed
            $t->string('error_code', 60)->nullable();
            $t->uuid('usage_event_id')->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamp('attempted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
