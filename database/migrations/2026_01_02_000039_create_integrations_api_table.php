<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** التكاملات و APIs — APIs */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('integrations_api', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->string('provider', 300)->nullable();
            $t->string('base_url', 600)->nullable();
            $t->string('docs', 600)->nullable();
            $t->string('auth_type', 80)->nullable()->index();
            $t->uuid('vault_id')->nullable()->index();
            $t->string('env', 80)->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('quota', 300)->nullable();
            $t->decimal('cost', 16, 3)->nullable();
            $t->date('expiry')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
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

        DB::statement("CREATE INDEX integrations_api_proj_created_idx ON integrations_api (project_id, created_at DESC)");
        DB::statement("CREATE INDEX integrations_api_status_proj_idx ON integrations_api (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations_api');
    }
};
