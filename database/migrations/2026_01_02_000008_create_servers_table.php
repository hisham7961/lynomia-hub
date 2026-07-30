<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** السيرفرات — Servers */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('provider', 300)->nullable();
            $t->string('ip', 300)->nullable();
            $t->string('type', 80)->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->string('os', 300)->nullable();
            $t->string('specs', 300)->nullable();
            $t->string('panel', 600)->nullable();
            $t->date('expiry')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
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

        DB::statement("CREATE INDEX servers_proj_created_idx ON servers (project_id, created_at DESC)");
        DB::statement("CREATE INDEX servers_status_proj_idx ON servers (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
