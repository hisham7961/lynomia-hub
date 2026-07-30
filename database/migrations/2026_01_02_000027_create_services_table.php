<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الخدمات والمنتجات — Catalog */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('services', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->decimal('price', 16, 3)->nullable();
            $t->decimal('cost', 16, 3)->nullable();
            $t->string('cycle', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('team_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->uuid('domain_id')->nullable()->index();
            $t->decimal('sla', 16, 3)->nullable();
            $t->string('ver', 300)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('description')->nullable();
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

        DB::statement("CREATE INDEX services_proj_created_idx ON services (project_id, created_at DESC)");
        DB::statement("CREATE INDEX services_status_proj_idx ON services (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
