<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** السوشال ميديا — Social media */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('platform', 80)->index();
            $t->string('handle', 300);
            $t->string('url', 600)->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->decimal('followers', 16, 3)->nullable();
            $t->decimal('goal', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX social_accounts_proj_created_idx ON social_accounts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX social_accounts_status_proj_idx ON social_accounts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
