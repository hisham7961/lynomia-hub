<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الاجتماعات — Meetings */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->timestamp('dt')->nullable();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->json('parts')->nullable();
            $t->string('link', 600)->nullable();
            $t->uuid('client_id')->nullable()->index();
            $t->string('repeat', 80)->nullable()->index();
            $t->text('agenda')->nullable();
            $t->text('notes')->nullable();
            $t->text('decs')->nullable();
            $t->uuid('att_id')->nullable(); // مرفق في attachments
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX meetings_proj_created_idx ON meetings (project_id, created_at DESC)");
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
