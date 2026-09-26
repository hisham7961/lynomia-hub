<?php

namespace App\Support\Ai\Auditor;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **التقاريرُ اليوميّةُ الحديثة كما يراها المدقّق** — قارئٌ واحدٌ تتشاركه الكواشف.
 *
 * يمرّ بـ`hub_scope('updates')` بهويّةِ المدقّق — فلا طريقَ جانبيّاً ولو اتّسع نطاقُه —
 * ويرتّب **دلاليّاً** (صاحبُ التقرير، ثمّ يومُ العمل، ثمّ المعرّف): «السابقُ» في كاشفِ
 * النسخ يجب أن يكون سابقاً حقّاً، لا ما صادف أن أعاده المحرّكُ أوّلاً (CLAUDE.md: القرعة).
 */
final class Reports
{
    /** نافذةُ النظر — أسبوعان: ما قبلها تاريخٌ لا عملٌ جارٍ */
    public const WINDOW_DAYS = 14;

    /** @return Collection<int, object> */
    public static function recent(User $auditor, int $days = self::WINDOW_DAYS): Collection
    {
        $since = now()->subDays($days)->toDateString();

        return hub_scope(DB::table('work_updates')->whereNull('deleted_at'), 'updates', $auditor)
            ->whereNotNull('created_by')
            ->whereNotNull('work_date')
            ->where('work_date', '>=', $since)
            ->orderBy('created_by')->orderBy('work_date')->orderBy('id')
            ->get(['id', 'created_by', 'work_date', 'project_id', 'task_id', 'company_id',
                   'done', 'doing', 'problems', 'needs', 'next', 'progress', 'hours']);
    }

    public static function day(object $r): string
    {
        return substr((string) $r->work_date, 0, 10);
    }
}
