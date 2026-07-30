<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** العملاء (CRM) — Clients */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('contact', 300)->nullable();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('country', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('source', 80)->nullable()->index();
            $t->string('stage', 80)->nullable()->index();
            $t->decimal('value', 16, 3)->nullable();
            $t->decimal('prob', 16, 3)->nullable();
            $t->json('services')->nullable();
            $t->date('last_touch')->nullable();
            $t->string('next_step', 300)->nullable();
            $t->date('next_date')->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX clients_proj_created_idx ON clients (project_id, created_at DESC)");
        DB::statement("CREATE INDEX clients_status_proj_idx ON clients (stage, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
