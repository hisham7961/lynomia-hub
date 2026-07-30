<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** تحديثات العمل — Work updates */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_updates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->nullable()->index();
            $t->text('done');
            $t->text('doing')->nullable();
            $t->text('problems')->nullable();
            $t->text('needs')->nullable();
            $t->text('next')->nullable();
            $t->decimal('progress', 16, 3)->nullable();
            $t->decimal('hours', 16, 3)->nullable();
            $t->string('links', 300)->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
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

        DB::statement("CREATE INDEX work_updates_proj_created_idx ON work_updates (project_id, created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists('work_updates');
    }
};
