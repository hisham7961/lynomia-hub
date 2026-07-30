<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** قيود اليومية — Journal entries */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('doc_no', 300);
            $t->date('date');
            $t->uuid('project_id')->nullable()->index();
            $t->text('description')->nullable();
            $t->string('reference', 300)->nullable();
            $t->string('state', 80)->nullable()->index();
            $t->uuid('fin_id')->nullable()->index();
            $t->decimal('odoo_id', 16, 3)->nullable();
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

        DB::statement("CREATE INDEX journal_entries_proj_created_idx ON journal_entries (project_id, created_at DESC)");
        DB::statement("CREATE INDEX journal_entries_status_proj_idx ON journal_entries (state, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
