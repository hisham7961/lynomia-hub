<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الموافقات — Approvals */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('type', 80)->index();
            $t->decimal('amount', 16, 3)->nullable();
            $t->string('currency', 80)->nullable()->index();
            $t->text('reason')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->json('chain')->nullable();
            $t->date('due')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('approver_id')->nullable()->index();
            $t->text('details')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);
            $t->string('status', 30)->default('pending')->index();         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX approvals_proj_created_idx ON approvals (project_id, created_at DESC)");
        DB::statement("CREATE INDEX approvals_status_proj_idx ON approvals (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};