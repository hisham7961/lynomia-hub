<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** البنوك والصناديق — Banks & cash */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('kind', 80)->nullable()->index();
            $t->string('bank', 300)->nullable();
            $t->string('iban', 300)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->decimal('balance', 16, 3)->nullable();
            $t->decimal('min_bal', 16, 3)->nullable();
            $t->uuid('acc_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
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

        DB::statement("CREATE INDEX bank_accounts_proj_created_idx ON bank_accounts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX bank_accounts_status_proj_idx ON bank_accounts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
