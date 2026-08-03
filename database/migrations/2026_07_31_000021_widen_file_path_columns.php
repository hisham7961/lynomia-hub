<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «خطأ غير متوقع» عند رفع صورةٍ أو ملف — في **كل** وحدة لا في التصاميم وحدها.
 *
 * أعمدة حقول الملفات والصور أُعلنت `uuid` (‏char(36) على MySQL) على نية تخزين
 * معرّف مرفق، بينما المتحكّم يخزّن فيها **مسار الملف** (`hub/xxxxxxxx….jpg`،
 * نحو ٤٥ حرفاً) والعرضُ يقرأ المسار. فعلى MySQL الصارم ترمي القاعدة
 * «Data too long for column» فيرى المستخدم خطأً غير مفهوم؛ وSQLite لا يفرض
 * الطول فتمرّ الاختبارات ولا يظهر العيب إلا في الإنتاج.
 *
 * التوسيع غير مدمّر: `char(36) → varchar(500)` يحفظ كل قيمة قائمة كما هي.
 * الأعمدة مكتوبةٌ صراحةً (لا بحلقةٍ على مصفوفة) كي يقرأها حارس
 * `FileColumnWidthTest` من المصدر — فلا يعود العيب صامتاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('applications')) {
            Schema::table('applications', function (Blueprint $t) {
                $t->string('certs_id', 500)->nullable()->change();
                $t->string('keystore_id', 500)->nullable()->change();
                $t->string('logo_id', 500)->nullable()->change();
                $t->string('shots_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('approvals')) {
            Schema::table('approvals', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('brands')) {
            Schema::table('brands', function (Blueprint $t) {
                $t->string('kit_id', 500)->nullable()->change();
                $t->string('logo_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('candidates')) {
            Schema::table('candidates', function (Blueprint $t) {
                $t->string('cv_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('changes')) {
            Schema::table('changes', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('companies')) {
            Schema::table('companies', function (Blueprint $t) {
                $t->string('logo_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('compliance_items')) {
            Schema::table('compliance_items', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('decisions')) {
            Schema::table('decisions', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('deployments')) {
            Schema::table('deployments', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('design_tasks')) {
            Schema::table('design_tasks', function (Blueprint $t) {
                $t->string('art_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('documents', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('employee_records')) {
            Schema::table('employee_records', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('fin_documents')) {
            Schema::table('fin_documents', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('incidents')) {
            Schema::table('incidents', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('internal_requests')) {
            Schema::table('internal_requests', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('ip_assets')) {
            Schema::table('ip_assets', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('issues')) {
            Schema::table('issues', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('kb_articles')) {
            Schema::table('kb_articles', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('media_items')) {
            Schema::table('media_items', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('meetings')) {
            Schema::table('meetings', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('platform_accounts')) {
            Schema::table('platform_accounts', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('policies')) {
            Schema::table('policies', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('projects')) {
            Schema::table('projects', function (Blueprint $t) {
                $t->string('logo_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('purchases')) {
            Schema::table('purchases', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('quotes')) {
            Schema::table('quotes', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('restore_tests')) {
            Schema::table('restore_tests', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('skills')) {
            Schema::table('skills', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('tasks')) {
            Schema::table('tasks', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }

        if (Schema::hasTable('work_updates')) {
            Schema::table('work_updates', function (Blueprint $t) {
                $t->string('att_id', 500)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // لا تراجع: العودة إلى char(36) تبتر المسارات المحفوظة فعلاً
    }
};
