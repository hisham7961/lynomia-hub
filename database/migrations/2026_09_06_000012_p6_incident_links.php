<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **الأدلّةُ المرتبطة بالحادثة (WP-6.2) — جدولُ وصلٍ خفيف.**
 *
 * السببُ الوجيه لجدولٍ جديد: `audits` **مختومةٌ** فلا يُضاف إليها
 * `incident_id`، والأحداثُ الأمنية **مشتقّةٌ** بلا جدولٍ ولا معرّفٍ ثابت —
 * فالربطُ بين الحادثة وأدلّتها (خطأ/قيد تدقيق/حدث أمنيّ/طلب/تنبيه/مهمة/نشر/
 * ملاحظة) يحتاج صفَّ وصلٍ خاصّاً به. وما له عمودُ مرجعٍ سلفاً
 * (`deployments.incident_id`) **لا يأخذ صفَّ وصل** — يقرؤه فرعُ الحوادث في
 * `hub_timeline` عبر العلاقة القائمة مباشرة.
 *
 * الفريدُ **منطقيّ** لا قيدَ قاعدة: (incident_id, kind, ref) يفرضه الكاتبُ
 * (`IncidentLinkController`) تحديثاً لا تكراراً — لأنّ `ref` تكون فارغةً
 * للملاحظات الحرّة فيستحيل قيدُ unique فوق NULL بدلالةٍ واحدة على المحرّكين.
 *
 * كتلةٌ **حرفيّة** (critic #35): `hub_col_widths()` يقرأ مصدرَ الهجرة، وكلُّ
 * عمودٍ نصّي بعرضٍ صريح — و`summary` (٣٠٠) يقصّه كاتبُه بـ`mb_substr`
 * (critic #36 — والزوجُ محروسٌ في `ColumnFitsItsWriterTest`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('incident_links')) return;

        Schema::create('incident_links', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('incident_id');                    // الحادثة الأم
            $t->string('kind', 24);                     // error|audit|security|request|alert|task|deploy|note
            $t->string('module', 60)->nullable();       // وحدةُ السجل المربوط (لسجلّات الوحدات)
            $t->uuid('record_id')->nullable();          // معرّفُ السجل المربوط
            $t->string('ref', 120)->nullable();         // بصمةُ خطأ / request_id / audits.id / مفتاحُ تنبيه
            $t->string('summary', 300);                 // ملخّصٌ للبشر — mb_substr عند الكاتب
            $t->uuid('by')->nullable();                 // من ربط
            $t->dateTime('created_at');
            $t->index(['incident_id', 'created_at'], 'il_incident_at_idx');   // شاشةُ الحادثة
            $t->index(['module', 'record_id'], 'il_module_record_idx');       // «هل هذا السجلُ دليلٌ في حادثة؟»
            $t->index('ref', 'il_ref_idx');                                   // الربطُ العكسيّ بالبصمة/المعرّف
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_links');
    }
};
