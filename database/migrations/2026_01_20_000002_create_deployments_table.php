<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سجل النشر والإصدارات — كل عملية رفع إصدار إلى بيئة ونتيجتها */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('deployments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('ver', 80)->index();                       // رقم النسخة/الإصدار
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('env', 40)->nullable()->index();           // البيئة: إنتاج/تجريبي/تطوير
            $t->dateTime('deployed_at')->nullable()->index();     // وقت تنفيذ النشر
            $t->uuid('by_id')->nullable()->index();               // من نفّذ النشر
            $t->text('changes_log')->nullable();                  // ما الذي تغيّر في هذا الإصدار
            $t->string('commit', 120)->nullable()->index();       // Git commit hash
            $t->string('migrations', 40)->nullable();             // حالة هجرات قاعدة البيانات
            $t->string('tests', 40)->nullable();                  // نتيجة تشغيل الاختبارات
            $t->string('status', 60)->nullable()->index();        // النتيجة النهائية للنشر
            $t->boolean('rollbackable')->default(false);          // هل يمكن التراجع لهذا الإصدار
            $t->uuid('change_id')->nullable()->index();           // التغيير التقني المرتبط
            $t->uuid('incident_id')->nullable()->index();         // الحادث الناتج أو المرتبط
            $t->unsignedInteger('duration_min')->nullable();      // مدة عملية النشر بالدقائق
            $t->uuid('att_id')->nullable();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX deployments_app_deployed_idx ON deployments (app_id, deployed_at DESC)');
        DB::statement('CREATE INDEX deployments_env_status_idx ON deployments (env, status)');
        DB::statement('CREATE INDEX deployments_proj_created_idx ON deployments (project_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('deployments');
    }
};
