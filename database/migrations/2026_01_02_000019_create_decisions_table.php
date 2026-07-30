<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سجل القرارات — Decisions */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('decisions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('client_id')->nullable()->index();
            $t->uuid('owner_id')->nullable()->index();
            $t->date('due')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->uuid('meeting_id')->nullable()->index();
            $t->date('date')->nullable();
            $t->text('reason')->nullable();
            $t->json('parts')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX decisions_proj_created_idx ON decisions (project_id, created_at DESC)");
        DB::statement("CREATE INDEX decisions_status_proj_idx ON decisions (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
    }
};
