<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دورات وزيارات المندوب — المرحلة ب من العمليات الميدانية.
 *
 *  — **`cycles`**: الدورة/الحملة الترويجية: نافذةٌ زمنية بأهداف تغطيةٍ وتكرارٍ
 *    على منطقةٍ ومنتجاتٍ من سجل المنتجات القائم. تُقاس التغطيةُ الفعلية من
 *    الزيارات المرتبطة (لا حقلٌ يُملأ يدوياً).
 *
 *  — **`visits`**: الزيارة — مخططةً كانت أو طارئةً أو فائتة. تحمل الطبيبَ
 *    والمنشأةَ والمندوبَ والدورةَ، وتقريراً مهيكلاً (الهدف، ما دار، النتيجة،
 *    الخطوة التالية)، ومنتجاتٍ عُرِضت (من سجل المنتجات)، وعيّناتٍ صُرِفت.
 *    موقعُها يُلتقط لحظةَ التنفيذ **بموافقةٍ** كنمط الحضور — لا تتبّعَ بعدها.
 *    وتتكامل مع «اليوم الميداني»: زيارةٌ نُفّذت في يومٍ ميدانيّ نشط.
 *
 * السلامة: `down()` فارغة عمداً — لا هجرة مدمّرة في هذا المستودع.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cycles')) {
            Schema::create('cycles', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300);
                $t->string('code', 60)->nullable();
                $t->string('type', 80)->nullable();            // دورة تغطية/حملة منتج/إطلاق
                $t->uuid('territory_id')->nullable()->index();
                $t->json('product_ids')->nullable();           // منتجات الحملة — multi ref products
                $t->date('date_start')->nullable();
                $t->date('date_end')->nullable();
                $t->unsignedInteger('target_visits')->nullable(); // هدف عدد الزيارات
                $t->unsignedInteger('frequency')->nullable();     // تكرار الزيارة لكل طبيب في الدورة
                $t->string('status', 80)->nullable()->index();    // مخطط/نشط/منتهٍ/ملغى
                $t->text('notes')->nullable();
                $t->json('tags')->nullable();
                $t->uuid('company_id')->nullable()->index();
                $t->uuid('project_id')->nullable()->index();
                $t->json('custom')->nullable();
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['territory_id', 'status']);
            });
        }

        if (! Schema::hasTable('visits')) {
            Schema::create('visits', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300)->nullable();           // يولَّد: طبيب — تاريخ
                $t->uuid('hcp_id')->nullable()->index();       // ref hcps
                $t->uuid('facility_id')->nullable()->index();  // ref facilities
                $t->uuid('cycle_id')->nullable()->index();     // ref cycles
                $t->uuid('emp_id')->nullable()->index();       // المندوب — ref hr
                $t->string('kind', 40)->nullable();            // مخططة/طارئة/فائتة
                $t->string('status', 80)->nullable()->index(); // مخطط/تمت/فائتة/ملغاة
                $t->date('planned_date')->nullable()->index();
                $t->dateTime('visit_at')->nullable();          // لحظة التنفيذ الفعلية
                $t->text('objective')->nullable();             // هدف الزيارة
                $t->text('discussion')->nullable();            // ما دار
                $t->text('outcome')->nullable();               // النتيجة
                $t->text('next_action')->nullable();           // الخطوة التالية
                $t->json('product_ids')->nullable();           // منتجات عُرِضت — multi ref products
                $t->text('samples')->nullable();               // عيّنات صُرِفت (منتج × كمية)
                $t->string('geo', 80)->nullable();             // موقع لحظة التنفيذ (بموافقة)
                $t->uuid('territory_id')->nullable()->index();
                $t->uuid('client_id')->nullable()->index();
                $t->text('notes')->nullable();
                $t->json('tags')->nullable();
                $t->uuid('company_id')->nullable()->index();
                $t->uuid('project_id')->nullable()->index();
                $t->json('custom')->nullable();
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['emp_id', 'planned_date']);         // خطة المندوب اليومية
                $t->index(['cycle_id', 'status']);             // تغطية الدورة
                $t->index(['hcp_id', 'visit_at']);             // تكرار زيارة الطبيب
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
