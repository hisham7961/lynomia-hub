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

        return view('partials.searchmini', ['flat' => array_slice($flat, 0, 9), 'q' => $q]);
    }

    /** صفحة النتائج الكاملة مجمّعة بالوحدات */
    public function index(Request $r)
    {
        $q = trim((string) $r->input('q'));
        $groups = [];

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

        return view('search.index', ['q' => $q, 'groups' => $groups]);
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
