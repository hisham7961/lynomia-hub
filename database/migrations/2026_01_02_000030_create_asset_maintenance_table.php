<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سجل الصيانة — Maintenance log */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('asset_maintenance', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('asset_id')->nullable()->index();
            $t->string('title', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->date('date');
            $t->uuid('by_id')->nullable()->index();
            $t->string('vendor', 300)->nullable();
            $t->decimal('cost', 16, 3)->nullable();
            $t->string('part', 300)->nullable();
            $t->date('next')->nullable()->index();
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

        DB::statement("CREATE INDEX asset_maintenance_proj_created_idx ON asset_maintenance (project_id, created_at DESC)");
        DB::statement("CREATE INDEX asset_maintenance_status_proj_idx ON asset_maintenance (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance');
    }
};
