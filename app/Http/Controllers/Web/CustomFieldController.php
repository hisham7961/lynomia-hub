<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** باني الحقول المخصصة — المالك يضيف حقولاً لأي وحدة بلا كود (تُخزن في عمود custom) */
class CustomFieldController extends Controller
{
    protected array $types = [
        'text' => 'نص', 'num' => 'رقم', 'date' => 'تاريخ',
        'sel' => 'قائمة اختيار', 'bool' => 'خانة تأشير ✓', 'ref' => 'مرجع لسجل',
    ];
    protected array $refs = ['users' => 'مستخدم', 'companies' => 'شركة', 'projects' => 'مشروع', 'clients' => 'عميل', 'suppliers' => 'مورد'];

    protected function gate(): void
    {
        abort_unless(auth()->user()?->role?->is_owner, 403, 'باني الحقول للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();
        $module = (string) $r->query('m', '');
        if ($module !== '') abort_unless(hub_mod($module), 404);

        return view('fields.index', [
            'module' => $module,
            'fields' => $module ? hub_custom_fields($module) : [],
            'types'  => $this->types,
            'refs'   => $this->refs,
        ]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'm'        => ['required', 'string'],
            'label'    => ['required', 'string', 'max:120'],
            'type'     => ['required', 'in:' . implode(',', array_keys($this->types))],
            'options'  => ['nullable', 'string', 'max:800'],
            'ref'      => ['nullable', 'in:' . implode(',', array_keys($this->refs))],
            'required' => ['nullable'],
        ]);
        abort_unless(hub_mod($d['m']), 404);
        if ($d['type'] === 'sel' && trim((string) ($d['options'] ?? '')) === '') {
            return back()->withErrors(['options' => 'اكتب خيارات القائمة مفصولة بفواصل'])->withInput();
        }
        if ($d['type'] === 'ref' && empty($d['ref'])) {
            return back()->withErrors(['ref' => 'اختر نوع المرجع'])->withInput();
        }

        $all = $this->all();
        $list = (array) ($all[$d['m']] ?? []);

        // مفتاح متسلسل ثابت cf_1 cf_2 …
        $n = 0;
        foreach ($list as $f) if (preg_match('/^cf_(\d+)$/', $f['key'], $mm)) $n = max($n, (int) $mm[1]);
        $field = [
            'key'      => 'cf_' . ($n + 1),
            'label'    => trim($d['label']),
            'type'     => $d['type'],
            'required' => $r->boolean('required'),
        ];
        if ($d['type'] === 'sel') $field['options'] = array_values(array_filter(array_map('trim', preg_split('/[,،]/u', $d['options']))));
        if ($d['type'] === 'ref') $field['ref'] = $d['ref'];

        $list[] = $field;
        $all[$d['m']] = $list;
        $this->save($all);

        return redirect()->route('fields.index', ['m' => $d['m']])->with('ok', 'أُضيف الحقل — سيظهر فوراً في نماذج «' . hub_mod($d['m'])['label'] . '»');
    }

    public function destroy(Request $r, string $module, string $key)
    {
        $this->gate();
        $all = $this->all();
        $all[$module] = array_values(array_filter((array) ($all[$module] ?? []), fn ($f) => $f['key'] !== $key));
        if (! $all[$module]) unset($all[$module]);
        $this->save($all);

        return redirect()->route('fields.index', ['m' => $module])->with('ok', 'حُذف الحقل من النماذج — القيم المخزنة في السجلات القديمة تبقى في مكانها');
    }

    protected function all(): array
    {
        $v = setting('custom.fields');

        return is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
    }

    protected function save(array $all): void
    {
        Setting::updateOrCreate(['key' => 'custom.fields'], ['value' => $all]);
        Cache::forget('settings:all');
    }
}
