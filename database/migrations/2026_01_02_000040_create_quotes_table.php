<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** عروض الأسعار — Quotes */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('doc_no', 300);
            $t->uuid('client_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->date('date')->nullable();
            $t->date('valid')->nullable()->index();
            $t->text('items')->nullable();
            $t->decimal('amount', 16, 3)->nullable();
            $t->decimal('tax', 16, 3)->nullable();
            $t->decimal('total', 16, 3);
            $t->string('currency', 80)->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->text('terms')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX quotes_proj_created_idx ON quotes (project_id, created_at DESC)");
        DB::statement("CREATE INDEX quotes_status_proj_idx ON quotes (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
