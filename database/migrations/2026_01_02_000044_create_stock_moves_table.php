<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** حركات المخزون — Stock moves */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_moves', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('doc_no', 300)->nullable();
            $t->string('kind', 80)->index();
            $t->uuid('item_id')->nullable()->index();
            $t->decimal('qty', 16, 3);
            $t->string('from_wh', 80)->nullable()->index();
            $t->string('to_wh', 80)->nullable()->index();
            $t->date('date');
            $t->uuid('by_id')->nullable()->index();
            $t->string('reference', 300)->nullable();
            $t->string('partner', 300)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX stock_moves_proj_created_idx ON stock_moves (project_id, created_at DESC)");
        DB::statement("CREATE INDEX stock_moves_status_proj_idx ON stock_moves (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_moves');
    }
};
