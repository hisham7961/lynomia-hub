<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /** أعلام الدور كما يقرؤها hub_flag — المصدر الوحيد للنموذج والحفظ معاً */
    public const FLAGS = [
        'users'   => 'إدارة المستخدمين',
        'audit'   => 'سجل التدقيق',
        'approve' => 'اعتماد الطلبات والموافقات',
        'monitor' => 'اللوحات التحليلية والمراقبة',
        'secrets' => 'كشف أسرار الخزنة',
        'copySec' => 'نسخ السرّ دون كشفه',
        'exp'     => 'تصدير البيانات',
    ];

    protected array $ops = ['v' => 'عرض', 'a' => 'إضافة', 'e' => 'تعديل', 'd' => 'حذف'];

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'إدارة الأدوار لمالك النظام فقط');
    }

    public function index()
    {
        $this->gate();
        $roles = Role::withCount('users')->orderByDesc('is_owner')->orderBy('name')->get();

        return view('roles.index', compact('roles'));
    }

    public function create()
    {
        $this->gate();
        return view('roles.form', ['role' => null, 'ops' => $this->ops]);
    }

    public function store(Request $r)
    {
        $this->gate();
        Role::create($this->data($r));

        return redirect()->route('roles.index')->with('ok', 'أُضيف الدور');
    }

    public function edit(Role $role)
    {
        $this->gate();
        return view('roles.form', ['role' => $role, 'ops' => $this->ops]);
    }

    public function update(Request $r, Role $role)
    {
        $this->gate();
        $role->update($this->data($r, $role));

        return redirect()->route('roles.index')->with('ok', 'حُفظ الدور');
    }

    public function destroy(Role $role)
    {
        $this->gate();
        abort_if((bool) $role->is_owner, 422, 'لا يُحذف دور المالك');
        abort_if(User::where('role_id', $role->id)->exists(), 422, 'انقل مستخدمي هذا الدور أولاً');
        $role->delete();

        return redirect()->route('roles.index')->with('ok', 'حُذف الدور');
    }

    protected function data(Request $r, ?Role $role = null): array
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'scope' => 'required|in:all,proj']);
        $matrix = [];
        foreach (array_keys(hub_modules()) as $mod) {
            foreach (array_keys($this->ops) as $op) {
                if ($r->boolean("matrix.$mod.$op")) $matrix[$mod][$op] = 1;
            }
        }
        // الأعلام السبعة التي يقرؤها النظام فعلاً (hub_flag). كانت القائمة
        // خمسةً فقط، والنموذج لا يعرض أياً منها إطلاقاً — فكان كل تعديلِ دورٍ
        // روتيني يكتب [] فوق أعلامه ويُسقط الاعتماد والتدقيق والمراقبة صامتاً.
        //
        // ومربّع الاختيار غير المؤشَّر لا يُرسَل أصلاً، فلا يُفرَّق بين «أُلغيت
        // كلها» و«لم يُرسَل القسم» إلا بعلامةٍ صريحة: `flags_submitted` من
        // النموذج. بلا هذه العلامة تبقى الأعلام القديمة كما هي.
        $flags = (array) ($role?->flags ?? []);
        if ($r->has('flags') || $r->boolean('flags_submitted')) {
            $flags = [];
            foreach (array_keys(self::FLAGS) as $f) {
                if ($r->boolean("flags.$f")) $flags[$f] = 1;
            }
        }

        // صلاحيات مستوى الحقل: نحفظ القيود فقط (ro/hide) ونطرح الوحدات الفارغة
        $fieldRules = [];
        foreach ((array) $r->input('fr', []) as $mod => $fields) {
            if (! hub_mod((string) $mod)) continue;
            foreach ((array) $fields as $fk => $mode) {
                if (in_array($mode, ['ro', 'hide'], true)) $fieldRules[$mod][$fk] = $mode;
            }
        }

        return $d + ['matrix' => $matrix, 'flags' => $flags, 'is_owner' => false,
                     'field_rules' => $fieldRules ?: null];
    }
}
