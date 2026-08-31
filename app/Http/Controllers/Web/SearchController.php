<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** البحث الشامل عبر كل الوحدات — يحترم مصفوفة الصلاحيات ونطاق المشاريع */
class SearchController extends Controller
{
    /**
     * النتائج الحية للشريط العلوي (htmx) — البحث هو لوحة أوامر النظام:
     * - تركيزٌ بلا كتابة: أهم الوجهات + إجراءات «＋ جديد» فوراً (توجّهٌ لحظي بلا حرف)
     * - حرف واحد: لا شيء بعد (ضجيج)، وحرفان فأكثر: وجهات + إجراءات + سجلات بنطاق المستخدم
     */
    public function mini(Request $r)
    {
        $q = trim(hub_str($r->input('q')));
        if ($q === '') {
            $recents = $this->recents(5);
            return view('partials.searchmini', [
                'flat' => [], 'q' => '', 'recents' => $recents,
                'dests' => array_slice($this->destinations(''), 0, $recents ? 4 : 6),
                'acts' => $this->quickActions('', 3),
            ]);
        }
        if (mb_strlen($q) < 2) return response('');

        $flat = [];
        foreach ($this->searchableModules() as $key => $def) {
            // ترتيبٌ صريح: limit بلا orderBy يجعل «أي ثلاثة تظهر» قرعةً بين المحرّكين
            $rows = $this->query($key, $def, $q)
                ->orderByDesc('created_at')->orderByDesc('id')->limit(3)->get();
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
            'acts' => $this->quickActions($q, 3),
        ]);
    }

    /**
     * «الأخيرة»: آخر السجلات التي فتحها المستخدم — من سجل التنقل القائم (page_visits)
     * بلا جدول جديد. كل سجل يُعاد التحقق منه لحظة العرض: الوحدة موجودة، والصلاحية
     * قائمة، والسجل داخل نطاق المستخدم وغير محذوف — فلا يتسرب عبر «الأخيرة» ما لم
     * يعد له رؤيته.
     */
    protected function recents(int $limit): array
    {
        $u = auth()->user();
        $visits = \Illuminate\Support\Facades\DB::table('page_visits')
            ->where('user_id', $u->id)->where('route', 'm.show')
            ->orderByDesc('at')->limit(40)->pluck('path');

        $out = [];
        $seen = [];
        foreach ($visits as $path) {
            if (! preg_match('#^/m/([\w-]+)/([\w-]+)$#u', $path, $m)) continue;
            [, $module, $id] = $m;
            if (isset($seen[$path])) continue;
            $seen[$path] = 1;

            $def = hub_mod($module);
            if (! $def || ! hub_can($u, $module, 'v')) continue;
            $class = '\\App\\Models\\' . ($def['model'] ?? '');
            if (! class_exists($class)) continue;
            $row = hub_scope($class::query(), $module)->whereKey($id)->first();
            if (! $row) continue;

            $out[] = ['t' => (string) $row->{hub_display_col($module)}, 'l' => $def['label'],
                      'u' => route('m.show', [$module, $id])];
            if (count($out) >= $limit) break;
        }

        return $out;
    }

    /** إجراءات سريعة: «＋ جديد» في الوحدات التي يملك المستخدم الإضافة فيها ويطابق اسمُها النص */
    protected function quickActions(string $q, int $limit): array
    {
        $u = auth()->user();
        $acts = [];
        foreach (hub_nav($u) as $g) {
            foreach ($g['items'] as $it) {
                if ($q !== '' && mb_stripos($it['label'], $q) === false) continue;
                if (! hub_can($u, $it['key'], 'a')) continue;
                $acts[] = ['t' => $it['label'], 'u' => route('m.create', $it['key'])];
                if (count($acts) >= $limit) return $acts;
            }
        }
        return $acts;
    }

    /** صفحة النتائج الكاملة مجمّعة بالوحدات */
    public function index(Request $r)
    {
        $q = trim(hub_str($r->input('q')));
        $groups = [];
        $dests = mb_strlen($q) >= 2 ? $this->destinations($q) : [];

        if (mb_strlen($q) >= 2) {
            foreach ($this->searchableModules() as $key => $def) {
                $base  = $this->query($key, $def, $q);
                $count = (clone $base)->count();
                if (! $count) continue;
                $groups[] = [
                    // العمود الفيزيائيّ لا المفتاح: وحدةٌ مفتاحُ حالتها ≠ عمودها
                    // (الوثائق: docStatus/doc_status) كانت شارةُ حالتها تختفي بصمت
                    'module' => $key, 'label' => $def['label'], 'count' => $count,
                    'display' => hub_display_col($key), 'status' => hub_status_col($key),
                    // فاصل id: created_at بدقة الثانية يتساوى في الإدخال الدفعي فيقترع المحرّكان
                    'rows' => $base->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get(),
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

        // مساحات العمل — الصفحات المركزية للمجالات (مرشّحة بصلاحية المستخدم)
        foreach (\App\Support\Workspaces::for($u) as $key => $ws) {
            $cat[] = ['t' => $ws['icon'] . ' مساحة ' . $ws['label'], 'u' => route('workspace', $key)];
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

        // مساحةُ عمل العميل تصفّي البحثَ كما تصفّي القوائم — من يعمل في مساحة
        // «شركة أ» لا تقفز له نتائجُ عميلٍ آخر وهو يظن نفسه داخلها
        return hub_client_scope(hub_scope($class::query()->search($term), $key), $key);
    }
}
