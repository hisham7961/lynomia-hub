<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** دليل الحسابات — Chart of accounts */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('code', 300);
            $t->string('name', 300);
            $t->string('type', 80)->index();
            $t->string('sub', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->decimal('odoo_id', 16, 3)->nullable();
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

        DB::statement("CREATE INDEX ledger_accounts_proj_created_idx ON ledger_accounts (project_id, created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
