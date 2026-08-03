<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use Illuminate\Http\Request;

/** باني مسارات العمل — «عندما يحدث كذا ← افعل كذا» بلا كود (للمالكين) */
class FlowController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'باني المسارات للمالكين فقط');
    }

    public function index(Request $r)
    {
        $this->gate();
        $module = hub_str($r->query('m', ''));
        if ($module !== '') abort_unless(hub_mod($module), 404);

        $all = Flow::orderBy('module')->orderByDesc('enabled')->orderBy('name')->get();

        // بحثٌ وفلترة: بـ٨٩ مساراً صارت القائمة المسطّحة غير صالحة للاستعمال
        $q = trim(hub_str($r->query('q', '')));
        $only = hub_str($r->query('only', ''));          // on|off
        $group = hub_str($r->query('g', ''));
        $flows = $all->filter(function ($f) use ($q, $only, $group) {
            if ($only === 'on' && ! $f->enabled) return false;
            if ($only === 'off' && $f->enabled) return false;
            if ($group !== '' && $this->groupOf($f->module) !== $group) return false;
            if ($q !== '') {
                $hay = $f->name . ' ' . (hub_mod($f->module)['label'] ?? $f->module) . ' ' . $f->status_to;
                if (mb_stripos($hay, $q) === false) return false;
            }

            return true;
        });

        // التجميع بمجموعات التنقل نفسها — تصنيفٌ واحد يعرفه المستخدم أصلاً
        $grouped = $flows->groupBy(fn ($f) => $this->groupOf($f->module))
            ->sortKeys();

        // التغطية: أي وحدةٍ لها مسار وأيها بلا — «ماذا ينقصني؟» سؤالٌ له جواب
        $withFlows = $all->pluck('module')->unique()->all();
        $coverage = [];
        foreach (config('hub_nav', []) as $g) {
            $has = []; $missing = [];
            foreach ($g['items'] as $mk) {
                if (! hub_mod($mk)) continue;
                in_array($mk, $withFlows, true) ? $has[] = $mk : $missing[] = $mk;
            }
            if ($has || $missing) $coverage[$g['g']] = ['icon' => $g['icon'], 'has' => $has, 'missing' => $missing];
        }

        return view('flows.index', [
            'module'   => $module,
            'def'      => $module ? hub_mod($module) : null,
            'flows'    => $flows,
            'grouped'  => $grouped,
            'coverage' => $coverage,
            'total'    => $all->count(),
            'onCount'  => $all->where('enabled', true)->count(),
            'q'        => $q, 'only' => $only, 'group' => $group,
            'users'    => \App\Models\User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /** مجموعة التنقل التي تنتمي إليها وحدة المسار — للتجميع في الشاشة */
    protected function groupOf(string $module): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (config('hub_nav', []) as $g) {
                foreach ($g['items'] as $mk) $map[$mk] = $g['g'];
            }
        }

        return $map[$module] ?? 'أخرى';
    }

    /** تفعيل/تعطيل مجموعةٍ كاملة — مراجعة ٨٩ مساراً واحداً واحداً غير عملية */
    public function bulk(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'g'  => ['required', 'string', 'max:60'],
            'do' => ['required', 'in:on,off'],
        ]);

        $ids = Flow::all()->filter(fn ($f) => $this->groupOf($f->module) === $d['g'])->pluck('id');
        abort_if($ids->isEmpty(), 422, 'لا مسارات في هذه المجموعة');
        Flow::whereIn('id', $ids)->update(['enabled' => $d['do'] === 'on']);

        return back()->with('ok', ($d['do'] === 'on' ? '✅ فُعّلت ' : '⏸ عُطّلت ')
            . $ids->count() . ' مسار في «' . $d['g'] . '»');
    }

    /** تحقّق المسار — مشترك بين الإنشاء والتعديل فلا يتفرّق العقد بينهما */
    protected function validated(Request $r): array
    {
        $d = $r->validate([
            'name'       => ['required', 'string', 'max:190'],
            'm'          => ['required', 'string'],
            'event'      => ['required', \Illuminate\Validation\Rule::in(
                array_merge(['created', 'updated', 'status'], \App\Support\HubEvents::semanticNames()))],
            'status_to'  => ['nullable', 'string', 'max:120'],
            'cond_field' => ['nullable', 'string', 'max:80'],
            'cond_op'    => ['nullable', 'in:eq,has,gt,lt'],
            'cond_value' => ['nullable', 'string', 'max:300'],
        ]);
        abort_unless(hub_mod($d['m']), 404);

        // حقلا الشرط والتعيين ينتميان لوحدة المسار المختارة: تبديل الوحدة كان
        // يترك cond_field معلّقاً في الهواء — وcondPass الآن fail-closed فيتعطّل
        // المسار بصمت؛ الرفضُ هنا برسالةٍ خيرٌ من تعطّلٍ لا يفهمه أحد
        $keys = collect(hub_mod($d['m'])['fields'])->pluck('key')->all();
        $errors = [];
        if (($d['cond_field'] ?? '') !== '' && ! in_array($d['cond_field'], $keys, true)) {
            $errors['cond_field'] = 'حقل الشرط ليس من حقول الوحدة المختارة — اختر حقلاً منها أو أفرغ الشرط';
        }
        if ($r->boolean('a_set') && ! in_array(hub_str($r->input('a_set_field', '')), $keys, true)) {
            $errors['a_set_field'] = 'حقل التعيين ليس من حقول الوحدة المختارة';
        }
        if ($errors) throw \Illuminate\Validation\ValidationException::withMessages($errors);

        return $d;
    }

    /** تجميع الإجراءات المفعّلة من النموذج — مشترك بين الإنشاء والتعديل */
    protected function collectActions(Request $r): array
    {
        $actions = [];
        if ($r->boolean('a_notify')) {
            $actions[] = ['type' => 'notify', 'to' => hub_str($r->input('a_notify_to', 'owners')),
                          'text' => hub_str($r->input('a_notify_text', ''))];
        }
        if ($r->boolean('a_tg')) $actions[] = ['type' => 'tg', 'text' => hub_str($r->input('a_tg_text', ''))];
        if ($r->boolean('a_mail')) {
            $actions[] = ['type' => 'mail', 'to_email' => hub_str($r->input('a_mail_to', '')),
                          'text' => hub_str($r->input('a_mail_text', ''))];
        }
        if ($r->boolean('a_task')) {
            $actions[] = ['type' => 'task', 'text' => hub_str($r->input('a_task_title', '')),
                          'assignee' => $r->input('a_task_assignee') ?: null];
        }
        if ($r->boolean('a_set')) {
            $actions[] = ['type' => 'set', 'field' => hub_str($r->input('a_set_field', '')),
                          'value' => hub_str($r->input('a_set_value', ''))];
        }

        return $actions;
    }

    public function store(Request $r)
    {
        $this->gate();
        $d = $this->validated($r);
        $actions = $this->collectActions($r);
        if (! $actions) return back()->withErrors(['actions' => 'فعّل إجراءً واحداً على الأقل'])->withInput();

        Flow::create([
            'name' => $d['name'], 'module' => $d['m'], 'event' => $d['event'],
            'status_to' => $d['status_to'] ?? null,
            'cond_field' => $d['cond_field'] ?? null,
            'cond_op' => $d['cond_op'] ?? 'eq',
            'cond_value' => $d['cond_value'] ?? null,
            'actions' => $actions, 'enabled' => true, 'created_by' => auth()->id(),
        ]);

        return redirect()->route('flows.index', ['m' => $d['m']])->with('ok', '🪄 أُنشئ المسار وهو مفعّل الآن');
    }

    /**
     * شاشة تعديل مسارٍ قائم — كان المسار يُنشأ ويُحذف ولا يُعدَّل، فتصحيح
     * كلمةٍ في نصّ إشعار يعني حذفه وإعادة بنائه من الصفر (وتضيع عدّادات
     * تشغيله وتاريخه). النموذج نفسه يخدم الحالتين مُعبّأً بقيم المسار.
     */
    public function edit(string $id)
    {
        $this->gate();
        $flow = Flow::findOrFail($id);
        $def = hub_mod($flow->module);
        abort_unless($def, 404, 'وحدة هذا المسار غير مثبّتة');

        return view('flows.edit', [
            'flow'   => $flow,
            'module' => $flow->module,
            'def'    => $def,
            'users'  => \App\Models\User::whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $r, string $id)
    {
        $this->gate();
        $flow = Flow::findOrFail($id);
        $d = $this->validated($r);
        $actions = $this->collectActions($r);
        if (! $actions) return back()->withErrors(['actions' => 'فعّل إجراءً واحداً على الأقل'])->withInput();

        $flow->update([
            'name' => $d['name'], 'module' => $d['m'], 'event' => $d['event'],
            'status_to' => $d['status_to'] ?? null,
            'cond_field' => $d['cond_field'] ?? null,
            'cond_op' => $d['cond_op'] ?? 'eq',
            'cond_value' => $d['cond_value'] ?? null,
            'actions' => $actions,
        ]);
        hub_audit('تعديل مسار', 'autos', $flow->id, $flow->name);

        return redirect()->route('flows.index', ['m' => $flow->module])
            ->with('ok', '✏️ حُفظ تعديل المسار — عدّاد تشغيلاته وتاريخه كما هما');
    }

    /** استنساخ مسار: أسرع طريق لبناء متغيّرٍ منه — يولد معطّلاً حتى تراجعه */
    public function duplicate(string $id)
    {
        $this->gate();
        $src = Flow::findOrFail($id);
        $copy = $src->replicate(['runs', 'last_run_at', 'created_by']);
        $copy->name = \Illuminate\Support\Str::limit($src->name . ' (نسخة)', 190, '');
        $copy->enabled = false;
        $copy->runs = 0;
        $copy->last_run_at = null;
        $copy->created_by = auth()->id();
        $copy->save();

        return redirect()->route('flows.edit', $copy->id)
            ->with('ok', '📑 نُسخ المسار معطّلاً — عدّله ثم فعّله');
    }

    public function toggle(string $id)
    {
        $this->gate();
        $f = Flow::findOrFail($id);
        $f->update(['enabled' => ! $f->enabled]);

        return back()->with('ok', $f->enabled ? 'فُعّل المسار' : 'عُطّل المسار');
    }

    public function destroy(string $id)
    {
        $this->gate();
        Flow::findOrFail($id)->delete();

        return back()->with('ok', 'حُذف المسار');
    }

    /**
     * Sandbox: جرّب مساراً على سجل حقيقي وشاهد ما **سيحدث** — بلا تنفيذ.
     * أحدث ٢٠ سجلاً من وحدة المسار للاختيار، والتقرير من محرك المسارات نفسه.
     */
    public function sandbox(Request $r, string $id)
    {
        $this->gate();
        $flow = Flow::findOrFail($id);
        $def = hub_mod($flow->module);
        abort_unless($def, 404, 'وحدة هذا المسار غير مثبّتة');

        $class = '\\App\\Models\\' . $def['model'];
        $recent = class_exists($class)
            ? $class::query()->orderByDesc('created_at')->limit(20)
                ->get(['id', hub_display_col($flow->module), 'created_at'])
            : collect();

        $result = null; $record = null;
        if ($rid = $r->query('rid')) {
            $record = class_exists($class) ? $class::find($rid) : null;
            if ($record) {
                // للحدث «status» نحاكي التحول إلى الحالة المطلوبة في المسار
                $statusTo = $flow->event === 'status' ? (string) $flow->status_to : null;
                $result = \App\Support\FlowRunner::simulate($flow, $flow->module, $record, $statusTo);
            }
        }

        return view('flows.sandbox', [
            'flow' => $flow, 'def' => $def, 'recent' => $recent,
            'record' => $record, 'result' => $result,
            'display' => hub_display_col($flow->module),
        ]);
    }
}
