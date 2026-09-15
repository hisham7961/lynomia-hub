<?php

use App\Models\Attendance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **تصفيرُ خاناتِ الوقتِ في صفوفِ الحضورِ القائمة** (التحقّقُ المستقلّ الثاني عشر).
 *
 * `time_in`/`time_out` عمودا نصٍّ يُقارَنان كنصّ، ونموذجُ الإدخالِ كان يقبل الساعةَ
 * بخانةٍ واحدة. و`'9:00' > '23:00:00'` معجميّاً — فتفوز ورديّةُ الصباحِ المنسيّةُ على
 * ورديّةِ الليلِ المفتوحة، وتعود مهلةُ التقريرِ اثنتين وعشرين ساعةً إلى الوراء.
 *
 * النموذجُ يُصفّر عند الكتابةِ من الآن (`Attendance::normTime`)، وهذه الهجرةُ تُصلح
 * ما كُتب قبلَه. **إضافةٌ لا كسر:** لا صفَّ يُحذف، ولا عمودَ يُسقَط، ولا قيمةَ تتغيّر
 * دلالتُها — `9:00` و`09:00:00` **اللحظةُ نفسُها**، وإنّما تُكتب بصيغةٍ تُقارَن صواباً.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('attendance')) return;

        $fixed = 0;
        DB::table('attendance')->select('id', 'time_in', 'time_out')
            ->orderBy('id')->chunk(500, function ($rows) use (&$fixed) {
                foreach ($rows as $r) {
                    $in = Attendance::normTime($r->time_in);
                    $out = Attendance::normTime($r->time_out);
                    if ((string) $in === (string) $r->time_in && (string) $out === (string) $r->time_out) continue;

                    DB::table('attendance')->where('id', $r->id)
                        ->update(['time_in' => $in, 'time_out' => $out]);
                    $fixed++;
                }
            });

        if ($fixed) echo "  ↳ صُفِّرت خاناتُ الوقتِ في {$fixed} صفّاً\n";
    }

    public function down(): void
    {
        // لا رجوع: الصيغةُ القانونيّةُ هي الصحيحة، والقيمةُ لم تتغيّر دلالتُها
    }
};
