<?php

namespace App\Support\Ai\Reports;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * **من يرى ملخّصَ تقارير المشروع؟** — قاعدةٌ واحدةٌ للصفحة والتفصيل وبطاقةِ المشروع وزرِّ التحديث.
 *
 * الملخّصُ مشتقٌّ من التقارير نفسِها، فيُرى **بشروطِ رؤيتها**:
 *  · داخليٌّ لا حسابُ عميل (العميلُ لا يرى تقريراً داخليّاً إطلاقاً — §79).
 *  · `updates:v` و`projects:v`.
 *  · المشروعُ في نطاقه (`hub_scope('projects')` — عزلُ الشركة والعميل والمشاريع معاً).
 *  · **وحقولُ التقرير الحرّة كلُّها مرئيّةٌ له** (`hub_field_mode`): من حُجب عنه «المشكلات» مثلاً لا يقرؤها
 *    ملخّصةً في «العوائق» — فيُحجب الملخّصُ كلُّه ويُقال السبب (فشلٌ مغلق، لا ترشيحٌ جزئيٌّ لنصٍّ مولَّد).
 */
final class DigestAccess
{
    /**
     * وسيطٌ رخوٌ لا `?User` صارم — `hub_top_links` يُستدعى أيضاً بـ«مالكٍ صوريّ» (stdClass) لاشتقاق
     * كتالوج المراكز (نمطُ `ReportReview::canReviewAny`).
     */
    public static function canUse(mixed $u): bool
    {
        return $u && ! hub_is_client($u) && hub_can($u, 'updates', 'v') && hub_can($u, 'projects', 'v');
    }

    /** أيُحجب عنه حقلٌ من حقول التقرير الحرّة؟ ⇒ الملخّصُ محجوبٌ عنه */
    public static function masked(User $u): bool
    {
        foreach (ProjectReportDigest::TEXT_FIELDS as $f) {
            if (hub_field_mode($u, 'updates', $f) === 'hide') return true;
        }

        return false;
    }

    /** «تحديث الآن»: المالكُ، أو من يعدّل التقارير (`updates:e`) أو يراجعها موارداً بشريّة (`hr:e`) */
    public static function canRefresh(mixed $u): bool
    {
        if (! self::canUse($u)) return false;

        return (bool) ($u->role->is_owner ?? false) || hub_can($u, 'updates', 'e') || hub_can($u, 'hr', 'e');
    }

    /** مشاريعُ نطاقه (غيرُ المحذوفة) — استعلامٌ يُكمَل */
    public static function projects(User $u): Builder
    {
        return hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects', $u);
    }

    /** المشروعُ إن كان في نطاقه — أو `null` (⇒ ٤٠٤) */
    public static function project(User $u, string $id): ?object
    {
        return self::projects($u)->where('id', $id)->first(['id', 'name', 'status', 'company_id']);
    }

    /**
     * **نصُّ الملخّص للعرض** — رموزُ الأعضاء (`[P1]`) تُستبدل بأسمائهم، والرمزُ المجهولُ «عضو».
     * يُهرَّب في القالب (`{{ }}`) — لا HTML من النموذج.
     */
    public static function text(string $s, array $members, array $names): string
    {
        return (string) preg_replace_callback('/\[(P\d{1,3})\]/u', function ($m) use ($members, $names) {
            $uid = (string) ($members[$m[1]] ?? '');

            return $uid !== '' && isset($names[$uid]) ? (string) $names[$uid] : 'عضو';
        }, $s);
    }

    /** أسماءُ أعضاء الملخّص — دفعةً واحدة */
    public static function names(array $members): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $members))));

        return $ids === [] ? [] : hub_ref_labels('users', $ids);
    }
}
