<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **سجلُّ الالتزامات — المتابِع** (`App\Support\Ai\FollowUp` — docs/ai-hub/47 §٢ العمود ب).
 *
 * ما قاله موظّفٌ في تقريره أنّه سيفعله («غداً سأنهي ربط البوابة»)، باقتباسٍ يتحقّق الخادمُ من وجوده
 * حرفياً في التقرير، وموعدٍ مقدَّر. يُغلق بالدليل (مهمّةٌ مرتبطةٌ أُغلقت، أو تقريرٌ لاحقٌ يذكر إنجازه)،
 * أو يُسأل صاحبُه «ماذا حدث؟» بعد المهلة، ثم يُصعَّد إلى مديره المباشر إن لم يردّ.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_commitments')) return;

        Schema::create('ai_commitments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id')->index();
            $t->string('what', 300);
            $t->text('quote')->nullable();
            $t->string('source_module', 20)->default('updates');
            $t->uuid('source_id');
            $t->uuid('project_id')->nullable();
            $t->uuid('task_id')->nullable();
            $t->date('said_on');
            $t->date('due_on');
            $t->string('status', 12)->default('open');            // open | done | dropped | dismissed | escalated
            $t->unsignedTinyInteger('asked_count')->default(0);
            $t->timestamp('asked_at')->nullable();
            $t->string('answer', 12)->nullable();                 // done | working | postponed | dropped | wrong
            $t->string('answer_note', 300)->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('closed_by', 10)->nullable();              // ai | user | manager
            $t->string('evidence_module', 20)->nullable();
            $t->uuid('evidence_id')->nullable();
            $t->text('evidence_quote')->nullable();
            $t->string('dedupe', 64)->unique();
            $t->timestamps();

            $t->index(['status', 'due_on'], 'ai_commitments_due_idx');
            $t->index(['user_id', 'status'], 'ai_commitments_user_idx');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
