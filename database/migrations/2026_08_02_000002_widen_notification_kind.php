<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **قواعدُ التنبيه لا تُشعِر أحداً في الإنتاج — بحرفٍ واحد.**
 *
 * `notifications_hub.kind` عرضُه ٤٠، و`HubAutomation` يكتب فيه
 * `'rule:' . $rule->id` — خمسةُ أحرفٍ ومعرّفٌ من ستةٍ وثلاثين = **٤١**.
 *
 * على SQLite يمرّ (لا تفرض عرضاً) فالحزمةُ خضراء؛ وعلى MySQL:
 * `Data too long for column 'kind'` — فيسقط **كلُّ إشعارٍ من قاعدة تنبيه**.
 * أي أنّ النظام الذي بُني ليقول «هذا تجاوز حدّه» كان صامتاً في الإنتاج تماماً،
 * ولا أحد يعلم: الاستثناء يُلتقط في مركز الأخطاء ولا يصل صاحبه.
 *
 * ١٢٠ حرفاً تسع `rule:` ومعرّفاً وما قد يُضاف من بادئاتٍ لاحقاً.
 */
return new class extends Migration
{
    /*
     * **بأسماءٍ صريحة لا بحلقة**: حارسُ عرض الأعمدة يقرأ **مصدر** الهجرات،
     * و`Schema::table($t, …)` باسمٍ متغيّر لا يراه — فيبقى العمودُ في نظره
     * أربعين بعد توسيعه. ما لا يُقرأ من المصدر لا يُحرَس.
     */
    public function up(): void
    {
        if (Schema::hasColumn('notifications_hub', 'kind')) {
            Schema::table('notifications_hub', fn (Blueprint $t) => $t->string('kind', 120)->change());
        }
        if (Schema::hasTable('outbox') && Schema::hasColumn('outbox', 'kind')) {
            Schema::table('outbox', fn (Blueprint $t) => $t->string('kind', 120)->change());
        }
    }

    public function down(): void
    {
        // لا تضييق: تضييقُ عمودٍ يقطع بياناتٍ قائمة — والترحيل إضافةٌ لا كسر
    }
};
