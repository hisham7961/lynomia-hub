<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّع المسار الميدانيّ — المرحلة ج من العمليات الميدانية.
 *
 * **قواعدُ الخصوصية مفروضةٌ من النموذج فصاعداً** (المواصفةُ تنصّ عليها):
 *  — لا تتبّعَ إلا ضمن **جلسة يوم ميدانيّ نشطة ومصرَّح بها وظاهرة** للموظف؛
 *    الجلسةُ لها بدايةٌ ونهايةٌ صريحتان، ولا نقطةَ تُقبل بعد إغلاقها.
 *  — الموافقةُ مختومةٌ على الجلسة (`consent_at`) — لا تتبّعَ بلا إقرار.
 *  — GPS **لا يحدّد الحضور**: هذا سجلُّ مسارٍ منفصلٌ عن جدول attendance تماماً.
 *  — النقاطُ الخام لها سياسةُ احتفاظٍ (تُقلَّم)، والمسارُ المبسَّط يبقى.
 *
 *  — **`track_sessions`**: جلسةُ التتبّع — لمن، وأي يومٍ ميدانيّ، ومتى بدأت
 *    وانتهت، وملخّصُها (عدد النقاط، المسافة، المدّة).
 *  — **`track_points`**: النقاط الخام — إحداثيةٌ ودقّةٌ ولحظةُ التقاط، ومعرّفُ
 *    عمليةِ العميل (`client_operation_id`) **فريدٌ لكل جلسة** فمنعُ التكرار عند
 *    إعادة الإرسال بنيويّ لا اجتهاديّ (أول idempotency على مستوى العنصر).
 *
 * down() فارغة عمداً — لا هجرة مدمّرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('track_sessions')) {
            Schema::create('track_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('emp_id')->index();                   // المندوب — ref hr
                $t->uuid('user_id')->nullable()->index();      // حسابه (لربط الجلسة بالمصدر)
                $t->date('field_day')->index();                // يومُه الميدانيّ (يرتبط بـ attendance منطقياً لا بنيوياً)
                $t->string('status', 20)->default('نشطة');     // نشطة · منتهية · ملغاة
                $t->timestamp('consent_at')->nullable();       // ختمُ الموافقة — لا تتبّع بلا إقرار
                $t->timestamp('started_at')->nullable();
                $t->timestamp('ended_at')->nullable();
                $t->unsignedInteger('point_count')->default(0);
                $t->unsignedInteger('distance_m')->default(0); // مسافةُ المسار بالمتر (تُحدَّث دفعةً)
                $t->json('simplified')->nullable();            // خطُّ المسار المبسَّط [[lat,lng],…]
                $t->string('device', 200)->nullable();
                $t->uuid('company_id')->nullable()->index();
                $t->uuid('project_id')->nullable()->index();
                $t->json('meta')->nullable();
                $t->integer('version')->default(1);
                $t->boolean('archived')->default(false)->index();
                $t->uuid('created_by')->nullable()->index();
                $t->uuid('updated_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['emp_id', 'field_day']);
                $t->index(['status', 'started_at']);
            });
        }

        if (! Schema::hasTable('track_points')) {
            Schema::create('track_points', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('session_id')->index();
                $t->decimal('lat', 10, 7);
                $t->decimal('lng', 10, 7);
                $t->unsignedSmallInteger('accuracy_m')->nullable();  // دقّةُ القراءة — للترشيح
                $t->timestamp('captured_at')->index();               // لحظةُ الالتقاط على الجهاز
                $t->string('client_operation_id', 80);               // معرّفُ عملية العميل — منعُ التكرار
                $t->json('meta')->nullable();
                $t->timestamp('created_at')->nullable();
                // القيدُ الفريد **هو** المنع: نقطةٌ بمعرّفٍ مكرّرٍ في الجلسة نفسها تُرفض
                $t->unique(['session_id', 'client_operation_id'], 'track_points_op_uq');
                $t->index(['session_id', 'captured_at']);
            });
        }
    }

    public function down(): void
    {
        // لا هجرة مدمّرة
    }
};
