<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المواقع — Websites */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('url', 600);
            $t->uuid('project_id')->nullable()->index();
            $t->string('type', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('tech', 300)->nullable();
            $t->string('cms', 300)->nullable();
            $t->uuid('dev_id')->nullable()->index();
            $t->string('admin_url', 600)->nullable();
            $t->string('staging', 600)->nullable();
            $t->string('host', 300)->nullable();
            $t->boolean('cloudflare')->default(false);
            $t->string('git', 600)->nullable();
            $t->string('prod', 600)->nullable();
            $t->string('db_name', 300)->nullable();
            $t->string('gsc', 300)->nullable();
            $t->string('ga', 300)->nullable();
            $t->string('pixels', 300)->nullable();
            $t->string('sup_email', 300)->nullable();
            $t->date('last_up')->nullable();
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

        DB::statement("CREATE INDEX websites_proj_created_idx ON websites (project_id, created_at DESC)");
        DB::statement("CREATE INDEX websites_status_proj_idx ON websites (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
