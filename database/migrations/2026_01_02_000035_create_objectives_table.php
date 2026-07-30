<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الأهداف والنتائج (OKR) — OKRs */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('objectives', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('level', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('period', 80)->nullable()->index();
            $t->date('date_start')->nullable();
            $t->date('due')->nullable()->index();
            $t->decimal('progress', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('description')->nullable();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX objectives_proj_created_idx ON objectives (project_id, created_at DESC)");
        DB::statement("CREATE INDEX objectives_status_proj_idx ON objectives (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('objectives');
    }
};
