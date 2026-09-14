<?php

use App\Models\Attendance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تعبئةُ اللحظتين للصفوفِ القائمة** (التحقّق المستقلّ · DB-02).
 *
 * أضافت هجرةُ v2.510.0 عمودَي `in_at`/`out_at` **ولم تملأهما للقائم**، فبقي
 * مئةٌ وخمسةٌ وخمسون صفّاً بـ`NULL`: من يقرأ اللحظةَ المطلقةَ يجدها للجديدِ
 * وحدَه. وهذا **نصفُ إضافةٍ** — والعمودُ الذي يُملأ لبعضِ الصفوفِ دون بعضٍ
 * يُغري قارئَه بأن يظنَّ الفراغَ معنىً.
 *
 * والتعبئةُ **اشتقاقٌ لا تخمين**: الصفوفُ القائمةُ كلُّها بلا رايةِ عبور
 * (العمودُ افتراضُه `false` والرايةُ لم تكن موجودةً قبلَ أمس)، فلحظاتُها تُشتقّ
 * من `date` + الوقتِ مباشرةً بلا أيِّ افتراضِ عبور. وما لا وقتَ له يبقى `NULL`.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('attendance')) return;
        foreach (['in_at', 'out_at'] as $c) {
            if (! Schema::hasColumn('attendance', $c)) return;
        }

        DB::table('attendance')
            ->whereNull('in_at')->whereNull('out_at')
            ->whereNotNull('date')
            ->select('id', 'date', 'time_in', 'time_out', 'overnight')
            ->orderBy('id')->chunk(500, function ($rows) {
                foreach ($rows as $r) {
                    [$in, $out] = Attendance::instants(
                        $r->date, $r->time_in, $r->time_out, (bool) ($r->overnight ?? false));
                    if ($in === null && $out === null) continue;
                    DB::table('attendance')->where('id', $r->id)->update([
                        'in_at' => $in?->toDateTimeString(),
                        'out_at' => $out?->toDateTimeString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // لا عكس: العمودان يُسقطان في هجرةِ إنشائهما، والتعبئةُ اشتقاقٌ لا بيانات
    }
};
