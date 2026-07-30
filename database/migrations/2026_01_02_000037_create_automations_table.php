<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الأتمتة (Workflows) — Automations */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('trigger', 80)->index();
            $t->string('act1', 80)->index();
            $t->string('act2', 80)->nullable()->index();
            $t->string('act3', 80)->nullable()->index();
            $t->uuid('assignee_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX automations_proj_created_idx ON automations (project_id, created_at DESC)");
        DB::statement("CREATE INDEX automations_status_proj_idx ON automations (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('automations');
    }
};
