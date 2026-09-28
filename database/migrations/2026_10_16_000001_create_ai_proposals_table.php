<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **طابورُ اقتراحات الذكاء** (`App\Support\Ai\Proposals\ProposalService` — docs/ai-hub/47 §٢ العمود أ).
 *
 * الذكاءُ لا يكتب في سجلٍّ أعماليّ: يقترح تغييراً لحقلٍ واحدٍ من قائمةٍ مغلقة، مع دليلٍ مقتبسٍ
 * يتحقّق الخادمُ من وجوده حرفياً في مصدره — والإنسانُ يعتمده أو يرفضه. `current_value` لقطةُ
 * الحقل عند الاقتراح: إن تغيّر قبل الاعتماد صار الاقتراحُ `superseded` ولا يُطبَّق على قيمةٍ لم يرَها.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_proposals')) return;

        Schema::create('ai_proposals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('kind', 40);                              // ProposalService::KINDS
            $t->string('module', 40);
            $t->uuid('record_id');
            $t->string('field', 60);                             // مفتاحُ الحقل في السجلّ (لا العمود)
            $t->text('current_value')->nullable();
            $t->text('proposed_value')->nullable();
            $t->text('applied_value')->nullable();               // ما طُبِّق فعلاً (قد يعدّله المعتمِد)
            $t->text('rationale')->nullable();
            $t->json('evidence')->nullable();                    // [{module, record_id, quote}]
            $t->unsignedTinyInteger('confidence')->nullable();   // 0-100
            $t->string('source', 40)->nullable();                // الغرضُ المُنتِج (progress · followup …)
            $t->string('status', 12)->default('open');           // open | applied | rejected | expired | superseded
            $t->uuid('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('reject_reason', 300)->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();

            $t->index(['status', 'module', 'record_id'], 'ai_proposals_open_idx');
            $t->index(['kind', 'status', 'decided_at'], 'ai_proposals_accuracy_idx');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
