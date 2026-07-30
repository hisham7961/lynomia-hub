<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** المخزون — Inventory */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('sku', 300)->nullable();
            $t->string('barcode', 300)->nullable();
            $t->string('wh', 80)->nullable()->index();
            $t->decimal('qty', 16, 3);
            $t->decimal('reorder', 16, 3)->nullable();
            $t->string('unit', 300)->nullable();
            $t->string('batch', 300)->nullable();
            $t->date('prod')->nullable();
            $t->date('expiry')->nullable()->index();
            $t->decimal('cost', 16, 3)->nullable();
            $t->decimal('price', 16, 3)->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
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

        DB::statement("CREATE INDEX stock_items_proj_created_idx ON stock_items (project_id, created_at DESC)");
        DB::statement("CREATE INDEX stock_items_status_proj_idx ON stock_items (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_items');
    }
};
