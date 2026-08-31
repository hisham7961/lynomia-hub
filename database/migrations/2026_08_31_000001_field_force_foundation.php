<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * أساس العمليات الميدانية — المرحلة أ من نظام المندوب الطبي.
 *
 * أربعة جداول جديدة وعمودٌ واحد على القائم:
 *
 *  — **`hcps`**: دليل مقدمي الرعاية الصحية (طبيب/صيدلي/ممرض…). سجلُّ معرفةٍ
 *    مهنيّ فحسب: تصنيفٌ وتخصصٌ ومنشآتُ عملٍ ومنطقة. **عمداً بلا أي حقلٍ
 *    بيعيّ**: لا باركود ولا كود إحالة ولا عمولة ولا ربطَ طلبات — فالمواصفة
 *    تمنعها نصاً، وحارسُ اختبارٍ (FieldForceGuardrailsTest) يمنع عودتها.
 *
 *  — **`facilities`**: المنشآت الصحية بإحداثياتها ونطاقها الجغرافي — أول
 *    أعمدة إحداثيات حقيقية في المشروع. `decimal(10,7)` يعطي دقة ~1سم،
 *    والنطاق بالمتر لأن «الوصول للمنشأة» يُقاس به لا بخطوط الطول وحدها.
 *    ربط الطبيب بمنشآته متعدد (`hcps.facility_ids`) لأن الطبيب يعمل في
 *    أكثر من مكان — وهذا نمطُ `multi` المعتمد في السجل كله.
 *
 *  — **`territories`**: المناطق بهرمية `parent_id` — أول مرجعٍ ذاتي في سجل
 *    الوحدات. المولّد العام يعرضه قائمةَ اختيارٍ مسطّحة، وقائمةُ الأبناء
 *    تأتي من لوحة «السجلات المرتبطة» القائمة بلا شيفرة جديدة.
 *
 *  — **`terrassigns`**: إسنادُ المناطق للمندوبين **مؤرَّخاً وبدور** — جدولٌ
 *    حقيقي لا مصفوفة JSON، لأن «من متى وبأي صفة» لا مكان له في نمط `multi`،
 *    والوحدةُ الحقيقية تكسب التدقيقَ والتنطيقَ والاعتمادَ الزمني مجاناً.
 *    التاريخ لا يُمحى: انتهاءُ الإسناد حالةٌ وتاريخُ نهاية، لا حذفُ صف.
 *
 *  — **`employees.field_role`**: ملفُ المندوب هو ملفُ الموظف نفسه — امتدادٌ
 *    لا نظامٌ موازٍ. الحقلُ يميّز من يعمل ميدانياً فتظهر له شاشاتُ الميدان.
 *
 * السلامة: `down()` فارغة عمداً — لا هجرةَ مُدمِّرة في هذا المستودع.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hcps')) {
            Schema::create('hcps', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300);
                $t->string('title', 120)->nullable();         // د. / أ.د. / صيدلي…
                $t->string('specialty', 120)->nullable()->index();
                $t->string('class', 80)->nullable()->index();  // تصنيف الأهمية أ/ب/ج
                $t->string('kind', 80)->nullable();            // طبيب/صيدلي/ممرض/إداري
                $t->string('phone', 100)->nullable();
                $t->string('email', 300)->nullable();
                $t->string('city', 120)->nullable();
                $t->string('area', 120)->nullable();
                $t->json('facility_ids')->nullable();          // منشآت عمله — multi كما نمط السجل
                $t->uuid('territory_id')->nullable()->index();
                $t->string('status', 80)->nullable()->index(); // نشط/غير نشط/منتقل/متقاعد
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
                $t->index(['territory_id', 'class']);          // استهداف الدورة: منطقة × تصنيف
            });
        }

        if (! Schema::hasTable('facilities')) {
            Schema::create('facilities', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300);
                $t->string('type', 80)->nullable()->index();   // مستشفى/عيادة/صيدلية…
                $t->string('city', 120)->nullable();
                $t->string('area', 120)->nullable();
                $t->string('address', 500)->nullable();
                $t->string('phone', 100)->nullable();
                $t->decimal('lat', 10, 7)->nullable();         // أول إحداثيات حقيقية في المشروع
                $t->decimal('lng', 10, 7)->nullable();
                $t->unsignedInteger('radius_m')->nullable();   // نطاق «الوصول» بالمتر
                $t->uuid('territory_id')->nullable()->index();
                $t->uuid('client_id')->nullable()->index();    // منشأةٌ قد تكون عميلاً — فيلحقها العزل
                $t->string('status', 80)->nullable()->index();
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
            });
        }

        if (! Schema::hasTable('territories')) {
            Schema::create('territories', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300);
                $t->string('code', 60)->nullable();
                $t->uuid('parent_id')->nullable()->index();    // الهرمية — أول مرجع ذاتي في السجل
                $t->string('kind', 80)->nullable();            // بلد/محافظة/منطقة/قطاع
                $t->uuid('manager_id')->nullable()->index();   // مشرف المنطقة (حساب نظام)
                $t->string('status', 80)->nullable()->index();
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
            });
        }

        if (! Schema::hasTable('terrassigns')) {
            Schema::create('terrassigns', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('name', 300)->nullable();           // يولَّد من المنطقة والمندوب إن تُرك
                $t->uuid('territory_id')->index();
                $t->uuid('emp_id')->index();                   // ref => hr كما اصطلاح الحضور
                $t->string('role', 80)->nullable();            // أساسي/مساند/مشرف
                $t->date('date_start')->nullable();
                $t->date('date_end')->nullable();
                $t->string('status', 80)->nullable()->index(); // ساري/منتهٍ
                $t->text('notes')->nullable();
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
                $t->index(['emp_id', 'status']);               // مناطق المندوب السارية — قراءة كل شاشة ميدانية
                $t->index(['territory_id', 'status']);
            });
        }

        if (! Schema::hasColumn('employees', 'field_role')) {
            Schema::table('employees', function (Blueprint $t) {
                $t->string('field_role', 80)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة — القاعدة الثابتة في هذا المستودع
    }
};
