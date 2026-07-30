<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** خطة العمل والمزايا — Plan & features */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('plan_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('type', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('assignee_id')->nullable()->index();
            $t->date('date_start')->nullable();
            $t->date('due')->nullable();
            $t->decimal('weight', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->decimal('progress', 16, 3)->nullable();
            $t->string('test', 80)->nullable()->index();
            $t->string('test_note', 300)->nullable();
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

        DB::statement("CREATE INDEX plan_items_proj_created_idx ON plan_items (project_id, created_at DESC)");
        DB::statement("CREATE INDEX plan_items_status_proj_idx ON plan_items (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_items');
    }
};
