<?php

namespace App\Support\Ai\FollowUp;

use App\Models\AiCommitment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Platform\BusinessDate;
use App\Support\Workforce\MonthlyAttendance;
use App\Support\Workforce\Workday;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **السؤالُ ثم التصعيد** — بحدودٍ تحمي الموظّفَ من الإزعاج:
 *  - يُسأل بعد الموعد والمهلة (`followup.grace_days`)، **بإشعارٍ واحدٍ مجمَّع** لكلِّ التزاماته المستحقّة.
 *  - **سؤالان في اليوم على الأكثر**، ولا يُسأل الالتزامُ الواحدُ مرّتين في اليوم نفسِه.
 *  - **لا سؤالَ في يومٍ لا يلزمه فيه عمل** (إجازة · عطلة · راحة — `DailyWorkCompliance`).
 *  - الكتمُ من تفضيلاته (`followup`): إشعارٌ مكتومٌ لا يُنشأ، **ولا يُحسب سؤالاً** فلا يُصعَّد بسببه.
 *  - بعد `followup.max_asks` سؤالاً بلا ردٍّ ⇒ «مُصعَّد»، ويُبلَّغ **مديرُه المباشر وحدَه** بإشعارٍ مجمَّع.
 * والنصُّ قالبٌ ثابتٌ لا كلامُ نموذج: سؤالُ متابعةٍ لا اتّهام.
 */
final class FollowUpSender
{
    public const KIND = 'followup';

    public const KIND_TEAM = 'followup_team';

    /** @return array{asked: int, users: int, escalated: int} */
    public static function run(bool $dry = false): array
    {
        $out = ['asked' => 0, 'users' => 0, 'escalated' => 0];
        $today = today();
        $cut = $today->copy()->subDays(FollowUp::graceDays())->toDateString();

        $due = FollowUp::models(AiCommitment::query()->where('status', 'open')
            ->where('due_on', '<=', $cut)->where('asked_count', '<', FollowUp::maxAsks())
            ->where(fn ($w) => $w->whereNull('asked_at')->orWhere('asked_at', '<', $today->toDateTimeString()))
            ->orderBy('due_on')->orderBy('id')->limit(500)->get());

        foreach ($due->groupBy('user_id') as $uid => $list) {
            $u = User::query()->find((string) $uid);
            if (! $u instanceof User || hub_is_client($u) || (string) $u->status !== 'نشط') continue;
            if (! self::workday($u)) continue;
            if (self::sentToday((string) $uid) >= FollowUp::MAX_PER_DAY) continue;
            if ($dry) { $out['users']++; $out['asked'] += $list->count(); continue; }

            $first = $list->values()[0];   // الأقربُ موعداً — المجموعةُ مرتّبةٌ بالاستعلام (due_on ثم id)
            $text = '🤖 متابعة من مساعد Hub: ذكرتَ في تقرير ' . $first->said_on->toDateString()
                . ' أنّك ستعمل على «' . Str::limit((string) $first->what, 120) . '» — ماذا حدث؟'
                . ($list->count() > 1 ? ' ولديك ' . ($list->count() - 1) . ' التزاماتٌ أخرى تنتظر ردّك.' : '')
                . ' ردّ بنقرةٍ من صفحة «متابعاتي».';
            $n = hub_notify((string) $uid, self::KIND, $text);
            if ($n === null || ! $n->exists) continue;   // مكتوم: لا يُحسب سؤالاً

            AiCommitment::query()->whereIn('id', $list->pluck('id')->all())->update([
                'asked_at' => now(), 'updated_at' => now(), 'asked_count' => DB::raw('asked_count + 1'),
            ]);
            $out['users']++;
            $out['asked'] += $list->count();
        }

        $out['escalated'] = self::escalate($dry);

        return $out;
    }

    /** بعد الحدّ من الأسئلة بلا ردٍّ (ومضى يومٌ على آخرها) ⇒ «مُصعَّد»، ومديرُه المباشر وحدَه يُبلَّغ */
    public static function escalate(bool $dry = false): int
    {
        $rows = FollowUp::models(AiCommitment::query()->where('status', 'open')
            ->where('asked_count', '>=', FollowUp::maxAsks())->whereNull('answered_at')
            ->where('asked_at', '<=', now()->subDay()->toDateTimeString())
            ->orderBy('due_on')->orderBy('id')->limit(500)->get());
        if ($rows->isEmpty() || $dry) return $rows->count();

        $byManager = [];
        foreach ($rows as $c) {
            $c->forceFill(['status' => 'escalated', 'escalated_at' => now()])->save();
            $mgr = Employee::query()->whereNull('deleted_at')->where('user_id', (string) $c->user_id)->value('manager_id');
            if ($mgr && (string) $mgr !== (string) $c->user_id) $byManager[(string) $mgr][] = $c;
        }
        foreach ($byManager as $mgr => $list) {
            $names = User::query()->whereIn('id', collect($list)->pluck('user_id')->unique()->all())->pluck('name')->all();
            hub_notify($mgr, self::KIND_TEAM, '📌 متابعة: ' . count($list) . ' التزاماً في فريقك لم يُردّ عليها بعد سؤالين ('
                . Str::limit(implode('، ', $names), 150) . ') — راجعها في «متابعات فريقي».');
        }

        return $rows->count();
    }

    /**
     * يومُ عملٍ لهذا الموظّف؟ — لا في عطلة الأسبوع (`cost.weekend` — مصدرُ الكشف الشهريّ نفسُه)، ولا في
     * إجازةٍ معتمدة. و«الاشتراطُ مطفأ» في التقارير لا يعني عطلة، فلا يُقرأ من حالة الامتثال.
     */
    private static function workday(User $u): bool
    {
        $today = BusinessDate::today();
        if (MonthlyAttendance::isWeekend($today)) return false;

        $emp = Employee::query()->whereNull('deleted_at')->where('user_id', (string) $u->id)->value('id');
        if (! $emp) return true;   // بلا ملفّ موظّف (حسابٌ إداريّ) ⇒ يومُ عمل

        try {
            return ! Workday::onLeave((string) $emp, $today);
        } catch (\Throwable $e) {
            report($e);

            return false;   // لا نعرف ⇒ لا نُزعج
        }
    }

    private static function sentToday(string $uid): int
    {
        return DB::table('notifications_hub')->where('user_id', $uid)->where('kind', self::KIND)
            ->where('created_at', '>=', today()->toDateTimeString())->count();
    }
}
