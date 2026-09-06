<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (WP-5.5) تاريخُ نزاهة سلسلة التدقيق — صفٌّ لكل تشغيلٍ للفحص الكامل.
 *
 * الفحصُ الكامل (`hub:audit-verify`) كان يجري أسبوعياً وبزرّ مركز التشغيل ثم
 * **يتبخّر ناتجُه**: سطرُ طرفيةٍ أو رسالةُ فلاش. فسؤالُ المدقّق «متى فُحصت
 * السلسلة آخرَ مرة؟ وبماذا خرجت؟» (§45 · §1.6) كان بلا جواب. الآن كلُّ تشغيلٍ
 * — آليٍّ أو يدويٍّ — يكتب صفاً بعدّاداته ونتيجته وأول قيدٍ متأثّر.
 *
 * دليلُ نزاهةٍ لا تليمتري: يُعلَن في `HubBackup::RAW_TABLES` (لا يُقلَّم كما
 * `metric_points`)، وعلى تحميل الصفحات يبقى `Audit::verifyTail` وحده —
 * **لا فحصَ كامل أبداً في طلب**.
 *
 * الأعمدةُ النصّية بعرضٍ صريح وكتلة `Schema::create` حرفيةٌ لا حلقة
 * (critic #35) — فيراها `hub_col_widths()` وحرّاسُ العرض.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_verifications')) return;

        Schema::create('audit_verifications', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('mode', 8);                             // auto (مجدول/طرفية) | manual (زرّ مركز التشغيل)
            $t->uuid('initiated_by')->nullable();              // المستخدمُ المُشغِّل — null للآليّ
            $t->string('request_id', 40)->nullable();          // للربط بأثر الطلب (system.trace)
            $t->dateTime('started_at');
            $t->dateTime('finished_at')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->string('result', 8);                           // ok | warn | fail
            $t->unsignedInteger('checked_rows')->default(0);   // قيودٌ تحقّق محتواها فطابق بصمتَها
            $t->unsignedInteger('weak_rows')->default(0);      // مختومةٌ ببصمة الجيل الأول (v1)
            $t->unsignedInteger('unsealed_rows')->default(0);  // بلا بصمةٍ بعد بدء السلسلة — فشلُ ختم
            $t->unsignedInteger('mismatch_rows')->default(0);  // عمودُ الشركة يخالف شركةَ السجل الحالية
            $t->unsignedInteger('blank_rows')->default(0);     // بلا شركةٍ وسجلُّها اليوم داخل شركة
            $t->unsignedBigInteger('first_bad_id')->nullable(); // أول قيدٍ متأثّر عند الفشل
            $t->string('message', 500)->nullable();            // خلاصةُ الفاحص بلسانه — mb_substr عند الكاتب
            $t->index(['started_at'], 'audit_verifications_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_verifications');
    }
};
