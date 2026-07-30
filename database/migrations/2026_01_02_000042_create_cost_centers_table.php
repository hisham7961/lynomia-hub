<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** مراكز التكلفة — Cost centers */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 300);
            $t->string('name', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('manager_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
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

        DB::statement("CREATE INDEX cost_centers_proj_created_idx ON cost_centers (project_id, created_at DESC)");
        DB::statement("CREATE INDEX cost_centers_status_proj_idx ON cost_centers (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
    }
};
