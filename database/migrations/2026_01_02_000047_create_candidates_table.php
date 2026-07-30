<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** التوظيف — Recruitment */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('job', 300);
            $t->string('dept', 80)->nullable()->index();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('source', 80)->nullable()->index();
            $t->string('stage', 80)->nullable()->index();
            $t->decimal('expect', 16, 3)->nullable();
            $t->string('rating', 80)->nullable()->index();
            $t->uuid('interviewer')->nullable()->index();
            $t->timestamp('next_date')->nullable();
            $t->uuid('cv_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX candidates_proj_created_idx ON candidates (project_id, created_at DESC)");
        DB::statement("CREATE INDEX candidates_status_proj_idx ON candidates (stage, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
