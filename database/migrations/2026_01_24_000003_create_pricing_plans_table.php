<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** الباقات والتسعير — باقات كل خدمة/منتج مع الأسعار والحدود والمزايا وتاريخ تغير السعر */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pricing_plans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 200);                           // اسم الباقة
            $t->uuid('service_id')->nullable()->index();       // الخدمة أو المنتج التابعة له
            $t->decimal('price', 16, 3)->nullable();           // السعر المعلن
            $t->string('currency', 80)->nullable()->index();
            $t->string('cycle', 80)->nullable()->index();      // دورة الفوترة
            $t->string('market', 200)->nullable()->index();    // السوق المستهدف (الكويت، الخليج…)
            $t->text('limits')->nullable();                    // الحدود والكميات (رسائل، مستخدمون، مساحة)
            $t->text('features')->nullable();                  // المزايا المشمولة في الباقة
            $t->boolean('free_tier')->default(false)->index(); // باقة مجانية
            $t->text('addons')->nullable();                    // الإضافات المتاحة بسعر منفصل
            $t->text('discounts')->nullable();                 // الخصومات وشروطها
            $t->date('effective_from')->nullable()->index();   // تاريخ سريان السعر الحالي
            $t->decimal('prev_price', 16, 3)->nullable();      // السعر السابق قبل آخر تعديل
            $t->date('price_changed_at')->nullable()->index(); // تاريخ آخر تغيير للسعر
            $t->decimal('unit_cost', 16, 3)->nullable();       // التكلفة التقديرية لتقديم الباقة — لتحليل هامش الربح
            $t->string('status', 80)->nullable()->index();
            $t->json('custom')->nullable();
            $t->json('meta')->nullable();
            $t->integer('version')->default(1);
            $t->boolean('archived')->default(false)->index();
            $t->uuid('created_by')->nullable()->index();
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::statement('CREATE INDEX pricing_plans_service_status_idx ON pricing_plans (service_id, status)');
        DB::statement('CREATE INDEX pricing_plans_status_eff_idx ON pricing_plans (status, effective_from)');
        DB::statement('CREATE INDEX pricing_plans_service_created_idx ON pricing_plans (service_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plans');
    }
};
