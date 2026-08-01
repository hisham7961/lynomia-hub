<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التطبيق والمشروع — علاقةٌ محسومة لا التباس.
 *
 * السجل يقول الحقيقة صراحةً: **التطبيق يتبع مشروعاً** (`apps.project_id`).
 * لكن ثماني وحداتٍ تعرض الحقلين معاً — «المشروع» و«التطبيق» — ولا شيء يربط
 * أحدهما بالآخر. فينشأ الالتباس بأربع صور:
 *
 *   ١) سجلٌّ رُبط بتطبيقٍ و**تُرك مشروعه فارغاً**. المشروع معروفٌ من التطبيق،
 *      لكن عدسة المشروع `?p=` لا تراه، ومجاميع المشروع تنقص بصمت.
 *   ٢) سجلٌّ مشروعه **يخالف** مشروع تطبيقه — أحدهما خطأ، ولا أحد يعلم.
 *   ٣) تطبيقٌ **بلا مشروع** أصلاً: يتيمٌ لا يصعد إلى أي مجموع.
 *   ٤) مشروعٌ منتَجُه تطبيق (له رابط متجرٍ أو إنتاج) و**لا ملفَّ تطبيقٍ له** —
 *      فلا نسخةَ ولا تقييمَ ولا تحميلاتٍ تُتابَع.
 *
 * الأولى تُصلَح **وقت الحفظ** (الاستنتاج)، والباقي يُعرض ويُصلَح بضغطة.
 */
class AppsProjects
{
    public const TTL = 300;

    /**
     * وقت الحفظ: سجلٌّ له تطبيقٌ وليس له مشروع يرث مشروع تطبيقه.
     * لا يُكتب فوق اختيارٍ صريح — من كتب مشروعاً يُحترم، ويُعرض تناقضُه لاحقاً.
     */
    public static function inherit(array $def, Model $m): void
    {
        $module = (string) ($def['key'] ?? '');
        if ($module === 'apps' || ! Schema::hasTable('applications')) return;

        $appCol = self::colOf($def, 'apps');
        $projCol = self::colOf($def, 'projects');
        if (! $appCol || ! $projCol) return;

        $appId = $m->{$appCol} ?? null;
        if (! $appId || ! empty($m->{$projCol})) return;

        $pid = DB::table('applications')->whereNull('deleted_at')->where('id', $appId)->value('project_id');
        if ($pid) $m->{$projCol} = $pid;
    }

    /** عمود الحقل الذي يشير إلى وحدةٍ ما في سجل الوحدة */
    protected static function colOf(array $def, string $ref): ?string
    {
        foreach ($def['fields'] ?? [] as $f) {
            if (($f['ref'] ?? null) === $ref) return $f['col'] ?? $f['key'];
        }

        return null;
    }

    /** الوحدات التي تعرض الحقلين معاً — مصدر الالتباس */
    public static function dualModules(): array
    {
        $out = [];
        foreach (hub_modules() as $mk => $md) {
            if ($mk === 'apps') continue;
            $a = self::colOf($md, 'apps');
            $p = self::colOf($md, 'projects');
            if ($a && $p) $out[$mk] = ['label' => $md['label'], 'table' => $md['table'],
                                       'app' => $a, 'proj' => $p, 'display' => $md['display'] ?? 'title'];
        }

        return $out;
    }

    /**
     * السجلات الملتبسة: ما وَرِث ولم يُحفظ بعد (قديم)، وما يتناقض.
     * `$fixable` وحدها تُصلَح تلقائياً — التناقض قرارُ إنسان.
     */
    public static function scan($user = null): array
    {
        return hub_screen('ap:scan', self::TTL, fn () => self::scanCalc($user),
            array_merge(['applications', 'projects'], array_column(self::dualModules(), 'table')));
    }

    protected static function scanCalc($user = null): array
    {
        $user = $user ?? auth()->user();
        if (! Schema::hasTable('applications')) return ['orphanApps' => [], 'noProject' => [],
            'conflict' => [], 'appless' => [], 'byProject' => []];

        // الخريطةُ الكاملة تلزم للمقارنة، لكن **الأسماء** لا تُعرض إلا لمن يملك
        // وحدتها ونطاقها: اسم المشروع بيانٌ كاسم أي حقل
        $appProj = DB::table('applications')->whereNull('deleted_at')
            ->get(['id', 'name', 'project_id', 'platform', 'status']);
        $map = $appProj->pluck('project_id', 'id');

        $appQ = hub_read('apps', $user);
        $appSeen = $appQ ? $appQ->pluck('name', 'id') : collect();
        $projQ = hub_read('projects', $user);
        $projNames = $projQ ? $projQ->pluck('name', 'id') : collect();

        $noProject = [];
        $conflict = [];
        foreach (self::dualModules() as $mk => $d) {
            if (! hub_can($user, $mk, 'v') || ! Schema::hasTable($d['table'])) continue;

            $rows = hub_scope(DB::table($d['table'])->whereNull('deleted_at'), $mk, $user)
                ->whereNotNull($d['app'])->limit(200)
                ->get(['id', $d['app'], $d['proj'], $d['display']]);

            foreach ($rows as $r) {
                $want = $map[$r->{$d['app']}] ?? null;
                if (! $want) continue;                       // تطبيقٌ يتيم — يُعالَج في بابه
                $have = $r->{$d['proj']} ?? null;
                $item = ['module' => $mk, 'label' => $d['label'], 'id' => $r->id,
                         'title' => (string) ($r->{$d['display']} ?? '—'),
                         'app' => $appSeen[$r->{$d['app']}] ?? '—',
                         'want' => $projNames[$want] ?? '—', 'wantId' => $want];
                if (! $have) $noProject[] = $item;
                elseif ($have !== $want) $conflict[] = $item + ['have' => $projNames[$have] ?? '—'];
            }
        }

        // تطبيقاتٌ يتيمة: لا تصعد إلى أي مجموعٍ ولا تظهر في عدسة مشروع
        $orphan = $appQ
            ? $appProj->whereNull('project_id')->filter(fn ($a) => isset($appSeen[$a->id]))
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name,
                    'platform' => $a->platform, 'status' => $a->status])->values()->all()
            : [];

        return [
            'orphanApps' => $orphan,
            'noProject'  => $noProject,
            'conflict'   => $conflict,
            'appless'    => self::appless($user, $appProj),
            'byProject'  => self::byProject($user, $appProj, $projNames, $appSeen),
        ];
    }

    /** مشروعٌ منتَجُه تطبيقٌ ولا ملفَّ تطبيقٍ له — فلا نسخةَ ولا تقييمَ يُتابَع */
    protected static function appless($user, $appProj): array
    {
        if (! ($pq = hub_read('projects', $user))) return [];

        $withApp = $appProj->pluck('project_id')->filter()->unique()->all();

        return hub_open_scope($pq)
            ->whereNotIn('id', $withApp ?: ['-'])
            ->where(fn ($w) => $w->whereNotNull('prod')->where('prod', '!=', '')
                ->orWhere(fn ($x) => $x->whereNotNull('url')->where('url', '!=', '')))
            ->limit(20)->get(['id', 'name', 'prod', 'url'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'link' => $p->prod ?: $p->url])->all();
    }

    /** خريطةٌ بسيطة: كل مشروعٍ وتطبيقاته */
    protected static function byProject($user, $appProj, $projNames, $appSeen): array
    {
        if (! hub_can($user, 'apps', 'v')) return [];

        return $appProj->whereNotNull('project_id')
            // لا يُسمّى تطبيقٌ ولا مشروعٌ خارج ما يراه القارئ
            ->filter(fn ($a) => isset($appSeen[$a->id]) && isset($projNames[$a->project_id]))
            ->groupBy('project_id')
            ->map(fn ($g, $pid) => [
                'id' => $pid, 'project' => $projNames[$pid] ?? 'مشروعٌ محذوف',
                'apps' => $g->map(fn ($a) => ['id' => $a->id, 'name' => $a->name,
                    'platform' => $a->platform, 'status' => $a->status])->values()->all(),
            ])->sortByDesc(fn ($x) => count($x['apps']))->values()->all();
    }

    /**
     * الإصلاح الجماعي: ملء المشروع من التطبيق حيث كان **فارغاً** وحده.
     * لا يمسّ التناقض — من كتب مشروعاً مخالفاً يُسأل، ولا يُصحَّح عنه بصمت.
     */
    public static function fixMissing($user = null): int
    {
        $user = $user ?? auth()->user();
        $scan = self::scan($user);
        $n = 0;

        foreach ($scan['noProject'] as $it) {
            $d = self::dualModules()[$it['module']] ?? null;
            if (! $d || ! hub_can($user, $it['module'], 'e')) continue;

            DB::table($d['table'])->where('id', $it['id'])->whereNull($d['proj'])
                ->update([$d['proj'] => $it['wantId'], 'updated_at' => now()]);
            $n++;
        }

        if ($n) {
            hub_audit('ربط سجلات بمشاريع تطبيقاتها', null, null, $n . ' سجلاً');
            // التحديث بمنشئ الاستعلام لا يُطلق أحداث Eloquent
            foreach (self::dualModules() as $d) hub_data_bump($d['table']);
        }

        return $n;
    }
}
