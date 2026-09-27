<?php

namespace App\Support\Collaboration;

use App\Models\Comment;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * **بحثُ الرسائل عبر السطوح — القاعدةُ الواحدة** (§25 · خطّة التطبيق 4.2). مُستخرَجٌ
 * حرفاً من `MessageSearchController` كي يستدعيه الويبُ والجوال: نصُّ الرسائل في
 * المحرّك الواحد (خلاصة/قنوات/محادثات) بعزلٍ خادميّ —
 *  • الخلاصةُ منطَّقةٌ بشركة القارئ (نظيرُ `feedCompanyFilter`).
 *  • القنواتُ: عضويّتُه شرطُ الظهور (لا يبحث في قناةٍ ليس عضواً فيها).
 *  • المحادثاتُ المباشرة: خيوطُه وحدَها ضمن نطاق الشركة.
 *
 * يعيد الصفوفَ الخامَ (الموديلات) مرتّبةً حتميّاً، وكلُّ سطحٍ يبني عرضَه ورابطَه.
 */
final class MessageSearch
{
    /** أقصرُ نصٍّ يُبحث به — ما دونه لا بحث (لا مسحَ للجداول كلِّها بحرفٍ واحد) */
    public const MIN_CHARS = 2;

    /** سقفُ ما يُعاد — والعدُّ الكلّيُّ يُحسب منفصلاً (`total`) */
    public const LIMIT = 50;

    /**
     * @return array{rows: Collection, total: int} كلُّ صفٍّ
     *   `['type' => 'feed'|'channel'|'dm', 'model' => Comment|DmMessage, 'author' => ?string, 'at' => mixed]`
     */
    public static function run(User $me, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < self::MIN_CHARS) return ['rows' => collect(), 'total' => 0];

        $meId = (string) $me->getKey();
        // هروبُ أحرف البدل — نصٌّ يبحثه المستخدم لا نمطُ LIKE
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';

        $memberConvIds = ConversationMember::where('user_id', $meId)->pluck('conversation_id');

        $feedQ = fn () => self::feedScope($me,
            Comment::whereNull('deleted_at')->where('module', 'feed')->where('body', 'LIKE', $like));
        $chanQ = fn () => Comment::whereNull('deleted_at')->where('module', 'channel')
            ->whereIn('conversation_id', $memberConvIds)->where('body', 'LIKE', $like);
        $dmQ = fn () => DmMessage::alive()->inCompanyScope()
            ->where(fn ($w) => $w->where('from_id', $meId)->orWhere('to_id', $meId))
            ->where('body', 'LIKE', $like);

        $rows = collect();
        $feed = $feedQ()->with('user')->orderByDesc('created_at')->orderBy('id')->limit(30)->get();
        $chan = $chanQ()->with('user')->orderByDesc('created_at')->orderBy('id')->limit(30)->get();
        foreach ($feed->merge($chan) as $c) {
            $rows->push(['type' => $c->module === 'feed' ? 'feed' : 'channel', 'model' => $c,
                'author' => optional($c->user)->name, 'at' => $c->created_at]);
        }

        $dms = $dmQ()->orderByDesc('created_at')->orderBy('id')->limit(30)->get();
        $names = User::whereIn('id', $dms->pluck('from_id')->unique())->pluck('name', 'id');
        foreach ($dms as $m) {
            $rows->push(['type' => 'dm', 'model' => $m, 'author' => $names[$m->from_id] ?? null, 'at' => $m->created_at]);
        }

        // الأحدثُ أوّلاً، ثمّ المعرّفُ كسراً للتعادل (لا قرعةَ ثانية)
        $rows = $rows->sort(fn ($a, $b) => [(string) $b['at'], (string) $a['model']->id]
            <=> [(string) $a['at'], (string) $b['model']->id])->take(self::LIMIT)->values();

        // «N نتيجة» تعني ما طابق لا ما عُرض (W-5) — عدٌّ على المصادرِ الثلاثةِ بشروطِ تنطيقِها
        $total = $feedQ()->count() + $chanQ()->count() + $dmQ()->count();

        return ['rows' => $rows, 'total' => $total];
    }

    /** نطاقُ الشركة على الخلاصة — نظيرُ `CommentController::feedCompanyFilter` حرفاً */
    private static function feedScope(User $me, $query)
    {
        if (! hub_has_col('comments', 'company_id')) return $query;
        if (($cids = hub_company_ids($me)) === null) return $query;

        return $query->where(fn ($w) => $w->whereIn('company_id', $cids)->orWhereNull('company_id'));
    }
}
