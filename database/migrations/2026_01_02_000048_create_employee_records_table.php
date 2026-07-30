<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سجلات الموظفين — Employee records */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_records', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('emp_id')->nullable()->index();
            $t->string('title', 300);
            $t->string('kind', 80)->index();
            $t->date('date');
            $t->date('expiry')->nullable()->index();
            $t->uuid('by_id')->nullable()->index();
            $t->string('provider', 300)->nullable();
            $t->string('score', 300)->nullable();
            $t->decimal('cost', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('description')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX employee_records_proj_created_idx ON employee_records (project_id, created_at DESC)");
        DB::statement("CREATE INDEX employee_records_status_proj_idx ON employee_records (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_records');
    }
};
