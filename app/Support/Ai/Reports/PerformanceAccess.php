<?php

namespace App\Support\Ai\Reports;

use App\Models\Employee;
use App\Models\User;

/**
 * **من يرى تقريرَ أداء الموظّف؟** — قاعدةٌ واحدةٌ لتبويب الملفّ الشامل وصفحة التقرير وزرِّ التحديث.
 *
 *  · **ليس حسابَ عميل** (٤٠٤ — لا يُثبَت وجودُ ما لا يخصّه).
 *  · **الموارد البشريّة:** المالكُ، أو من يملك `hr:v` والموظّفُ في نطاقه (`hub_scope('hr')` — عزلُ الشركة).
 *  · **المديرُ المباشر:** `employees.manager_id` = المستخدم — العلاقةُ نفسُها التي يقرؤها
 *    `LeaveDecision::parties` («مدير الموظّف»)، لا علاقةٌ مخترعة.
 *  · **والموظّفُ نفسُه لا يرى السرد** (سياسةُ المالك: يرى الموظّفُ ملاحظاتِ مديره المعتمدةَ وحدَها) —
 *    ولا يستثنيه `hr:v` في دوره؛ والمالكُ وحده مستثنى.
 *  · **حجبُ الحقول يُغلق:** من حُجب عنه «آخر تقييم أداء» (`hr.perf`) أو حقلٌ حرٌّ من التقارير اليوميّة
 *    (والسردُ مبنيٌّ منها) لا يرى التقرير — فشلٌ مغلقٌ لا ترشيحٌ جزئيٌّ لنصٍّ مولَّد.
 */
final class PerformanceAccess
{
    /** حقولُ الموارد البشريّة التي يعكسها التقرير — حجبُ أيٍّ منها يحجبه */
    public const HR_FIELDS = ['perf'];

    /**
     * أيبلغ هذا المستخدمُ سطحَ تقارير الأداء أصلاً؟ — وسيطٌ رخو (`hub_top_links` يمرّر «مالكاً صوريّاً»).
     */
    public static function canUseAny(mixed $u): bool
    {
        if (! $u || hub_is_client($u)) return false;
        if (($u->role->is_owner ?? false) || hub_can($u, 'hr', 'v')) return true;

        return $u instanceof User && self::managesAny($u);
    }

    /** أهو مديرٌ مباشرٌ لموظّفٍ واحدٍ على الأقلّ؟ */
    public static function managesAny(User $u): bool
    {
        return Employee::query()->whereNull('deleted_at')->where('manager_id', (string) $u->id)->exists();
    }

    public static function isManagerOf(User $u, Employee $emp): bool
    {
        return $emp->manager_id !== null && (string) $emp->manager_id === (string) $u->id;
    }

    public static function isSelf(User $u, Employee $emp): bool
    {
        return $emp->user_id !== null && (string) $emp->user_id === (string) $u->id;
    }

    /**
     * **الموظّفُ إن كان للمستخدم أن يرى تقريرَه** — أو `null` (⇒ ٤٠٤). الحجبُ لا يُفحص هنا (`masked`).
     */
    public static function employee(User $u, string $id): ?Employee
    {
        if (hub_is_client($u)) return null;
        /** @var Employee|null $emp */
        $emp = Employee::query()->whereNull('deleted_at')->whereKey($id)->first();
        if ($emp === null) return null;

        return self::canView($u, $emp) ? $emp : null;
    }

    public static function canView(User $u, Employee $emp): bool
    {
        if (hub_is_client($u)) return false;
        if (hub_is_owner($u)) return true;
        if (self::isSelf($u, $emp)) return false;
        if (self::isManagerOf($u, $emp)) return true;

        return hub_can($u, 'hr', 'v')
            && hub_scope(Employee::query()->whereNull('deleted_at'), 'hr', $u)->whereKey($emp->id)->exists();
    }

    /** أيُحجب عنه حقلٌ يعكسه التقرير؟ ⇒ التقريرُ محجوبٌ عنه */
    public static function masked(User $u): bool
    {
        foreach (self::HR_FIELDS as $f) {
            if (hub_field_mode($u, 'hr', $f) === 'hide') return true;
        }
        foreach (ProjectReportDigest::TEXT_FIELDS as $f) {
            if (hub_field_mode($u, 'updates', $f) === 'hide') return true;
        }

        return false;
    }

    /** «تحديث»: يرى التقرير، والمالكُ أو من يعدّل الموارد البشريّة (`hr:e`) */
    public static function canRefresh(User $u, Employee $emp): bool
    {
        return self::canView($u, $emp) && ! self::masked($u) && (hub_is_owner($u) || hub_can($u, 'hr', 'e'));
    }

    /**
     * **الموظّفون الذين يرى تقاريرَهم** — نطاقُ hr (لمن يملكه) ومرؤوسوه المباشرون، عدا نفسه (استعلامٌ يُكمَل).
     */
    public static function employees(User $u)
    {
        $q = Employee::query()->whereNull('deleted_at');
        if (hub_is_owner($u)) return $q;

        $uid = (string) $u->id;
        $hr = hub_can($u, 'hr', 'v');
        $scoped = $hr ? hub_scope(Employee::query()->whereNull('deleted_at'), 'hr', $u)->select('id') : null;

        return $q->where(function ($w) use ($uid, $scoped) {
            $w->where('manager_id', $uid);
            if ($scoped !== null) $w->orWhereIn('id', $scoped);
        })->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', '!=', $uid));
    }

    /**
     * **ما يعرضه التبويبُ والصفحة** — الفترةُ المختارة (إن كان لها صفّ) أو الأحدث، وتاريخُ الفترات،
     * وأسماءُ مشاريع الحقائق **بنطاق القارئ** (ما خارجه «مشروعٌ خارج نطاقك» لا اسمُه).
     * يُستدعى بعد `canView` — لا يحرس بنفسه.
     *
     * @return array{rows: \Illuminate\Support\Collection, row: ?\App\Models\EmployeePerformanceReport, period: ?string,
     *               masked: bool, projects: array<string,string>, canRefresh: bool, why: ?string, enabled: bool}
     */
    public static function panel(User $u, Employee $emp, ?string $period = null): array
    {
        $rows = EmployeePerformance::history((string) $emp->id);
        $period = trim((string) $period);
        $row = $period !== '' ? $rows->first(fn ($r) => $r->period === $period) : null;
        $row ??= $rows->get(0);   // الأحدثُ فترةً — `history` مرتّبٌ صراحةً

        $masked = self::masked($u);
        $projects = [];
        $list = (array) ($row?->facts['work']['projects'] ?? []);
        if (! $masked && $list !== []) {
            $ids = array_values(array_filter(array_map(fn ($p) => (string) ($p['id'] ?? ''), $list)));
            $names = hub_can($u, 'projects', 'v') && $ids !== []
                ? hub_scope(\Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at'), 'projects', $u)
                    ->whereIn('id', $ids)->pluck('name', 'id')->all()
                : [];
            foreach ($list as $p) {
                $projects[(string) $p['code']] = (string) ($names[(string) ($p['id'] ?? '')] ?? 'مشروعٌ خارج نطاقك');
            }
        }

        return [
            'rows' => $rows, 'row' => $row, 'period' => $row?->period, 'masked' => $masked, 'projects' => $projects,
            'canRefresh' => self::canRefresh($u, $emp) && EmployeePerformance::ready(),
            'why' => EmployeePerformance::whyNot(), 'enabled' => EmployeePerformance::enabled(),
        ];
    }
}
