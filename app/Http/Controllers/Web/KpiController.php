<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiDef;
use Illuminate\Http\Request;

/**
 * باني معادلات KPI: مؤشرات مخصصة بمعادلة **مُهيكَلة آمنة** — بدائل مُحدَّدة
 * (عدد/مجموع/متوسط) فوق وحدة بفلتر حالة، وعملية بين مقياسين. لا نص حر يُقيَّم.
 *
 * وما بُني يُعدَّل ويُوقَف ويُرتَّب: مؤشرٌ لا يُصحَّح إلا بحذفه وإعادة بنائه من
 * الذاكرة ليس أداةَ قياس — هو ورقةٌ تُمزَّق كلما أخطأتَ رقمَ الهدف.
 */
class KpiController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_monitor(),
            403, 'باني المؤشرات للمالكين ومن يحمل صلاحية المتابعة');
    }

    /**
     * الوحدات المتاحة وأعمدتها الرقمية لبناء المقاييس — **وهي مرجعُ الحالات
     * الوحيد** (WP-8.5): ما ليس في `states` هنا لا يُكتب في معادلة. كانت
     * القائمةُ تُملأ للواجهة وحدَها بينما الكاتبُ يقبل أيّ نصّ، فوُلدت مؤشّراتٌ
     * تُرشِّح حالاتٍ لا وجودَ لها («متأخرة» في المهامّ، «مفتوحة» في التذاكر)
     * فتقرأ صفراً أبداً — وصفرٌ مقابل هدفٍ صفرٍ نزولاً يُعرض «على الهدف».
     */
    protected function catalog(): array
    {
        $mods = [];
        foreach (hub_modules() as $mk => $md) {
            if (! hub_can(auth()->user(), $mk, 'v')) continue;
            $nums = collect($md['fields'])->whereIn('type', ['num', 'big'])
                ->map(fn ($f) => ['key' => $f['key'], 'label' => $f['label']])->values()->all();
            $status = collect($md['fields'])->firstWhere('key', $md['status'] ?? '');
            $mods[$mk] = [
                'label'  => $md['label'],
                'nums'   => $nums,
                'states' => $status['options'] ?? [],
            ];
        }

        return $mods;
    }

    /**
     * الحالةُ المطلوبة موجودةٌ في سجلّ وحدتها.
     *
     * وحدةٌ **بلا حقل حالة** تُرفض كذلك: `hub_kpi_metric` يُسقط الفلترَ صامتاً
     * فتعدّ البطاقةُ كلَّ السجلات تحت اسمٍ يعد بالتصفية (وحدةُ الموردين مثالٌ حيّ).
     */
    protected function stateOk(string $module, ?string $state): bool
    {
        if (($state = trim(hub_str($state ?? ''))) === '') return true;

        $states = (array) ($this->catalog()[$module]['states'] ?? []);

        return $states !== [] && in_array($state, $states, true);
    }

    public function index(Request $r)
    {
        $this->gate();

        // `?edit=` يحمّل المؤشر في الباني نفسه — لا شاشة ثانية ولا نموذج مكرّر
        // `?edit[]=` كان يُمرَّر مصفوفةً إلى find() فتعود Collection ثم تسقط الشاشة
        $editing = ($id = hub_str($r->query('edit'))) ? KpiDef::find($id) : null;

        // صفوفُ المركز (WP-8.5): القيمةُ من `hub_kpi_value` كما كانت، ومعها
        // المالكُ والدورةُ والانحرافُ والاتّجاهُ والحالةُ الصحّية — قراءةٌ واحدة
        $rows = \App\Support\KpiCentre::rows(auth()->user());

        return view('kpis.index', [
            'kpis'    => hub_kpis(null, true),
            'rows'    => $rows,
            'summary' => \App\Support\KpiCentre::summary($rows),
            'off'     => \App\Support\KpiCentre::offTarget($rows),
            'catalog' => $this->catalog(),
            'editing' => $editing,
            'people'  => \Illuminate\Support\Facades\DB::table('users')->whereNull('deleted_at')
                ->where('status', '!=', 'موقوف')->orderBy('name')->orderBy('id')
                ->limit(200)->get(['id', 'name']),
        ]);
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $this->validated($r);

        $k = KpiDef::create($this->attrs($d) + [
            'sort' => (int) KpiDef::max('sort') + 1,
            'created_by' => auth()->id(),
        ]);
        hub_audit('إضافة مؤشر KPI', null, $k->id, $k->name,
            ['after' => ['name' => $k->name, 'formula' => hub_kpi_explain((array) $k->formula)]]);

        return back()->with('ok', '📈 أُضيف المؤشر — قيمته محسوبة الآن من بياناتك');
    }

    /** تعديل مؤشر قائم — الاسم والهدف والاتجاه والمعادلة كلها */
    public function update(Request $r, string $id)
    {
        $this->gate();
        $k = KpiDef::findOrFail($id);
        $d = $this->validated($r);

        $before = ['name' => $k->name, 'target' => $k->target,
                   'formula' => hub_kpi_explain((array) $k->formula)];
        $k->update($this->attrs($d));
        $after = ['name' => $k->name, 'target' => $k->target,
                  'formula' => hub_kpi_explain((array) $k->formula)];

        hub_audit('تعديل مؤشر KPI', null, $k->id, $k->name, ['before' => $before, 'after' => $after]);

        return redirect()->route('kpis.index')->with('ok', '✏️ حُدّث المؤشر «' . $k->name . '»');
    }

    /**
     * إيقاف/تشغيل — الموقوف يختفي من اللوحات والملخّص ويبقى في الباني بمعادلته.
     * حملةٌ انتهت لا تُحذف كي تُبعث في موسمها القادم كما كانت.
     */
    public function toggle(string $id)
    {
        $this->gate();
        $k = KpiDef::findOrFail($id);
        $k->update(['active' => ! $k->active]);
        hub_audit($k->active ? 'تشغيل مؤشر KPI' : 'إيقاف مؤشر KPI', null, $k->id, $k->name);

        return back()->with('ok', $k->active
            ? '▶️ عاد «' . $k->name . '» إلى اللوحات'
            : '⏸ أُوقف «' . $k->name . '» — بقي هنا بمعادلته ولا يظهر في اللوحات');
    }

    /** ترتيب العرض: المؤشر الذي تنظر إليه أولاً يجب أن يكون أولاً */
    public function move(Request $r, string $id)
    {
        $this->gate();
        $dir = $r->input('dir') === 'down' ? 1 : -1;

        $all = KpiDef::orderBy('sort')->orderBy('created_at')->get();
        $i = $all->search(fn ($x) => $x->id === $id);
        if ($i === false) abort(404);
        $j = $i + $dir;
        if ($j < 0 || $j >= $all->count()) return back();

        // الترتيب يُعاد ترقيمه كاملاً: قيم `sort` المتساوية القديمة كانت تُبطل التبديل
        $order = $all->values()->all();
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        foreach ($order as $n => $one) $one->update(['sort' => $n]);

        return back()->with('ok', '↕️ أُعيد الترتيب');
    }

    public function destroy(string $id)
    {
        $this->gate();
        $k = KpiDef::findOrFail($id);
        $name = $k->name;
        hub_audit('حذف مؤشر KPI', null, $k->id, $name,
            ['before' => ['name' => $name, 'formula' => hub_kpi_explain((array) $k->formula)]]);
        $k->delete();

        return back()->with('ok', 'حُذف المؤشر «' . $name . '» — وبقي أثره في التدقيق');
    }

    /* ────────── داخلي ────────── */

    protected function validated(Request $r): array
    {
        return $r->validate([
            'name'     => ['required', 'string', 'max:190'],
            'unit'     => ['nullable', 'string', 'max:30'],
            // `1e400` عددٌ صالح نحوياً ولانهائيٌّ فعلاً — يُرفض قبل الكتابة لا بعدها
            // وحدٌّ أدنى معه (v2.325): السالبُ الضخم كان يمرّ ثم يُسقط MySQL بـ22003
            'target'   => ['nullable', 'numeric', 'min:-999999999999', 'max:999999999999'],
            'good'     => ['required', 'in:up,down'],
            // (WP-8.5) مالكٌ ودورة: «خارج الهدف» لا تصير فعلاً حتى يُعرف من يُسأل
            'owner_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('users', 'id')],
            'period'   => ['nullable', 'string', 'max:20'],
            'a_agg'    => ['required', 'in:count,sum,avg'],
            'a_module' => ['required', 'string', 'max:60'],
            'a_col'    => ['nullable', 'string', 'max:60'],
            'a_st'     => ['nullable', 'string', 'max:120'],
            'combine'  => ['required', 'in:none,ratio_pct,ratio,diff,sum'],
            'b_agg'    => ['nullable', 'in:count,sum,avg'],
            'b_module' => ['nullable', 'string', 'max:60'],
            'b_col'    => ['nullable', 'string', 'max:60'],
            'b_st'     => ['nullable', 'string', 'max:120'],
        ]);
    }

    /** الحقول المحفوظة من مدخلاتٍ محقّقة — واحدةٌ للإضافة والتعديل فلا يفترقان */
    protected function attrs(array $d): array
    {
        // الوحدات لا بد أن تكون مسجَّلة ومرئية — نفس حارس المقياس
        abort_unless(hub_mod($d['a_module']) && hub_can(auth()->user(), $d['a_module'], 'v'), 422);
        // ...والحالةُ لا بد أن تكون في سجلّ وحدتها: فلترٌ ميّتٌ يُولد رقماً كاذباً
        abort_unless($this->stateOk($d['a_module'], $d['a_st'] ?? null), 422,
            'الحالة «' . trim(hub_str($d['a_st'] ?? '')) . '» ليست من خيارات هذه الوحدة — اختر حالةً من قائمتها');

        $formula = [
            'a' => ['agg' => $d['a_agg'], 'module' => $d['a_module'],
                    'col' => $d['a_col'] ?? null, 'st' => $d['a_st'] ?? ''],
            'combine' => $d['combine'],
        ];

        if ($d['combine'] !== 'none') {
            abort_unless(($d['b_module'] ?? null) && hub_mod($d['b_module']) && hub_can(auth()->user(), $d['b_module'], 'v'),
                422, 'اختر وحدة المقياس الثاني');
            abort_unless($this->stateOk($d['b_module'], $d['b_st'] ?? null), 422,
                'حالةُ المقياس الثاني ليست من خيارات وحدته — اختر حالةً من قائمتها');
            $formula['b'] = ['agg' => $d['b_agg'] ?: 'count', 'module' => $d['b_module'],
                             'col' => $d['b_col'] ?? null, 'st' => $d['b_st'] ?? ''];
        }

        $out = ['name' => $d['name'], 'unit' => $d['unit'] ?? null,
                'target' => hub_num($d['target'] ?? null), 'good' => $d['good'], 'formula' => $formula];

        // العمودان مضافان في هجرة الطور ٨ — يُكتبان بحارسٍ فلا تسقط الكتابة
        // على نسخةٍ لم تُرحَّل بعد (الإضافةُ لا الكسر)
        if (hub_has_col('kpi_defs', 'owner_id')) $out['owner_id'] = $d['owner_id'] ?? null;
        if (hub_has_col('kpi_defs', 'period')) {
            $out['period'] = ($p = trim(hub_str($d['period'] ?? ''))) === ''
                ? null : mb_substr($p, 0, 20);      // القصُّ بعرض العمود الصريح عند الكاتب
        }

        return $out;
    }
}
