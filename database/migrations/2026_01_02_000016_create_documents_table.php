<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الملفات والمستندات — Files & documents */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('cat', 80)->nullable()->index();
            $t->string('legal_cat', 80)->nullable()->index();
            $t->string('brand_kind', 80)->nullable()->index();
            $t->string('folder', 300)->nullable();
            $t->uuid('task_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('ver', 300)->nullable();
            $t->string('secrecy', 80)->nullable()->index();
            $t->string('link', 600)->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->date('issue_date')->nullable();
            $t->date('expiry')->nullable()->index();
            $t->string('issuer', 300)->nullable();
            $t->string('doc_no', 300)->nullable();
            $t->string('doc_status', 80)->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->decimal('alert', 16, 3)->nullable();
            $t->text('description')->nullable();
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

        DB::statement("CREATE INDEX documents_proj_created_idx ON documents (project_id, created_at DESC)");
        DB::statement("CREATE INDEX documents_status_proj_idx ON documents (doc_status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
