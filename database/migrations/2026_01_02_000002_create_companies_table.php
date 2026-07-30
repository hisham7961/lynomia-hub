<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الشركات — Companies */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name_ar', 300);
            $t->string('name_en', 300)->nullable();
            $t->uuid('logo_id')->nullable(); // مرفق في attachments
            $t->string('country', 80)->nullable()->index();
            $t->string('city', 300)->nullable();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('reg_no', 300)->nullable();
            $t->string('tax_no', 300)->nullable();
            $t->date('founded')->nullable();
            $t->string('activity', 300)->nullable();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('website', 600)->nullable();
            $t->string('social', 300)->nullable();
            $t->string('owner', 300)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->text('partners')->nullable();
            $t->text('address')->nullable();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX companies_proj_created_idx ON companies (project_id, created_at DESC)");
        DB::statement("CREATE INDEX companies_status_proj_idx ON companies (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
