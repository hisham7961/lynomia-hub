<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** قاعدة المعرفة والسياسات — Knowledge base */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('kb_articles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('cat', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('ver', 300)->nullable();
            $t->boolean('must_read')->default(false);
            $t->string('status', 80)->nullable()->index();
            $t->text('body')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX kb_articles_proj_created_idx ON kb_articles (project_id, created_at DESC)");
        DB::statement("CREATE INDEX kb_articles_status_proj_idx ON kb_articles (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_articles');
    }
};
