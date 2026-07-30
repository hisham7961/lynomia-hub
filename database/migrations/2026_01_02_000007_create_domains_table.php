<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الدومينات — Domains */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('registrar', 300)->nullable();
            $t->date('reg_date')->nullable();
            $t->date('expiry')->index();
            $t->boolean('auto_renew')->default(false);
            $t->decimal('cost', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->string('ns', 300)->nullable();
            $t->string('dns', 300)->nullable();
            $t->string('ssl', 80)->nullable()->index();
            $t->date('ssl_exp')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->decimal('alert', 16, 3)->nullable();
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

        DB::statement("CREATE INDEX domains_proj_created_idx ON domains (project_id, created_at DESC)");
        DB::statement("CREATE INDEX domains_ssl_proj_idx ON domains (`ssl`, `project_id`)");
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
