<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **نتائجُ المدقّق** (`docs/ai-hub/46-ai-roadmap.md` §٣.٣).
 *
 * ولماذا جدولٌ والإشاراتُ الأخرى محسوبةٌ حيّاً (`hub_recommendations`)؟ لأنّ نتيجةَ
 * الكاشفِ الذكيّ **مكلفة** — نداءٌ مدفوعٌ لا استعلام — فلا تُحسب عند كلِّ عرض.
 * لكنّ الجدولَ **ليس مخزنَ إشارةٍ ثانياً**: يُعرَض عبر `ActionCenter` بالشكل القائم،
 * والإقرارُ والتأجيلُ والرفضُ في `signal_states` القائم — ولا يُكرَّر هنا عمودُ حالةٍ لها.
 *
 *  · `dedup_key` هويّةُ **الشرط** لا السجلّ: «هذا العائقُ عند هذا الموظّف»، «هذه المهمّةُ
 *    عند هذا الموظّف». والموضوعُ (`subject_*`) قد ينتقل إلى أحدثِ تقرير — والهويّةُ ثابتة،
 *    فيبقى مفتاحُ الإشارةِ ويبقى تصرّفُ المدير بها (تأجيلٌ أو رفض) ما بقي الشرط.
 *  · `status` حالةُ **الشرط** لا تصرّفِ المستخدم: `open` ما دام الكاشفُ يرصده،
 *    و`resolved` حين زال (حلٌّ تلقائيٌّ كالإشارات المحسوبة).
 *  · `evidence` السجلّاتُ التي بُنيت عليها النتيجة `[{module, id}]` — **والعرضُ يُعاد
 *    تنطيقُه عليها للمشاهد**: من لا يرى سجلّاً منها لا يرى النتيجة.
 *  · `fields` الحقولُ التي قرأها الكاشف `{module: [key…]}` — ومن حُجب عنه حقلٌ منها
 *    لا يرى النتيجة (فالملخّصُ قد يحمل قيمتَه).
 *  · `fingerprint` بصمةُ المدخلات: لا يُعاد تحليلُ ما لم يتغيّر (ولا يُدفع له مرّتين).
 *  · لا نصَّ مطالبةٍ ولا ردَّ نموذجٍ خامٍ هنا — الملخّصُ المنقَّحُ وحده.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_findings')) return;

        Schema::create('ai_findings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('detector', 40);
            $t->char('dedup_key', 40);                           // sha1 هويّةِ الشرط
            $t->string('source', 8)->default('rule');            // rule | ai
            $t->string('severity', 8)->default('info');          // high | medium | info
            $t->string('subject_module', 40);
            $t->uuid('subject_id');
            $t->uuid('subject_user_id')->nullable()->index();    // صاحبُ العمل — للحكم على العرض لا للكشف
            $t->uuid('company_id')->nullable()->index();
            $t->json('evidence');
            $t->json('fields');
            $t->string('summary', 600);
            $t->string('suggestion', 600)->nullable();
            $t->char('fingerprint', 64);
            $t->string('status', 10)->default('open');           // open | resolved
            $t->uuid('usage_event_id')->nullable();              // النداءُ المدفوع إن وُجد
            $t->timestamp('detected_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();

            // نتيجةٌ واحدةٌ لكلِّ كاشفٍ على كلِّ شرط — تُحدَّث ولا تتكرّر
            $t->unique(['detector', 'dedup_key'], 'ai_findings_dedup_uq');
            $t->index(['subject_module', 'subject_id'], 'ai_findings_subject_idx');
            $t->index(['status', 'severity', 'id'], 'ai_findings_open_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_findings');
    }
};
