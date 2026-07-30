<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الميزانيات — Budgets */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('scope', 80)->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('cc_id')->nullable()->index();
            $t->string('dept', 300)->nullable();
            $t->string('cat', 80)->nullable()->index();
            $t->string('period', 80)->nullable()->index();
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->decimal('amount', 16, 3);
            $t->string('currency', 80)->nullable()->index();
            $t->decimal('alert_pct', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX budgets_proj_created_idx ON budgets (project_id, created_at DESC)");
        DB::statement("CREATE INDEX budgets_status_proj_idx ON budgets (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
