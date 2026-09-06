<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **أثرُ إبطال الجلسات + أوّلُ ظهور العنوان** (الطور ٤ · WP-4.4 · spec §2.6/§2.8 · إضافةً لا كسراً).
 *
 * `sessions_log.revoked` منطقيٌّ **يخلط الخروجَ الطوعيّ بالإنهاء الإداريّ**:
 * logout يكتبه وإبطالُ المالك يكتبه — فالمحقّق لا يعرف مَن أخرج الجهازَ ولماذا.
 * الأعمدةُ الجديدة تفكّ الخلط: الإنهاءُ الإداريّ/الذاتيّ (سكّة `Sessions` الواحدة)
 * يختم `revoked_at/by/reason`، والخروجُ الطبيعيّ يبقى وسماً بلا ختم.
 *
 * و`user_ips.first_seen_at` **بلا ملءٍ رجعيّ** (critic): لا مصدرَ صادقاً لما قبل
 * العمود — الصفوفُ القديمة تبقى فارغةً، وأوّلُ ظهورٍ تقريبيٌّ يُشتقّ عند القراءة
 * من `audits(created_at, ip)` بوسم «تقريبيّ» صريح (قارئُ SecurityRadar::intel).
 *
 * الأعمدةُ كلُّها اختيارية والكتّابُ يمرّون بحارس `hub_has_col` — نشرُ الكود قبل
 * الهجرة يُنقص ميزةً (بلا ختمِ أثرٍ) ولا يُطفئ إنهاءَ الجلسات نفسَه.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions_log')) {
            Schema::table('sessions_log', function (Blueprint $t) {
                // متى أُنهيت إدارياً؟ (الخروجُ الطوعيّ لا يكتبها — فكُّ خلطِ revoked)
                if (! Schema::hasColumn('sessions_log', 'revoked_at')) $t->timestamp('revoked_at')->nullable();
                // مَن أنهاها؟ (المنفّذُ نفسُه في الإنهاء الذاتيّ)
                if (! Schema::hasColumn('sessions_log', 'revoked_by')) $t->uuid('revoked_by')->nullable();
                // لماذا؟ — نصٌّ قصير بعرضٍ صريح (mb_substr عند الكاتب في Sessions)
                if (! Schema::hasColumn('sessions_log', 'revoke_reason')) $t->string('revoke_reason', 120)->nullable();
            });
        }

        if (Schema::hasTable('user_ips')) {
            Schema::table('user_ips', function (Blueprint $t) {
                // يُملأ للصفوف الجديدة فقط حين يوصَل كاتبُ الدخول (ملاحظةُ تكامل —
                // LoginSentry خارجُ حزمة WP-4.4) — والقديمُ يبقى null بلا اختلاقِ تاريخ
                if (! Schema::hasColumn('user_ips', 'first_seen_at')) $t->timestamp('first_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sessions_log')) {
            Schema::table('sessions_log', function (Blueprint $t) {
                foreach (['revoked_at', 'revoked_by', 'revoke_reason'] as $c) {
                    if (Schema::hasColumn('sessions_log', $c)) $t->dropColumn($c);
                }
            });
        }
        if (Schema::hasTable('user_ips')) {
            Schema::table('user_ips', function (Blueprint $t) {
                if (Schema::hasColumn('user_ips', 'first_seen_at')) $t->dropColumn('first_seen_at');
            });
        }
    }
};
