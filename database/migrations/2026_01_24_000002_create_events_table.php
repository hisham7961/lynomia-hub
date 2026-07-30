<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الأحداث والمعارض — المعارض والمؤتمرات والرعايات وورش العمل وإطلاق المنتجات */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title', 300);                          // اسم الحدث
            $t->string('type', 80)->nullable()->index();       // نوع الحدث
            $t->string('venue', 300)->nullable();              // المكان / القاعة / المدينة
            $t->date('date_start')->nullable()->index();       // تاريخ البداية
            $t->date('date_end')->nullable()->index();         // تاريخ النهاية
            $t->uuid('company_id')->nullable()->index();
            $t->uuid('brand_id')->nullable()->index();         // العلامة التجارية المشاركة
            $t->uuid('owner_id')->nullable()->index();         // المسؤول عن الحدث
            $t->json('team')->nullable();                      // فريق المشاركة (معرفات مستخدمين)
            $t->decimal('budget', 16, 3)->nullable();          // الميزانية المعتمدة
            $t->decimal('actual_cost', 16, 3)->nullable();     // التكلفة الفعلية
            $t->string('currency', 80)->nullable()->index();
            $t->text('goals')->nullable();                     // أهداف المشاركة
            $t->text('results')->nullable();                   // النتائج المحققة بعد الحدث
            $t->unsignedInteger('leads')->nullable();          // عدد العملاء المحتملين
            $t->decimal('roi', 16, 3)->nullable();             // العائد التقديري من الحدث
            $t->uuid('att_id')->nullable();                    // مرفقات (عقد المشاركة، صور، تقرير)
            $t->string('status', 80)->nullable()->index();
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

        DB::statement('CREATE INDEX events_status_start_idx ON events (status, date_start)');
        DB::statement('CREATE INDEX events_company_start_idx ON events (company_id, date_start)');
        DB::statement('CREATE INDEX events_brand_created_idx ON events (brand_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
