<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الإجازات والطلبات — Leaves */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('emp_id')->nullable()->index();
            $t->string('type', 80)->index();
            $t->date('date_from');
            $t->date('date_to')->nullable();
            $t->decimal('days', 16, 3)->nullable();
            $t->text('reason')->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->uuid('mgr_id')->nullable()->index();
            $t->string('note', 300)->nullable();
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

        DB::statement("CREATE INDEX leave_requests_proj_created_idx ON leave_requests (project_id, created_at DESC)");
        DB::statement("CREATE INDEX leave_requests_status_proj_idx ON leave_requests (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
