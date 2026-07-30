<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الأصول والعهد — Assets */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('tag', 300)->nullable();
            $t->string('serial', 300)->nullable();
            $t->string('type', 80)->nullable()->index();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('holder_id')->nullable()->index();
            $t->string('loc', 300)->nullable();
            $t->string('vendor', 300)->nullable();
            $t->date('buy_date')->nullable();
            $t->decimal('price', 16, 3)->nullable();
            $t->date('warranty')->nullable()->index();
            $t->string('status', 80)->nullable()->index();
            $t->date('maint')->nullable();
            $t->decimal('life', 16, 3)->nullable();
            $t->text('parts')->nullable();
            $t->date('disposal')->nullable();
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

        DB::statement("CREATE INDEX assets_proj_created_idx ON assets (project_id, created_at DESC)");
        DB::statement("CREATE INDEX assets_status_proj_idx ON assets (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
