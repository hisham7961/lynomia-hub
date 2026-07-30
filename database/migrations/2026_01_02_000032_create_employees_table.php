<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ملفات الموظفين — HR files */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->uuid('user_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('dept', 80)->nullable()->index();
            $t->string('title', 300)->nullable();
            $t->uuid('manager_id')->nullable()->index();
            $t->date('hired')->nullable();
            $t->string('contract', 80)->nullable()->index();
            $t->decimal('salary', 16, 3)->nullable();
            $t->decimal('allow', 16, 3)->nullable();
            $t->string('iqama', 300)->nullable();
            $t->date('iqama_exp')->nullable()->index();
            $t->string('passport', 300)->nullable();
            $t->date('pass_exp')->nullable()->index();
            $t->decimal('leave_bal', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->string('perf', 80)->nullable()->index();
            $t->json('asset_ids')->nullable();
            $t->date('end_date')->nullable();
            $t->text('notes')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX employees_proj_created_idx ON employees (project_id, created_at DESC)");
        DB::statement("CREATE INDEX employees_status_proj_idx ON employees (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
