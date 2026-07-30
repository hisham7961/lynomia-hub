<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المشاريع — Projects */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->uuid('logo_id')->nullable(); // مرفق في attachments
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('manager_id')->nullable()->index();
            $t->json('members')->nullable();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('priority', 80)->nullable()->index();
            $t->decimal('progress', 16, 3)->nullable();
            $t->date('start_date')->nullable();
            $t->date('launch_exp')->nullable();
            $t->date('launch_act')->nullable();
            $t->string('market', 300)->nullable();
            $t->string('countries', 300)->nullable();
            $t->string('url', 600)->nullable();
            $t->string('staging', 600)->nullable();
            $t->string('git', 600)->nullable();
            $t->text('description')->nullable();
            $t->decimal('budget', 16, 3)->nullable();
            $t->decimal('cost', 16, 3)->nullable();
            $t->decimal('rev_exp', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->string('prod', 600)->nullable();
            $t->string('brand_colors', 300)->nullable();
            $t->string('brand_fonts', 300)->nullable();
            $t->uuid('brand_designer_id')->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX projects_proj_created_idx ON projects (project_id, created_at DESC)");
        DB::statement("CREATE INDEX projects_status_proj_idx ON projects (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
