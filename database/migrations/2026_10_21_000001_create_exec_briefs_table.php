<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **الموجزُ التنفيذيّ الأسبوعيّ** (`App\Support\Ai\Brief\ExecBrief` — docs/ai-hub/47 §العمود و — المرحلة ٧).
 * صفٌّ لكلِّ مالكٍ في كلِّ أسبوع: خمسةُ أمورٍ تحتاج قرارَه، كلٌّ بسببه والقرارِ المطلوب ورابطِه.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('exec_briefs')) return;

        Schema::create('exec_briefs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('week', 10);                              // 2026-W40
            $t->json('items')->nullable();                       // [{title, why, decision, sev, url}]
            $t->unsignedInteger('signals')->default(0);          // ما قُرئ من الإشارات
            $t->string('status', 10)->default('ok');
            $t->string('error_code', 60)->nullable();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'week'], 'exec_briefs_user_week_uq');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
