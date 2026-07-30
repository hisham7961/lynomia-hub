<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** التطبيقات — Applications */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->uuid('logo_id')->nullable(); // مرفق في attachments
            $t->uuid('project_id')->nullable()->index();
            $t->string('platform', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('ver', 300)->nullable();
            $t->string('build', 300)->nullable();
            $t->boolean('auto_store')->default(false);
            $t->string('pkg', 300)->nullable();
            $t->string('bundle', 300)->nullable();
            $t->date('last_up')->nullable();
            $t->date('next_up')->nullable();
            $t->uuid('dev_id')->nullable()->index();
            $t->string('fw', 300)->nullable();
            $t->string('api', 600)->nullable();
            $t->string('git', 600)->nullable();
            $t->string('firebase', 600)->nullable();
            $t->string('admin_url', 600)->nullable();
            $t->string('play', 600)->nullable();
            $t->string('appstore', 600)->nullable();
            $t->string('huawei', 600)->nullable();
            $t->string('test_url', 600)->nullable();
            $t->boolean('published')->default(false);
            $t->string('apple_rev', 80)->nullable()->index();
            $t->string('google_rev', 80)->nullable()->index();
            $t->string('sup_email', 300)->nullable();
            $t->string('privacy', 600)->nullable();
            $t->string('terms', 600)->nullable();
            $t->string('del_acc', 600)->nullable();
            $t->string('sup_phone', 300)->nullable();
            $t->string('gcloud', 600)->nullable();
            $t->string('apple_dev', 600)->nullable();
            $t->string('play_con', 600)->nullable();
            $t->string('countries', 300)->nullable();
            $t->string('sub_types', 300)->nullable();
            $t->text('iap')->nullable();
            $t->uuid('shots_id')->nullable(); // مرفق في attachments
            $t->uuid('keystore_id')->nullable(); // مرفق في attachments
            $t->uuid('certs_id')->nullable(); // مرفق في attachments
            $t->uuid('vault_id')->nullable()->index();
            $t->text('description')->nullable();
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

        DB::statement("CREATE INDEX applications_proj_created_idx ON applications (project_id, created_at DESC)");
        DB::statement("CREATE INDEX applications_status_proj_idx ON applications (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
    }
};
