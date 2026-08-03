<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\WidgetRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * باني اللوحات: إنشاء لوحة، تسمية، افتراضية، مشاركة، وإضافة ودجات وحذفها.
 *
 * الودجة تُختار بمفتاحها من `WidgetRegistry` — والمفتاح يُتحقق منه عند الحفظ **وعند
 * العرض** معاً: فلو نُزعت بوابة ودجة عن مستخدم بعد إضافتها، سقطت من لوحته حينها.
 * لوحة الدور للمالك وحده، إذ هي تغييرٌ يمسّ شاشات غيره.
 */
class BoardController extends Controller
{
    /** لوحة يملك المستخدم تعديلها، وإلا 404 — لا نكشف وجودها لغير صاحبها */
    protected function owned(string $id): Dashboard
    {
        $b = Dashboard::findOrFail($id);
        abort_unless($b->editableBy(auth()->user()), 404);

        return $b;
    }

    public function index()
    {
        $user = auth()->user();

        return view('boards.index', [
            'boards' => Dashboard::withCount('widgets')
                ->where(fn ($w) => $w->where('owner_id', $user->id)
                    ->orWhere('role_id', $user->role_id)->orWhere('shared', true))
                ->orderBy('sort')->orderBy('name')->get()
                ->filter(fn ($b) => $b->visibleTo($user))->values(),
        ]);
    }

    public function store(Request $r)
    {
        $user = auth()->user();
        $d = $r->validate([
            'name'    => ['required', 'string', 'max:80'],
            'role_id' => ['nullable', 'string', 'exists:roles,id'],
        ]);

        // لوحة الدور تمسّ شاشات الآخرين — للمالك وحده
        $roleId = ($d['role_id'] ?? null) && hub_is_owner($user) ? $d['role_id'] : null;

        $b = Dashboard::create([
            'name'     => $d['name'],
            'owner_id' => $roleId ? null : $user->id,
            'role_id'  => $roleId,
        ]);

        return redirect()->route('boards.edit', $b->id)->with('ok', 'أُنشئت اللوحة — أضف ودجاتها');
    }

    public function edit(string $id)
    {
        $b = $this->owned($id);
        $used = $b->widgets->pluck('widget_key')->all();

        return view('boards.edit', [
            'board'     => $b,
            'used'      => $used,
            // ما يستطيع هذا المستخدم إضافته: المرئي له فقط
            'available' => array_diff_key(WidgetRegistry::visibleFor(auth()->user()), array_flip($used)),
        ]);
    }

    public function update(Request $r, string $id)
    {
        $b = $this->owned($id);
        $d = $r->validate([
            'name'       => ['required', 'string', 'max:80'],
            'is_default' => ['nullable', 'boolean'],
            'shared'     => ['nullable', 'boolean'],
        ]);

        // افتراضية واحدة لكل مالك — الباقي يُنزع بلا ضجيج
        if ($r->boolean('is_default') && $b->owner_id) {
            Dashboard::where('owner_id', $b->owner_id)->where('id', '!=', $b->id)
                ->update(['is_default' => false]);
        }

        $b->update([
            'name'       => $d['name'],
            'is_default' => $r->boolean('is_default'),
            'shared'     => hub_is_owner(auth()->user()) ? $r->boolean('shared') : $b->shared,
        ]);

        return back()->with('ok', 'حُفظت اللوحة');
    }

    public function destroy(string $id)
    {
        $b = $this->owned($id);
        $b->widgets()->delete();
        $b->delete();

        return redirect()->route('boards.index')->with('ok', 'حُذفت اللوحة');
    }

    public function addWidget(Request $r, string $id)
    {
        $b = $this->owned($id);
        $d = $r->validate([
            'widget_key' => ['required', 'string', Rule::in(array_keys(WidgetRegistry::definitions()))],
        ]);

        // لا يُضاف ما لا يراه المستخدم أصلاً — الباني لا يمنح صلاحية
        abort_unless(WidgetRegistry::isVisible($d['widget_key'], auth()->user()), 403,
            'لا تملك صلاحية هذه الودجة');

        if ($b->widgets()->where('widget_key', $d['widget_key'])->exists()) {
            return back()->with('ok', 'الودجة موجودة في اللوحة');
        }

        $size = WidgetRegistry::get($d['widget_key'])['size'] ?? ['w' => 6, 'h' => 2];
        DashboardWidget::create([
            'dashboard_id' => $b->id,
            'widget_key'   => $d['widget_key'],
            'x' => 0, 'y' => (int) ($b->widgets()->max('y') + 1),
            'w' => $size['w'], 'h' => $size['h'],
        ]);

        return back()->with('ok', 'أُضيفت الودجة');
    }

    /**
     * حفظ التخطيط: الترتيب والعرض والارتفاع دفعةً واحدة.
     *
     * الترتيب يُؤخذ من ترتيب `order[]` كما وصل — فسحبُ العنصر في المتصفح يعيد ترتيب
     * الحقول المخفية، ولا حاجة لحقل موضعٍ يُحرَّر بيد المستخدم. والمعرِّفات تُصفّى على
     * ودجات هذه اللوحة وحدها، فمعرِّفٌ من لوحة أخرى يسقط بلا أثر.
     */
    public function saveLayout(Request $r, string $id)
    {
        $b = $this->owned($id);
        $d = $r->validate([
            'order'   => ['nullable', 'array'],
            'order.*' => ['string'],
            'w'       => ['nullable', 'array'],
            'w.*'     => ['integer', 'min:3', 'max:12'],
            'h'       => ['nullable', 'array'],
            'h.*'     => ['integer', 'min:1', 'max:6'],
        ]);

        $mine = $b->widgets()->pluck('id')->all();
        $order = array_values(array_intersect($d['order'] ?? [], $mine));

        foreach ($order as $i => $wid) {
            $w = ['y' => $i, 'x' => 0];
            if (isset($d['w'][$wid])) $w['w'] = (int) $d['w'][$wid];
            if (isset($d['h'][$wid])) $w['h'] = (int) $d['h'][$wid];
            DashboardWidget::where('dashboard_id', $b->id)->where('id', $wid)->update($w);
        }

        return back()->with('ok', 'حُفظ التخطيط');
    }

    public function removeWidget(string $id, string $widgetId)
    {
        $b = $this->owned($id);
        $b->widgets()->where('id', $widgetId)->delete();

        return back()->with('ok', 'أُزيلت الودجة');
    }
}
