<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الكود المصدري — Source code */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('code_releases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('ver', 300);
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->date('date')->nullable();
            $t->uuid('file_id')->nullable(); // مرفق في attachments
            $t->string('repo', 600)->nullable();
            $t->string('branch', 300)->nullable();
            $t->string('commit', 300)->nullable();
            $t->json('tags')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX code_releases_proj_created_idx ON code_releases (project_id, created_at DESC)");
        DB::statement("CREATE INDEX code_releases_status_proj_idx ON code_releases (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('code_releases');
    }
};
