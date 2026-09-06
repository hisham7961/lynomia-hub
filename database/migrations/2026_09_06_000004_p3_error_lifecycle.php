<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **دورةُ حياة الخطأ (WP-3.3) — إضافةً لا كسراً.**
 *
 * `error_events` كان يعرف ثلاث حالاتٍ بلا ذاكرة: مَن حلّ العطل؟ بأيّ نسخة؟
 * هل عاد بعد الحل (انحدار)؟ ولماذا أُهمل؟ ومَن يعمل عليه الآن؟ — كلُّ هذه
 * أسئلةُ §44 وكانت بلا أعمدة. الأعمدةُ كلُّها اختيارية: الصفوفُ القديمة تبقى
 * كما هي، والكتّابُ يمرّون بحارس `hasColumn` فلا يسقط التقاطٌ قبل الترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('error_events')) return;

        Schema::table('error_events', function (Blueprint $t) {
            // الإسناد: من يعمل عليه؟ بأيّ أولوية (مفردات أولوية المهام)؟ إلى متى؟
            if (! Schema::hasColumn('error_events', 'assignee_id')) $t->uuid('assignee_id')->nullable()->index();
            if (! Schema::hasColumn('error_events', 'priority')) $t->string('priority', 12)->nullable();           // عاجلة|عالية|متوسطة|منخفضة
            if (! Schema::hasColumn('error_events', 'due_at')) $t->date('due_at')->nullable();
            // الحل: متى ومَن وبأيّ نسخةٍ حُلّ؟
            if (! Schema::hasColumn('error_events', 'resolved_at')) $t->timestamp('resolved_at')->nullable();
            if (! Schema::hasColumn('error_events', 'resolved_by')) $t->uuid('resolved_by')->nullable();
            if (! Schema::hasColumn('error_events', 'resolved_release')) $t->string('resolved_release', 20)->nullable();
            // الانحدار: عاد بعد أن حُسب محلولاً — متى وبأيّ نسخة؟
            if (! Schema::hasColumn('error_events', 'regressed_at')) $t->timestamp('regressed_at')->nullable();
            if (! Schema::hasColumn('error_events', 'regression_release')) $t->string('regression_release', 20)->nullable();
            // الإهمال قرارٌ مسبَّب لا نسيان: السبب وصاحبه
            if (! Schema::hasColumn('error_events', 'ignored_reason')) $t->string('ignored_reason', 300)->nullable();
            if (! Schema::hasColumn('error_events', 'ignored_by')) $t->uuid('ignored_by')->nullable();
            // الكتم المؤقّت للإشعار (يُحترم في ErrorLog — حزمة 3.5) وربط الحادثة والملاحظات
            if (! Schema::hasColumn('error_events', 'muted_until')) $t->timestamp('muted_until')->nullable();
            if (! Schema::hasColumn('error_events', 'incident_id')) $t->uuid('incident_id')->nullable();
            if (! Schema::hasColumn('error_events', 'notes')) $t->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('error_events')) return;
        Schema::table('error_events', function (Blueprint $t) {
            foreach (['assignee_id', 'priority', 'due_at', 'resolved_at', 'resolved_by', 'resolved_release',
                      'regressed_at', 'regression_release', 'ignored_reason', 'ignored_by',
                      'muted_until', 'incident_id', 'notes'] as $c) {
                if (Schema::hasColumn('error_events', $c)) $t->dropColumn($c);
            }
        });
    }
};
