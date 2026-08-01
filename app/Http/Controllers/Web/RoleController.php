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
        // الاسم القديم «نسخ السرّ دون كشفه» كان يَعِد بحاجزٍ لا وجود له: منفذ
        // الكشف واحد، والنصّ يصل جهاز المستخدم في الحالين — والفرق عرضٌ لا منع.
        'copySec' => 'نسخ السرّ للحافظة — نصّه يصل الجهاز',
        'exp'     => 'تصدير البيانات',
    ];

    /** رايات يترتّب على منحها وصولٌ واسع — تُوسَم في الشاشة لا تُدسّ بين البقية */
    public const RISKY_FLAGS = ['users', 'secrets', 'copySec', 'exp', 'audit'];

    protected array $ops = ['v' => 'عرض', 'a' => 'إضافة', 'e' => 'تعديل', 'd' => 'حذف'];

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'إدارة الأدوار لمالك النظام فقط');
    }

    /**
     * تجميع الوحدات كما يراها المستخدم في تنقّله اليومي — مجموعات الشريط
     * الجانبي نفسها. ما لا مجموعة له يُجمع في «أخرى» بدل أن يسقط من الشاشة.
     */
    public static function groupedModules(): array
    {
        $mods = hub_modules();
        $out = [];
        $seen = [];

        foreach ((array) config('hub_nav', []) as $g) {
            $items = [];
            foreach ((array) ($g['items'] ?? []) as $k) {
                if (! isset($mods[$k])) continue;
                $items[$k] = $mods[$k];
                $seen[$k] = 1;
            }
            if ($items) $out[$g['g']] = ['icon' => $g['icon'] ?? '📁', 'items' => $items];
        }

        $rest = array_diff_key($mods, $seen);
        if ($rest) $out['أخرى'] = ['icon' => '📎', 'items' => $rest];

        return $out;
    }

    public function index()
    {
        $this->gate();
        $roles = Role::withCount('users')->orderByDesc('is_owner')->orderBy('name')->get();

        return view('roles.index', ['roles' => $roles, 'reach' => $roles->mapWithKeys(
            fn ($r) => [$r->id => self::reach($r)])]);
    }

    /**
     * مدى الدور بالأرقام: كم وحدةً يرى ويكتب ويحذف، وكم قيد حقل.
     * كان الجدول يقول «حسب المشروع» ولا يقول ماذا يبلغ الدور فعلاً.
     */
    public static function reach(Role $role): array
    {
        if ($role->is_owner) {
            return ['v' => count(hub_modules()), 'e' => count(hub_modules()),
                    'd' => count(hub_modules()), 'fields' => 0, 'owner' => true];
        }

        $mx = is_array($role->matrix) ? $role->matrix : (json_decode($role->matrix ?? '[]', true) ?: []);
        $fr = is_array($role->field_rules) ? $role->field_rules : (json_decode($role->field_rules ?? '[]', true) ?: []);
        $count = fn (string $op) => count(array_filter($mx, fn ($m) => ! empty($m[$op])));

        return ['v' => $count('v'), 'e' => $count('e'), 'd' => $count('d'), 'owner' => false,
                'fields' => collect($fr)->sum(fn ($f) => count(array_filter((array) $f)))];
    }

    public function create(Request $r)
    {
        $this->gate();

        return view('roles.form', ['role' => null, 'ops' => $this->ops,
            'groups' => self::groupedModules(), 'templates' => (array) config('hub_roles.templates', []),
            'others' => Role::where('is_owner', false)->orderBy('name')->get()]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $role = Role::create($this->data($r));
        $this->trail('إضافة دور', $role);

        return redirect()->route('roles.index')->with('ok', 'أُضيف الدور');
    }

    /**
     * أثرُ تدقيقٍ لكل مسٍّ بالأدوار.
     *
     * `Role` لا يستعمل سمة Auditable، فالشاشة **التي تمنح الصلاحيات كلها**
     * كانت الوحيدة غير المرئية للتدقيق: تُوسَّع صلاحيات دورٍ فلا يبقى أثرٌ
     * لمن وسّعها ولا متى — وهو أوّل ما يُسأل عنه بعد أي حادث.
     */
    protected function trail(string $action, Role $role): void
    {
        $x = self::reach($role);
        hub_audit($action, 'roles', $role->id, $role->name, ['after' => [
            'scope' => $role->scope,
            'modules_view' => $x['v'], 'modules_edit' => $x['e'], 'modules_delete' => $x['d'],
            'field_rules' => $x['fields'],
            'flags' => array_keys(array_filter((array) ($role->flags ?? []))),
        ]]);
    }

    public function edit(Role $role)
    {
        $this->gate();
        // دور المالك يتجاوز المصفوفة كلها، فتحريره وهمٌ خالص: كل مربّعٍ فيه بلا أثر
        abort_if((bool) $role->is_owner, 422, 'دور المالك يتجاوز كل الصلاحيات — لا معنى لتحريره');

        return view('roles.form', ['role' => $role, 'ops' => $this->ops,
            'groups' => self::groupedModules(), 'templates' => (array) config('hub_roles.templates', []),
            'others' => Role::where('is_owner', false)->where('id', '!=', $role->id)->orderBy('name')->get()]);
    }

    public function update(Request $r, Role $role)
    {
        $this->gate();
        // **حارس الملكية**: `data()` يكتب is_owner=false في كل حفظ، والمسار مفتوح
        // ولو أخفت الشاشة زرّه — فطلبٌ واحد كان يجرّد دور المالك من ملكيته، وإن
        // كان الوحيد أُقفلت الأدوار والإعدادات والأمان إلى الأبد: إعادة الملكية
        // نفسها تحتاج مالكاً.
        abort_if((bool) $role->is_owner, 422, 'لا يُعدَّل دور المالك — ولا تُنزع ملكيته');

        $role->update($this->data($r, $role));
        $this->trail('تعديل دور', $role);

        return redirect()->route('roles.index')->with('ok', 'حُفظ الدور');
    }

    /** استنساخ دور: البناء من قريبٍ قائم أسرع من البناء من الصفر */
    public function clone(Role $role)
    {
        $this->gate();
        abort_if((bool) $role->is_owner, 422, 'لا يُستنسخ دور المالك');

        $copy = Role::create([
            'name' => \Illuminate\Support\Str::limit($role->name . ' — نسخة', 78, ''),
            'scope' => $role->scope, 'is_owner' => false,
            'matrix' => $role->matrix, 'flags' => $role->flags, 'field_rules' => $role->field_rules,
        ]);

        $this->trail('استنساخ دور', $copy);

        return redirect()->route('roles.edit', $copy)->with('ok', 'استُنسخ الدور — عدّله ثم احفظ');
    }

    public function destroy(Role $role)
    {
        $this->gate();
        abort_if((bool) $role->is_owner, 422, 'لا يُحذف دور المالك');
        abort_if(User::where('role_id', $role->id)->exists(), 422, 'انقل مستخدمي هذا الدور أولاً');
        $this->trail('حذف دور', $role);
        $role->delete();

        return redirect()->route('roles.index')->with('ok', 'حُذف الدور');
    }

    /**
     * قالبٌ جاهز → مصفوفةٌ ورايات. القالب **بداية لا قيد**: يملأ الشبكة ثم
     * تُعدَّل بحرية قبل الحفظ.
     */
    public static function fromTemplate(string $key): array
    {
        $t = config("hub_roles.templates.$key");
        if (! $t) return [];

        $matrix = [];
        $apply = function (string $mod, string $letters) use (&$matrix) {
            if ($letters === '') { unset($matrix[$mod]); return; }
            $row = [];
            foreach (str_split($letters) as $op) if (in_array($op, ['v', 'a', 'e', 'd'], true)) $row[$op] = 1;
            if ($row) { $row['v'] = 1; $matrix[$mod] = $row; }      // لا كتابة بلا عرض
        };

        foreach (self::groupedModules() as $gLabel => $g) {
            $letters = (string) ($t['groups'][$gLabel] ?? '');
            if ($letters === '') continue;
            foreach (array_keys($g['items']) as $mod) $apply($mod, $letters);
        }
        foreach ((array) ($t['mods'] ?? []) as $mod => $letters) {
            if (hub_mod((string) $mod)) $apply((string) $mod, (string) $letters);
        }

        return ['matrix' => $matrix, 'flags' => (array) ($t['flags'] ?? []),
                'scope' => (string) ($t['scope'] ?? 'all'), 'field_rules' => (array) ($t['hide'] ?? [])];
    }

    protected function data(Request $r, ?Role $role = null): array
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'scope' => 'required|in:all,proj']);

        // قالبٌ مُختار يبني الأساس، وما أرسله النموذج يعلو عليه
        $tpl = $r->input('template') ? self::fromTemplate(hub_str($r->input('template'))) : [];

        // **«لم يُرسَل» غير «أُفرِغ عمداً»**: مربّع الاختيار غير المؤشَّر لا يصل
        // في الطلب، فبلا علامةٍ صريحة لا يُفرَّق بين مصفوفةٍ مُفرَّغةٍ عمداً وقسمٍ
        // لم يُرسَل أصلاً — وكان القالب يُعيد ما أزاله المالك بيده.
        $matrixSent = $r->has('matrix') || $r->boolean('matrix_submitted');
        $matrix = $matrixSent ? [] : (array) ($role?->matrix ?? []);
        if ($matrixSent) {
            foreach (array_keys(hub_modules()) as $mod) {
                $row = [];
                foreach (array_keys($this->ops) as $op) {
                    if ($r->boolean("matrix.$mod.$op")) $row[$op] = 1;
                }
                // **الكتابة تستلزم العرض**: دورٌ يعدّل ولا يرى حالةٌ لا تُستعمل —
                // كل قارئ يمرّ بـhub_can(...,'v') أولاً، فالإضافة بلا عرض صمتٌ محض.
                if ($row) { $row['v'] = 1; $matrix[$mod] = $row; }
            }
        }
        if (! $matrixSent && ! $matrix && ! empty($tpl['matrix'])) $matrix = $tpl['matrix'];

        // الأعلام السبعة التي يقرؤها النظام فعلاً (hub_flag). ومربّع الاختيار
        // غير المؤشَّر لا يُرسَل أصلاً، فلا يُفرَّق بين «أُلغيت كلها» و«لم يُرسَل
        // القسم» إلا بعلامةٍ صريحة: `flags_submitted`.
        $flagsSent = $r->has('flags') || $r->boolean('flags_submitted');
        $flags = (array) ($role?->flags ?? []);
        if ($flagsSent) {
            $flags = [];
            foreach (array_keys(self::FLAGS) as $f) {
                if ($r->boolean("flags.$f")) $flags[$f] = 1;
            }
        }
        if (! $flagsSent && ! $flags && ! empty($tpl['flags'])) $flags = $tpl['flags'];

        /*
         * صلاحيات مستوى الحقل — **لا تُبنى من الصفر إلا إن أُرسل قسمُها**.
         *
         * كان القسم يرسل قائمةً منسدلة لكل حقلٍ في النظام (١٢٢٠ قائمة) فيتجاوز
         * الطلبُ `max_input_vars` الافتراضي (١٠٠٠)، وPHP **يقصّ** الزائد بلا
         * خطأ — ثم يُعاد البناء من المقصوص فتُمحى كل قيود الذيل: يحفظ المالك
         * تعديلاً في اسم الدور فيُكشف الراتب لعشرات الأدوار صمتاً.
         * الآن: النموذج لا يرسل إلا القيود القائمة، والعلامة `fr_submitted`
         * تفرّق بين «أُفرِغت عمداً» و«لم يصل القسم».
         */
        $frSent = $r->has('fr') || $r->boolean('fr_submitted');
        $fieldRules = (array) ($role?->field_rules ?? []);
        if ($frSent) {
            $fieldRules = [];
            foreach ((array) $r->input('fr', []) as $mod => $fields) {
                if (! hub_mod((string) $mod)) continue;
                foreach ((array) $fields as $fk => $mode) {
                    if (in_array($mode, ['ro', 'hide'], true)) $fieldRules[$mod][$fk] = $mode;
                }
            }
        }
        if (! $frSent && ! $fieldRules && ! empty($tpl['field_rules'])) $fieldRules = $tpl['field_rules'];

        return $d + ['matrix' => $matrix, 'flags' => $flags, 'is_owner' => false,
                     'field_rules' => $fieldRules ?: null];
    }
}
