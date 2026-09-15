<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **موظّفٌ ويومٌ = صفٌّ واحد — في القاعدة لا في بابٍ واحد** (مجلس الخبراء · N-15).
 *
 * `day_key` مفتاحٌ **مشتقٌّ** لا بيانات: يحمل اليومَ ما دام الصفُّ حيّاً، ويُفرَّغ
 * عند الحذفِ الناعم — فالفهرسُ الفريدُ يحرس الأحياءَ ولا يحجز يوماً لصفٍّ ميّت
 * (المحرِّكان يعدّان الفراغاتِ متمايزة). نظيرُ `payroll_runs.month_key` حرفاً.
 *
 * **ولا صفَّ يُمسّ:** التعبئةُ تُعطي المفتاحَ للأحياءِ غيرِ المكرَّرة؛ وحيث وُجد
 * تكرارٌ قائمٌ يُعطى **الأحقُّ** (صاحبُ وقتِ الدخول، ثمّ الأقدمُ إدراجاً) ويُترك
 * الباقي بمفتاحٍ فارغ — **يبقى في الجدولِ ويُقرأ**، ولا يمنع الترقية. والفريدُ
 * يُضاف حين تسمح البياناتُ فقط، والحارسُ في النموذج يبقى خطَّ الدفاعِ الأوّل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance')) return;

        if (! Schema::hasColumn('attendance', 'day_key')) {
            Schema::table('attendance', function (Blueprint $t) {
                $t->date('day_key')->nullable()->after('date');
            });
        }

        // ── تعبئةٌ على دفعات: الأحقُّ وحدَه يحمل المفتاح ──
        $seen = [];
        DB::table('attendance')->select('id', 'emp_id', 'date', 'time_in', 'deleted_at')
            ->orderByRaw('CASE WHEN time_in IS NULL THEN 1 ELSE 0 END')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$seen) {
                foreach ($rows as $r) {
                    if ($r->deleted_at !== null) continue;
                    $day = substr((string) $r->date, 0, 10);
                    if ($day === '') continue;
                    $k = $r->emp_id . '|' . $day;
                    if (isset($seen[$k])) continue;      // مكرَّرٌ قائم: يبقى بلا مفتاح
                    $seen[$k] = true;
                    DB::table('attendance')->where('id', $r->id)->update(['day_key' => $day]);
                }
            });

        $idx = collect(Schema::getIndexes('attendance'))->pluck('name')->all();
        if (! in_array('attendance_emp_day_idx', $idx, true)) {
            Schema::table('attendance', function (Blueprint $t) {
                $t->index(['emp_id', 'day_key'], 'attendance_emp_day_idx');
            });
        }

        // الفريدُ حين تسمح البيانات — لا تُسقَط ترقيةٌ على مكرَّرٍ قديم
        $dupes = DB::table('attendance')->whereNotNull('day_key')
            ->select('emp_id', 'day_key')->groupBy('emp_id', 'day_key')
            ->havingRaw('COUNT(*) > 1')->count();

        if ($dupes === 0 && ! in_array('attendance_emp_day_uniq', $idx, true)) {
            try {
                Schema::table('attendance', function (Blueprint $t) {
                    $t->unique(['emp_id', 'day_key'], 'attendance_emp_day_uniq');
                });
            } catch (\Throwable $e) {
                // سباقُ إدراجٍ أثناء الهجرة — حارسُ النموذج يبقى خطَّ الدفاع
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('attendance')) return;
        $idx = collect(Schema::getIndexes('attendance'))->pluck('name')->all();
        Schema::table('attendance', function (Blueprint $t) use ($idx) {
            if (in_array('attendance_emp_day_uniq', $idx, true)) $t->dropUnique('attendance_emp_day_uniq');
            if (in_array('attendance_emp_day_idx', $idx, true)) $t->dropIndex('attendance_emp_day_idx');
        });
        if (Schema::hasColumn('attendance', 'day_key')) {
            Schema::table('attendance', fn (Blueprint $t) => $t->dropColumn('day_key'));
        }
    }
};
