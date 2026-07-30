<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الحضور والانصراف — Attendance */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('emp_id')->nullable()->index();
            $t->date('date');
            $t->string('time_in', 300)->nullable();
            $t->string('time_out', 300)->nullable();
            $t->decimal('hours', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->string('notes', 300)->nullable();
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

        DB::statement("CREATE INDEX attendance_proj_created_idx ON attendance (project_id, created_at DESC)");
        DB::statement("CREATE INDEX attendance_status_proj_idx ON attendance (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
