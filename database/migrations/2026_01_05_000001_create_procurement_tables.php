<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المشتريات: الموردون + مستندات الشراء (طلب ← أمر ← استلام ← فاتورة مورد) */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('contact', 300)->nullable();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('country', 80)->nullable()->index();
            $t->string('cat', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('rating', 40)->nullable()->index();
            $t->string('terms', 300)->nullable();
            $t->string('iban', 120)->nullable();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->uuid('project_id')->nullable()->index();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('doc_no', 120);
            $t->uuid('supplier_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->date('date')->nullable();
            $t->date('due')->nullable()->index();          // التسليم المتوقع
            $t->text('items')->nullable();
            $t->decimal('amount', 16, 3)->nullable();
            $t->string('currency', 20)->nullable();
            $t->string('status', 60)->nullable()->index();
            $t->string('pay_state', 40)->nullable()->index();
            $t->string('invoice_no', 120)->nullable();     // رقم فاتورة المورد
            $t->uuid('att_id')->nullable();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX purchases_proj_created_idx ON purchases (project_id, created_at DESC)');
        DB::statement('CREATE INDEX purchases_status_proj_idx ON purchases (status, project_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
