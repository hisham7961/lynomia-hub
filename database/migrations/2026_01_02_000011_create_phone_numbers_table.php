<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** أرقام الهواتف — Phone numbers */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('number', 300);
            $t->string('country', 80)->nullable()->index();
            $t->string('carrier', 300)->nullable();
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('project_id')->nullable()->index();
            $t->string('usage', 300)->nullable();
            $t->uuid('owner_id')->nullable()->index();
            $t->boolean('sms')->default(false);
            $t->boolean('wa')->default(false);
            $t->date('expiry')->nullable()->index();
            $t->decimal('cost', 16, 3)->nullable();
            $t->string('status', 80)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('custom')->nullable();            // الحقول المخصصة
            $t->json('meta')->nullable();              // بيانات إضافية (تاريخ، تكاملات، تتبع)
            $t->integer('version')->default(1);         // القفل التفاؤلي
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement("CREATE INDEX phone_numbers_proj_created_idx ON phone_numbers (project_id, created_at DESC)");
        DB::statement("CREATE INDEX phone_numbers_status_proj_idx ON phone_numbers (status, project_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
