<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-5.2) تطبيعُ قيد التدقيق — أعمدةٌ مخزّنة **خارج البصمة** (القرار ق٢).
 *
 * التصنيفُ كان يُشتقّ وقتَ القراءة من ١٢٠ صيغةَ فعلٍ عربيةٍ حرّة — يصلح لصفحةٍ
 * واحدة ولا يصلح للعدّ والتصفية على حجمٍ حقيقيّ. فتُخزَّن الفئةُ والشدّةُ
 * والمصدرُ والمآلُ ونوعُ الفاعل ومعرّفُ الجلسة مع كل قيدٍ **جديد**، وتبقى كلُّها
 * خارج `AuditEntry::SEALED` كـ`company_id`/`request_id` فلا تمسّ الختمَ ولا
 * تُقدَّم أبداً بوصفها «مختومة». الصفوفُ القديمة تُصنَّف وقتَ العرض
 * (`hub_audit_class`) — **بلا ملءٍ رجعيّ** يكتب جماعياً على جدولٍ مختوم.
 *
 * كتلُ الأعمدة **حرفيّةٌ لا حلقة** (critic #35): `hub_col_widths()` يقرأ مصدرَ
 * الهجرات ويطابق `Schema::table('audits'` — عمودٌ داخل foreach غيرُ مرئيٍّ
 * لحرّاس العرض. إضافيةٌ ومحروسة: تُتخطّى إن وُجد العمود أو غاب الجدول.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audits')) return;

        // الفئة: كودٌ أمنيّ قانونيّ (أطولُها SECURITY_POLICY_CHANGED = ٢٣) أو فئةُ نطاق
        if (! Schema::hasColumn('audits', 'category')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->string('category', 24)->nullable();
            });
        }
        // الشدّة: info · notice · warning · high — من تصنيف SecurityEvents نفسِه
        if (! Schema::hasColumn('audits', 'severity')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->string('severity', 12)->nullable();
            });
        }
        // المصدر: web · api · console · hook — من Api::requestSource (الطور ١)
        if (! Schema::hasColumn('audits', 'source')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->string('source', 12)->nullable();
            });
        }
        // المآل: success · failed · denied
        if (! Schema::hasColumn('audits', 'outcome')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->string('outcome', 12)->nullable();
            });
        }
        // نوعُ الفاعل: user · system · guest
        if (! Schema::hasColumn('audits', 'actor_type')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->string('actor_type', 12)->nullable();
            });
        }
        // جلسةُ الكاتب: session('hub.sl') — صفُّ sessions_log الموضوع عند الدخول
        if (! Schema::hasColumn('audits', 'session_id')) {
            Schema::table('audits', function (Blueprint $t) {
                $t->uuid('session_id')->nullable();
            });
        }

        // فهرسا العدّ والتحقيق: «كل أفعال فئةٍ في مدى» و«ماذا فعلت هذه الجلسة؟»
        $add = function (array $cols, string $name) {
            foreach ($cols as $c) if (! Schema::hasColumn('audits', $c)) return;
            try {
                if (Schema::hasIndex('audits', $name)) return;
            } catch (\Throwable $e) {
            }
            try {
                Schema::table('audits', fn (Blueprint $t) => $t->index($cols, $name));
            } catch (\Throwable $e) {
                // فهرسٌ قائمٌ باسمٍ آخر — لا نكسر الترحيل
            }
        };
        $add(['category', 'created_at'], 'audits_category_created_idx');
        $add(['session_id'], 'audits_session_idx');
    }

    public function down(): void
    {
        // إضافيةٌ فقط
    }
};
