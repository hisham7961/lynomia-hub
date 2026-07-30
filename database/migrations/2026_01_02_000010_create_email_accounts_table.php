<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** البريد الإلكتروني — Emails */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('address', 300);
            $t->string('type', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('provider', 80)->nullable()->index();
            $t->string('recovery', 300)->nullable();
            $t->string('rec_phone', 300)->nullable();
            $t->boolean('two_f_a')->default(false);
            $t->date('created')->nullable();
            $t->string('status', 80)->nullable()->index();
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

        DB::statement("CREATE INDEX email_accounts_proj_created_idx ON email_accounts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX email_accounts_status_proj_idx ON email_accounts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
