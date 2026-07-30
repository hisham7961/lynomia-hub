<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** قواعد البيانات — Databases */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('databases_reg', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('engine', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->string('host', 300)->nullable();
            $t->string('port', 300)->nullable();
            $t->string('db_user', 300)->nullable();
            $t->uuid('vault_id')->nullable()->index();
            $t->string('size', 300)->nullable();
            $t->string('backup', 300)->nullable();
            $t->date('last_bk')->nullable()->index();
            $t->string('env', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
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

        DB::statement("CREATE INDEX databases_reg_proj_created_idx ON databases_reg (project_id, created_at DESC)");
        DB::statement("CREATE INDEX databases_reg_status_proj_idx ON databases_reg (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('databases_reg');
    }
};
