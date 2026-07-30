<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** سجل الاعتماديات — على ماذا يعتمد كل مشروع/نظام، وماذا يتعطل إذا سقط ذلك الاعتماد */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('dependencies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);
            $t->string('src_type', 40)->nullable()->index();      // نوع العنصر المعتمِد
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('app_id')->nullable()->index();
            $t->uuid('server_id')->nullable()->index();
            $t->string('dep_type', 40)->nullable()->index();      // نوع العنصر المعتمَد عليه
            $t->string('dep_name', 300);                          // اسم العنصر المعتمَد عليه
            $t->uuid('supplier_id')->nullable()->index();         // المورد المسؤول عنه
            $t->uuid('api_id')->nullable()->index();              // الـ API المرتبط
            $t->uuid('emp_id')->nullable()->index();              // الموظف المعني بالاعتمادية
            $t->string('criticality', 40)->nullable()->index();   // درجة الحرجية
            $t->text('impact')->nullable();                       // ما الذي يتعطل عند فشله
            $t->text('fallback')->nullable();                     // البديل أو خطة الطوارئ
            $t->decimal('rto_hours', 16, 3)->nullable();          // وقت التعافي المقبول بالساعات
            $t->date('reviewed_at')->nullable()->index();         // آخر مراجعة للاعتمادية
            $t->string('status', 60)->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('tags')->nullable();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX deps_crit_status_idx ON dependencies (criticality, status)');
        DB::statement('CREATE INDEX deps_proj_created_idx ON dependencies (project_id, created_at DESC)');
        DB::statement('CREATE INDEX deps_type_crit_idx ON dependencies (dep_type, criticality)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dependencies');
    }
};
