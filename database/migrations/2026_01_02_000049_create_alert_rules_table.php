<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** قواعد التنبيه — Alert rules */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 300);
            $t->string('mod', 80)->index();
            $t->string('field', 300);
            $t->string('op', 80)->index();
            $t->string('val', 300)->nullable();
            $t->string('msg', 300)->nullable();
            $t->uuid('to_id')->nullable()->index();
            $t->string('chan', 80)->nullable()->index();
            $t->decimal('every', 16, 3)->nullable();
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

        DB::statement("CREATE INDEX alert_rules_proj_created_idx ON alert_rules (project_id, created_at DESC)");
        DB::statement("CREATE INDEX alert_rules_status_proj_idx ON alert_rules (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
