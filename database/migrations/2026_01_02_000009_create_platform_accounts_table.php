<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** حسابات المنصات — Accounts */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('platform', 80)->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('username', 300)->nullable();
            $t->string('login_url', 600)->nullable();
            $t->uuid('owner_id')->nullable()->index();
            $t->uuid('manager_id')->nullable()->index();
            $t->string('country', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->boolean('verified')->default(false);
            $t->date('created')->nullable();
            $t->date('sub_exp')->nullable()->index();
            $t->decimal('cost', 16, 3)->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->string('pay', 300)->nullable();
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

        DB::statement("CREATE INDEX platform_accounts_proj_created_idx ON platform_accounts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX platform_accounts_status_proj_idx ON platform_accounts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_accounts');
    }
};
