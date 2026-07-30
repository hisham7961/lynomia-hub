<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الخزنة الآمنة — Secure Vault */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vault_secrets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('type', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->string('username', 300)->nullable();
            $t->text('secret_cipher')->nullable(); // مشفّر (envelope encryption)
            $t->json('allowed_ids')->nullable();
            $t->string('url', 600)->nullable();
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

        DB::statement("CREATE INDEX vault_secrets_proj_created_idx ON vault_secrets (project_id, created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_secrets');
    }
};
