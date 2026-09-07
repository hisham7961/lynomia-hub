<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الطور F · WP-F.1 · §25–27) **المحطة = المقعدُ الدائم** — كيانٌ داخليٌّ مُدارٌ
 * بالبيانات فوق `ModuleController` (CRUD/scope مجّاناً)، **لا** إعادةُ استخدامٍ
 * لجدول المرافق الصحّية `facilities`: المرافقُ منشآتٌ طبّية، والمحطةُ مقعدُ عملٍ
 * له كودٌ يُطبَع ويُمسَح.
 *
 * **داخليّةٌ فقط — بلا `client_id` عمداً (قاعدةُ الطور F الأمنيّة):** المحطاتُ
 * بنيةٌ تشغيليّةٌ لا تُعرَض لعميل؛ فلا عمودَ عميلٍ يعزلها `hub_scope`، والعزلُ
 * الصلبُ عن حساب العميل في `PortalGuard` (قائمةٌ بيضاء لا تضمّ stations → ٤٠٤).
 * والعزلُ بين الشركات عبر `company_id` (رصيفُ `hub_company_col`).
 *
 * **الكودُ يُولَّد لا يُطلَب** (نمطُ `Asset::nextCode`): فريدٌ بفهرسٍ، ويُعاد توليدُه
 * عند التصادم في `Station::save`. و`current_employee_id` **حقلٌ مقفل** يُكتَب عبر
 * مسار الإسناد/الإخلاء المقفل وحدَه (`StationController` على نمط `Custody::move`)
 * لا من نموذج CRUD العامّ — فكلُّ تغييرِ مقعدٍ مُدقَّق.
 *
 * **عرضٌ مُعلَنٌ حرفيّاً وبلا DB enum (درسُ C10):** `status`/`type`/`dept` نصوصٌ
 * واسعةٌ تُتحقَّق قيمُها من سجل الوحدة (allowlist) لا `$t->enum` — فإضافةُ قيمةٍ
 * سطرٌ في `config/hub.php` لا ALTER شبهُ مدمِّرٍ على MySQL. والأعراضُ صريحةٌ هنا
 * فيحرسها `ColumnFitsItsWriterTest` على عرض MySQL الصارم.
 *
 * إضافيّةٌ محروسة (add-if-not-exists)، كتلةٌ حرفيّةٌ واحدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stations')) {
            Schema::create('stations', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // كودٌ مولَّدٌ فريد (ST-2026-0001) — يُطبَع على الملصق ويُمسَح بـs/{code}.
                // العرضُ ٦٠ نظيرَ assets.code تماماً: حقلُ CRUD نصّيّ (type=text) والحارسُ
                // العامّ ColumnWidthGuard يرفض عمودَ نصٍّ أضيقَ من ٦٠ ولو كان الكودُ قصيراً.
                $t->string('code', 60)->unique();
                // العزلُ بين الشركات (بلا client_id — داخليّةٌ فقط)
                $t->uuid('company_id')->nullable()->index();
                $t->uuid('project_id')->nullable()->index();
                // موضعُ المقعد — نصوصٌ واسعةٌ بعرضٍ صريح
                $t->string('facility', 120)->nullable();     // المبنى/المنشأة
                $t->string('floor', 60)->nullable();         // الطابق
                $t->string('zone', 60)->nullable();          // المنطقة
                $t->string('room', 60)->nullable();          // الغرفة
                $t->string('desk', 60)->nullable();          // المكتب/الرقم
                $t->string('type', 60)->nullable();          // نوعُ المقعد — allowlist في السجل (لا DB enum)
                $t->string('dept', 80)->nullable();          // القسم
                $t->string('status', 80)->nullable();        // الحالة — allowlist في السجل (لا DB enum)
                // مقعدٌ مشغولٌ الآن بمن؟ — حقلٌ مقفلٌ يُكتَب عبر الإسناد/الإخلاء وحدَه
                $t->uuid('current_employee_id')->nullable();
                $t->json('custom')->nullable();              // الحقولُ المخصَّصة (ModuleController)
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);          // القفلُ التفاؤليّ (HasVersions)
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                // فهارسُ القراءةِ الشائعة (§WP-F.1): «محطاتُ شركةٍ»، «المتاحُ منها»،
                // «حسب النوع/القسم»، و«مقعدُ فلان»
                $t->index('status', 'stations_status_idx');
                $t->index('type', 'stations_type_idx');
                $t->index('dept', 'stations_dept_idx');
                $t->index('current_employee_id', 'stations_cur_emp_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
