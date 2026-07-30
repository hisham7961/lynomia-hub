<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** تذاكر العملاء — Tickets */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('subject', 300);
            $t->text('body')->nullable();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->string('customer', 300)->nullable();
            $t->string('email', 300)->nullable();
            $t->string('phone', 300)->nullable();
            $t->string('channel', 80)->nullable()->index();
            $t->string('cat', 80)->nullable()->index();
            $t->string('priority', 80)->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->uuid('assignee_id')->nullable()->index();
            $t->string('ext_id', 300)->nullable();
            $t->json('tags')->nullable();
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

        DB::statement("CREATE INDEX tickets_proj_created_idx ON tickets (project_id, created_at DESC)");
        DB::statement("CREATE INDEX tickets_status_proj_idx ON tickets (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
