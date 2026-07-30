<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** التصاميم — Designs */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('design_tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('assignee_id')->nullable()->index();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('priority', 80)->nullable()->index();
            $t->date('due')->nullable();
            $t->text('brief')->nullable();
            $t->string('ref_link', 600)->nullable();
            $t->uuid('art_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX design_tasks_proj_created_idx ON design_tasks (project_id, created_at DESC)");
        DB::statement("CREATE INDEX design_tasks_status_proj_idx ON design_tasks (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('design_tasks');
    }
};
