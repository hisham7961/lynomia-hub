<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المصروفات المتكررة — Recurring */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('recurring_docs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('partner', 300)->nullable();
            $t->string('kind', 80)->nullable()->index();
            $t->decimal('amount', 16, 3);
            $t->string('currency', 80)->nullable()->index();
            $t->string('cycle', 80)->index();
            $t->date('next')->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('cc_id')->nullable()->index();
            $t->string('cat', 80)->nullable()->index();
            $t->string('method', 80)->nullable()->index();
            $t->boolean('auto_post')->default(false);
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

        DB::statement("CREATE INDEX recurring_docs_proj_created_idx ON recurring_docs (project_id, created_at DESC)");
        DB::statement("CREATE INDEX recurring_docs_status_proj_idx ON recurring_docs (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_docs');
    }
};
