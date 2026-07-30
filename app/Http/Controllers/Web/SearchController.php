<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** البحث الشامل عبر كل الوحدات — يحترم مصفوفة الصلاحيات ونطاق المشاريع */
class SearchController extends Controller
{
    /** النتائج الحية للشريط العلوي (htmx) — خفيفة: بلا عدّ، تتوقف عند الاكتفاء */
    public function mini(Request $r)
    {
        $q = trim((string) $r->input('q'));
        if (mb_strlen($q) < 2) return response('');

        $flat = [];
        foreach ($this->searchableModules() as $key => $def) {
            $rows = $this->query($key, $def, $q)->limit(3)->get();
            $disp = hub_display_col($key);
            foreach ($rows as $row) {
                $flat[] = ['module' => $key, 'id' => $row->id,
                           'name' => (string) $row->{$disp}, 'label' => $def['label']];
            }
            if (count($flat) >= 9) break;
        }

        return view('partials.searchmini', [
            'flat' => array_slice($flat, 0, 9), 'q' => $q,
            'dests' => array_slice($this->destinations($q), 0, 4),
        ]);
    }

    /** صفحة النتائج الكاملة مجمّعة بالوحدات */
    public function index(Request $r)
    {
        $q = trim((string) $r->input('q'));
        $groups = [];
        $dests = mb_strlen($q) >= 2 ? $this->destinations($q) : [];

        if (mb_strlen($q) >= 2) {
            foreach ($this->searchableModules() as $key => $def) {
                $base  = $this->query($key, $def, $q);
                $count = (clone $base)->count();
                if (! $count) continue;
                $groups[] = [
                    'module' => $key, 'label' => $def['label'], 'count' => $count,
                    'display' => hub_display_col($key), 'status' => $def['status'] ?? null,
                    'rows' => $base->orderByDesc('created_at')->limit(8)->get(),
                ];
            }
            usort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);
        }

        return view('search.index', ['q' => $q, 'groups' => $groups, 'dests' => $dests]);
    }

    /**
     * وجهات النظام: صفحات الوحدات والأدوات والمراكز والإدارة — البحث الشامل صار
     * يوصلك لأي جزء من النظام بالاسم، لا للسجلات وحدها. يحترم صلاحيات كل وجهة.
     * لا اقتراحات إضافة هنا: البحث يجد ويوصل فقط.
     */
    protected function destinations(string $q): array
    {
        $u = auth()->user();
        $owner = hub_is_owner();

        $cat = [['t' => '🏠 لوحة التحكم', 'u' => route('dashboard')]];

        // أدوات ولوحات القائمة (بصلاحياتها المضمّنة أصلاً)
        foreach (hub_top_links($u) as $l) {
            $cat[] = ['t' => $l['label'], 'u' => route($l['route'])];
        }

        // صفحات الوحدات الـ٧١ بأسمائها (المخصصة إن سمّاها المستخدم)
        foreach (hub_nav($u) as $g) {
            foreach ($g['items'] as $it) {
                $cat[] = ['t' => $g['icon'] . ' ' . $it['label'], 'u' => route('m.index', $it['key'])];
            }
        }

        // صفحات الإدارة — نفس شروط شريط الإدارة حرفياً
        foreach ([
            ['🎛️ التخصيص', 'prefs.edit', true],
            ['👥 المستخدمون', 'users.index', hub_flag($u, 'users')],
            ['🧑‍⚖️ الأدوار والصلاحيات', 'roles.index', $owner],
            ['🕘 سجل التدقيق', 'audit.index', hub_flag($u, 'audit')],
            ['🛡️ مركز الأمان', 'security.index', $owner],
            ['🖥️ مركز التشغيل', 'ops.index', $owner],
            ['🐞 مركز الأخطاء', 'errors.index', $owner],
            ['🔐 غرفة البيانات', 'dataroom.index', hub_secrets()],
            ['🧩 باني الحقول', 'fields.index', $owner],
            ['🪄 مسارات العمل', 'flows.index', $owner],
            ['🪝 Webhooks', 'webhooks.index', $owner],
            ['🧹 جودة البيانات', 'quality.index', $owner],
            ['⚙️ الإعدادات', 'settings.edit', $owner],
            ['🧾 QuoteFlow', 'quoteflow', $owner],
        ] as [$label, $route, $ok]) {
            if ($ok) $cat[] = ['t' => $label, 'u' => route($route)];
        }

        return array_values(array_filter($cat, fn ($d) => mb_stripos($d['t'], $q) !== false));
    }

    /* ────────── أدوات داخلية ────────── */

    /** الوحدات المؤهلة: يملك المستخدم عرضها وموديلها موجود (users لها صفحتها الإدارية) */
    protected function searchableModules(): array
    {
        $out = [];
        foreach (hub_modules() as $key => $def) {
            if ($key === 'users') continue;
            if (! hub_can(auth()->user(), $key, 'v')) continue;
            if (! class_exists('\\App\\Models\\' . $def['model'])) continue;
            $out[$key] = $def;
        }
        return $out;
    }

    /** استعلام بحث وحدة واحدة داخل نطاق المستخدم */
    protected function query(string $key, array $def, string $term): \Illuminate\Database\Eloquent\Builder
    {
        $class = '\\App\\Models\\' . $def['model'];

        return hub_scope($class::query()->search($term), $key);
    }
}
