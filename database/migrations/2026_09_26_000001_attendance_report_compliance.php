<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * (الحضور × التقرير × الامتثال · §54/§55) **حقولٌ إضافيّةٌ دنيا لفصلِ الحقائق الثلاث.**
 *
 * القاعدةُ (§6): الحضورُ الفيزيائيُّ يبقى في `attendance.status` ولا يُطمَس. الامتثالُ
 * التقريريُّ والأثرُ الفعّال **يُشتقّان حيّاً** في مُحلِّلٍ مركزيّ (`DailyWorkCompliance`)
 * لا يُخزَّنان — فلا عمودَ حالةٍ محسوبةٍ يتبيّت. لا يُخزَّن هنا إلّا ما **لا يُشتقّ**:
 *
 *  - `report_deadline_at` — مهلةُ التقرير المختومةُ عند الانصراف (لعرضِها ولمرشّحِ
 *    أمرِ المصالحة الكفء §51/§52؛ والمُحلِّلُ يُعيد اشتقاقها حيّاً فلا يتبيّت بها).
 *  - `compliance_outcome` — الأثرُ الفعّال **المُقفَل** بقرارِ HR/مدير (غياب بسبب عدم
 *    التقرير · معذور · حاضر بعد قبول تقريرٍ متأخر) — قرارٌ لا يُشتقّ ولا يُعاد كتابتُه
 *    صامتاً بتقريرٍ لاحق (§41).
 *  - `compliance_finalized_at` / `compliance_finalized_by` / `compliance_note` — ختمُ
 *    القرار: متى، من، ولماذا (§90/§91 التصحيحُ اليدويّ المُدقَّق).
 *
 * وعلى `work_updates` (§13/§55) دلالاتُ التقديمِ والمراجعةِ الدنيا: `submitted_at`
 * (زمنُ التقديم — يُملأ للصفوفِ القائمة من `created_at` فتُحتسب كما هي)، وحالةُ مراجعةٍ
 * خفيفة (`review_status`/`reviewed_by`/`reviewed_at`/`review_feedback`) — لا محرّكَ
 * موافقاتٍ ثانٍ، ولا عمودٌ مكرّر (`done` يبقى نصَّ الإنجاز، لا `work_done_v2`).
 *
 * إضافيّةٌ محروسةٌ بـ`hasColumn`، عكوسةٌ، غيرُ مُدمِّرة — لا تمسّ صفّاً قائماً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance')) {
            Schema::table('attendance', function (Blueprint $t) {
                if (! Schema::hasColumn('attendance', 'report_deadline_at')) {
                    $t->timestamp('report_deadline_at')->nullable()->after('hours');
                }
                if (! Schema::hasColumn('attendance', 'compliance_outcome')) {
                    // الأثرُ الفعّالُ المُقفَل — طولٌ كافٍ للحالاتِ القانونيّة
                    $t->string('compliance_outcome', 40)->nullable()->after('report_deadline_at');
                }
                if (! Schema::hasColumn('attendance', 'compliance_finalized_at')) {
                    $t->timestamp('compliance_finalized_at')->nullable()->after('compliance_outcome');
                }
                if (! Schema::hasColumn('attendance', 'compliance_finalized_by')) {
                    $t->uuid('compliance_finalized_by')->nullable()->after('compliance_finalized_at');
                }
                if (! Schema::hasColumn('attendance', 'compliance_note')) {
                    $t->string('compliance_note', 500)->nullable()->after('compliance_finalized_by');
                }
            });

            // مرشّحُ المصالحة الكفء (§51): «أيامٌ فات موعدُها ولم تُقفَل بعد» بلا مسحٍ كامل
            $this->safeIndex('attendance', ['report_deadline_at', 'compliance_finalized_at'], 'attend_deadline_final');
        }

        if (Schema::hasTable('work_updates')) {
            Schema::table('work_updates', function (Blueprint $t) {
                if (! Schema::hasColumn('work_updates', 'submitted_at')) {
                    $t->timestamp('submitted_at')->nullable()->after('work_date');
                }
                if (! Schema::hasColumn('work_updates', 'review_status')) {
                    // null = بلا مراجعةٍ بعد (بانتظار ضمناً) · pending_review/accepted/needs_revision
                    $t->string('review_status', 24)->nullable()->after('submitted_at');
                }
                if (! Schema::hasColumn('work_updates', 'reviewed_by')) {
                    $t->uuid('reviewed_by')->nullable()->after('review_status');
                }
                if (! Schema::hasColumn('work_updates', 'reviewed_at')) {
                    $t->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                }
                if (! Schema::hasColumn('work_updates', 'review_feedback')) {
                    $t->string('review_feedback', 1000)->nullable()->after('reviewed_at');
                }
            });

            // الصفوفُ القائمة: زمنُ تقديمِها هو زمنُ إنشائها — فتُحتسب تقاريرَ صالحةً كما كانت
            \Illuminate\Support\Facades\DB::table('work_updates')
                ->whereNull('submitted_at')->update(['submitted_at' => \Illuminate\Support\Facades\DB::raw('created_at')]);

            // قراءاتٌ حتميّةٌ لمركز المراجعة: بالحالةِ ثمّ اليومِ ثمّ المعرّف
            $this->safeIndex('work_updates', ['review_status', 'work_date', 'id'], 'wu_review_date');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance')) {
            Schema::table('attendance', function (Blueprint $t) {
                foreach (['report_deadline_at', 'compliance_outcome', 'compliance_finalized_at',
                    'compliance_finalized_by', 'compliance_note'] as $c) {
                    if (Schema::hasColumn('attendance', $c)) $t->dropColumn($c);
                }
            });
        }
        if (Schema::hasTable('work_updates')) {
            Schema::table('work_updates', function (Blueprint $t) {
                foreach (['submitted_at', 'review_status', 'reviewed_by', 'reviewed_at', 'review_feedback'] as $c) {
                    if (Schema::hasColumn('work_updates', $c)) $t->dropColumn($c);
                }
            });
        }
    }

    /** فهرسٌ آمنٌ: يُتخطّى بصمتٍ إن وُجد — لا يُسقط الهجرةَ على إعادةِ التشغيل */
    private function safeIndex(string $table, array $cols, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
        } catch (\Throwable $e) {
            // موجودٌ سلفاً — لا ضير
        }
    }
};
