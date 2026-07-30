<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** العقود والالتزامات — Contracts */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('type', 80)->index();
            $t->uuid('client_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('party', 300)->nullable();
            $t->decimal('value', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->date('date_start')->nullable();
            $t->date('date_end')->nullable()->index();
            $t->string('renewal', 80)->nullable()->index();
            $t->decimal('notice', 16, 3)->nullable();
            $t->uuid('owner_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->string('alerts', 300)->nullable();
            $t->text('obligations')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX contracts_proj_created_idx ON contracts (project_id, created_at DESC)");
        DB::statement("CREATE INDEX contracts_status_proj_idx ON contracts (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
