<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المشاكل والمخاطر — Issues & risks */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('issues', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('site_id')->nullable()->index();
            $t->string('affected', 300)->nullable();
            $t->uuid('ticket_id')->nullable()->index();
            $t->string('severity', 80)->nullable()->index();
            $t->string('priority', 80)->nullable()->index();
            $t->string('risk_cat', 80)->nullable()->index();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->uuid('assignee_id')->nullable()->index();
            $t->date('found')->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('cause')->nullable();
            $t->text('fix')->nullable();
            $t->text('final_fix')->nullable();
            $t->date('closed')->nullable();
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

        DB::statement("CREATE INDEX issues_proj_created_idx ON issues (project_id, created_at DESC)");
        DB::statement("CREATE INDEX issues_status_proj_idx ON issues (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('issues');
    }
};
