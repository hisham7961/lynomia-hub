<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المحاسبة والفواتير — Accounting */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fin_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('doc_no', 300);
            $t->string('kind', 80)->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('partner', 300)->nullable();
            $t->date('date')->nullable();
            $t->date('due')->nullable()->index();
            $t->decimal('amount', 16, 3)->nullable();
            $t->decimal('tax', 16, 3)->nullable();
            $t->decimal('total', 16, 3);
            $t->decimal('paid', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->string('state', 80)->nullable()->index();
            $t->string('cat', 80)->nullable()->index();
            $t->string('method', 80)->nullable()->index();
            $t->uuid('cc_id')->nullable()->index();
            $t->string('reference', 300)->nullable();
            $t->string('odoo_id', 300)->nullable();
            $t->text('description')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX fin_documents_proj_created_idx ON fin_documents (project_id, created_at DESC)");
        DB::statement("CREATE INDEX fin_documents_status_proj_idx ON fin_documents (state, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_documents');
    }
};
