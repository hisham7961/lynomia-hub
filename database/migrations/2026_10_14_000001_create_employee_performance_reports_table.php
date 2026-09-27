<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **تقريرُ أداء الموظّف بالذكاء** (`App\Support\Ai\Reports\EmployeePerformance`).
 *
 * صفٌّ لكلِّ (موظّف، فترة) — `2026-09` شهراً أو `2026-W39` أسبوعاً — والتاريخُ باقٍ فترةً بفترة.
 * الحقائقُ الحتميّة (`facts`) تُحسب في الخادم أوّلاً بلا ذكاء، والسردُ (`narrative`) من النموذج فوقها.
 * وبصمةُ ما أُرسِل (`facts_hash`) تمنع نداءً ثانياً لبياناتٍ لم تتغيّر. والإخفاقُ يُسجَّل حالةً ورمزاً
 * **ولا يمسّ السردَ السابق**.
 *
 * إضافيّةٌ فقط (CLAUDE.md) — لا تمسّ جدولاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_performance_reports')) return;

        Schema::create('employee_performance_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('employee_id');
            $t->uuid('company_id')->nullable()->index();
            $t->string('period', 10);                            // 2026-09 | 2026-W39
            $t->string('period_kind', 8)->default('month');      // month | week
            $t->date('period_from')->nullable();
            $t->date('period_to')->nullable();
            $t->date('as_of')->nullable();                       // آخرُ يومٍ شملته الحقائق
            $t->json('facts')->nullable();                       // الأرقامُ الحتميّة
            $t->json('narrative')->nullable();                   // سردُ النموذج — آخرُ ما نجح
            $t->char('facts_hash', 64)->nullable();              // بصمةُ ما أُرسِل في آخر نجاح
            $t->string('model', 190)->nullable();
            $t->string('status', 10)->default('ok');             // ok | failed
            $t->string('error_code', 40)->nullable();
            $t->uuid('usage_event_id')->nullable();
            $t->timestamp('generated_at')->nullable();           // آخرُ توليدٍ ناجح
            $t->timestamp('attempted_at')->nullable();           // آخرُ فحصٍ أو محاولة
            $t->timestamps();

            $t->unique(['employee_id', 'period'], 'employee_perf_reports_emp_period_uq');
            $t->index(['employee_id', 'period_from'], 'employee_perf_reports_emp_from_idx');
        });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
