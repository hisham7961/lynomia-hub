<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** مسيّرات الرواتب — Payroll */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('month', 300);
            $t->uuid('company_id')->nullable()->index();
            $t->decimal('total', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->date('pay_date')->nullable();
            $t->text('notes')->nullable();
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

        DB::statement("CREATE INDEX payroll_runs_proj_created_idx ON payroll_runs (project_id, created_at DESC)");
        DB::statement("CREATE INDEX payroll_runs_status_proj_idx ON payroll_runs (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
