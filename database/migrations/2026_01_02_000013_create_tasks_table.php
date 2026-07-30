<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المهام — Tasks */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('assignee_id')->nullable()->index();
            $t->json('parts')->nullable();
            $t->string('priority', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->date('start_date')->nullable();
            $t->date('due')->nullable();
            $t->decimal('est_h', 16, 3)->nullable();
            $t->decimal('act_h', 16, 3)->nullable();
            $t->decimal('progress', 16, 3)->nullable();
            $t->string('dept', 80)->nullable()->index();
            $t->uuid('ticket_id')->nullable()->index();
            $t->string('approval', 80)->nullable()->index();
            $t->text('late_reason')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->text('description')->nullable();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX tasks_proj_created_idx ON tasks (project_id, created_at DESC)");
        DB::statement("CREATE INDEX tasks_status_proj_idx ON tasks (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
