<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** إدارة التغييرات التقنية — أي تغيير على البنية (سيرفر/تطبيق/قاعدة/دومين/DNS/صلاحيات/إنتاج) */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('target', 60)->nullable()->index();     // نوع الهدف
            $t->uuid('server_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('domain_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('risk', 40)->nullable()->index();
            $t->text('plan')->nullable();                      // خطة التنفيذ
            $t->text('rollback')->nullable();                  // خطة التراجع
            $t->dateTime('window')->nullable()->index();       // الموعد المخطط
            $t->dateTime('done_at')->nullable();               // التنفيذ الفعلي
            $t->uuid('owner_id')->nullable()->index();         // المنفذ
            $t->string('status', 60)->nullable()->index();
            $t->string('result', 60)->nullable();
            $t->string('affected', 400)->nullable();           // الأنظمة المتأثرة
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

        DB::statement('CREATE INDEX changes_proj_created_idx ON changes (project_id, created_at DESC)');
        DB::statement('CREATE INDEX changes_status_proj_idx ON changes (status, project_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('changes');
    }
};
