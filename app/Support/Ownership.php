<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مَن يملك هذا العمل؟ — اقتراحٌ من الأدلّة لا اختراعٌ من فراغ.**
 *
 * قيل في v2.539.0 إنّ إسنادَ مالكٍ لكلِّ مؤشّرٍ «قرارُ إنسانٍ لا يُتّخَذ آليّاً»،
 * وهو نصفُ الحقيقة. والنصفُ الآخر أنّ **النظامَ يطرح السؤالَ ولا يعين على جوابه**:
 * إحدى وخمسون قائمةً منسدلةً فارغةً ليست قراراً، هي عبء. فتُترَك كلُّها، ويبقى
 * «خارج الهدف» بلا من يُسأل عنه — وهو عينُ ما أرادت بطاقةُ المالكِ منعَه.
 *
 * والمخرجُ ليس اختراعَ مالك — **النظامُ يعرف من يعمل في كلِّ وحدة**:
 *
 *  · **أثرُ التدقيق** (`audits.module` + `user_id`) يسجّل كلَّ فعلٍ وقع فعلاً؛
 *  · **أعمدةُ الإسناد** في الوحدةِ نفسِها (`assignee_id`/`owner_id`/`manager_id`)
 *    تقول لمن العملُ الآن.
 *
 * فيُقترَح الأكثرُ فعلاً **ومعه دليلُه معروضاً**، ويبقى القرارُ نقرةَ مراجعة.
 *
 * ═══ وثلاثةُ حرّاسٍ تحفظ صدقَ الاقتراح ═══
 *
 * **١) لا يُقترَح من لا يبلغ الوحدة.** وإلّا أُعيد عيبُ M-A1 حرفيّاً: كُلِّف
 *    بالمساءلةِ من لا يفتح الباب، فيصمت — والصمتُ يُقرأ انتظاراً. فكلُّ مرشّحٍ
 *    يمرّ بـ`hub_can(…, 'v')` قبل أن يُسمّى.
 *
 * **٢) لا مرشّحَ ⇒ يُقال ذلك.** `confidence = none` وسببٌ مكتوب، ولا يُملأ
 *    الحقلُ بأوّلِ اسمٍ في الدليل. مالكٌ مخترَعٌ أسوأُ من خانةٍ فارغة: الفارغةُ
 *    تُرى، والمخترَعُ يُصدَّق.
 *
 * **٣) الترتيبُ حتميّ.** العددُ نازلاً ثمّ `id` صاعداً — فلا يختلف الاقتراحُ
 *    بين محرّكٍ ومحرّك (انظر CLAUDE.md: ترتيبُ الصفوف قرعةٌ إلّا بما يُطلَب).
 */
final class Ownership
{
    /** نافذةُ النظر — ستّةُ أشهر: أقصرُ منها يُهمل موسميّاً، وأطولُ يُبقي من غادر */
    public const WINDOW_DAYS = 180;

    /** أعمدةُ الإسنادِ المعروفة، بترتيبِ قوّتِها كدليلِ ملكيّة */
    private const ASSIGN_COLS = ['owner_id', 'manager_id', 'assignee_id'];

    /**
     * **مَن عمل فعلاً في هذه الوحدة** — مرتَّبين بقوّةِ الدليل.
     *
     * @return array<int, array{id:string, name:string, n:int, acts:int, holds:int}>
     */
    public static function actors(string $module, int $days = self::WINDOW_DAYS): array
    {
        $def = hub_mod($module);
        if (! $def) return [];

        $score = [];   // id ⟵ ['acts' => أفعالٌ في الأثر, 'holds' => عملٌ مُسنَدٌ إليه]

        // ① أثرُ التدقيق: فعلٌ وقع فعلاً، وهو أقوى دليلٍ على من يشتغل بالوحدة
        if (Schema::hasTable('audits')) {
            $rows = DB::table('audits')->where('module', $module)->whereNotNull('user_id')
                ->where('created_at', '>=', now()->subDays($days))
                ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->get();
            foreach ($rows as $r) $score[(string) $r->user_id]['acts'] = (int) $r->n;
        }

        // ② أعمدةُ الإسناد: لمن العملُ الآن. عمودٌ واحدٌ يكفي — أوّلُ ما تملكه الوحدة
        $table = (string) ($def['table'] ?? '');
        if ($table !== '' && Schema::hasTable($table)) {
            foreach (self::ASSIGN_COLS as $col) {
                if (! Schema::hasColumn($table, $col)) continue;

                $q = DB::table($table)->whereNotNull($col)->where($col, '!=', '');
                if (Schema::hasColumn($table, 'deleted_at')) $q->whereNull('deleted_at');

                foreach ($q->selectRaw("{$col} as uid, count(*) as n")->groupBy($col)->get() as $r) {
                    $score[(string) $r->uid]['holds'] = (int) $r->n;
                }
                break;                                   // أقوى عمودٍ تملكه، لا جمعُ العمودين
            }
        }

        if (! $score) return [];

        // ③ الحارسُ الأوّل: **لا يُسمّى من لا يبلغ الوحدة** (درسُ M-A1)
        $users = \App\Models\User::whereIn('id', array_keys($score))
            ->where('status', '!=', 'موقوف')->orderBy('id')->get();

        $out = [];
        foreach ($users as $u) {
            if (! hub_can($u, $module, 'v')) continue;

            $acts  = (int) ($score[(string) $u->id]['acts'] ?? 0);
            $holds = (int) ($score[(string) $u->id]['holds'] ?? 0);
            if ($acts + $holds < 1) continue;

            $out[] = ['id' => (string) $u->id, 'name' => (string) $u->name,
                      'n' => $acts + $holds, 'acts' => $acts, 'holds' => $holds];
        }

        // ④ ترتيبٌ حتميّ: العددُ نازلاً ثمّ `id` صاعداً — لا قرعةَ محرّك
        usort($out, fn ($a, $b) => [$b['n'], $a['id']] <=> [$a['n'], $b['id']]);

        return $out;
    }

    /**
     * **اقتراحُ مالكٍ لوحدة** — ومعه دليلُه ودرجةُ الثقةِ به.
     *
     * @return array{user_id:?string, name:?string, why:string, confidence:string, runners:array}
     */
    public static function suggest(string $module, int $days = self::WINDOW_DAYS): array
    {
        $none = fn (string $why) => ['user_id' => null, 'name' => null, 'why' => $why,
                                     'confidence' => 'none', 'runners' => []];

        if (! ($def = hub_mod($module))) return $none('وحدةٌ غيرُ مسجَّلة');

        $actors = self::actors($module, $days);
        if (! $actors) {
            return $none('لا أثرَ عملٍ في «' . ($def['label'] ?? $module) . '» خلال '
                . $days . ' يوماً — لا مرشّحَ من البيانات، والمالكُ يُختار باليد');
        }

        $top = $actors[0];
        $next = $actors[1]['n'] ?? 0;

        /*
         * **الثقةُ تُقاس ولا تُدَّعى:** «قويّة» تعني أنّ الأوّلَ يحمل ضِعفَ ما
         * يحمله تاليه **وثلاثةَ أفعالٍ فأكثر** — فارقٌ لا يُفسَّر صدفةً. وما
         * دون ذلك «ضعيفة»: يُعرَض ويُراجَع، ولا يُعتمَد جملةً بلا نظر.
         */
        $strong = $top['n'] >= 3 && $top['n'] >= $next * 2 && $top['n'] > $next;

        $bits = [];
        if ($top['holds']) $bits[] = $top['holds'] . ' سجلاً مُسنَداً إليه';
        if ($top['acts'])  $bits[] = $top['acts'] . ' فعلاً في سجلّ التدقيق';

        return [
            'user_id' => $top['id'],
            'name' => $top['name'],
            'why' => implode(' · ', $bits) . ' في «' . ($def['label'] ?? $module) . '»'
                . ($next ? ' — وتاليه ' . $next : ' — ولا منافسَ له'),
            'confidence' => $strong ? 'strong' : 'weak',
            'runners' => array_slice($actors, 1, 3),
        ];
    }

    /**
     * **اقتراحُ مالكٍ لمؤشّر** — من وحدةِ معادلته لا من اسمه.
     *
     * والمعادلةُ قد تجمع وحدتين (نسبةٌ بين مقياسين)؛ فالمُعتَبرُ وحدةُ المقياسِ
     * الأوّل: هي التي يقيسها المؤشّرُ، والثانيةُ مقامٌ يُنسَب إليه.
     */
    public static function suggestForKpi(array $formula, int $days = self::WINDOW_DAYS): array
    {
        $module = hub_str($formula['a']['module'] ?? '');

        return $module === ''
            ? ['user_id' => null, 'name' => null, 'confidence' => 'none', 'runners' => [],
               'why' => 'معادلةٌ بلا وحدةٍ معلومة — لا مرشّحَ يُشتقّ منها']
            : self::suggest($module, $days);
    }
}
