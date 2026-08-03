<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * التخصيص الشخصي: شاشة البداية، إظهار/إخفاء/تسمية عناصر القائمة، ترتيب
 * المجموعات، وبطاقات لوحة التحكم — كله لكل مستخدم على حدة، وكله عرضٌ فقط:
 * الإخفاء لا يمس أي صلاحية.
 */
class PrefController extends Controller
{
    /**
     * بطاقات لوحة التحكم القابلة للإخفاء — مصدرها سجل الودجات.
     * كانت قائمة ثابتة هنا لا يعرفها `DashboardController`، فإضافة ودجة تتطلب
     * تعديل ملفين ونسيان أحدهما يعطي بطاقةً بلا اسم أو اسماً بلا بطاقة.
     */
    protected function dashCards(): array
    {
        return \App\Support\WidgetRegistry::labels();
    }

    public function edit()
    {
        $u = auth()->user();

        return view('personalize', [
            'top'    => hub_top_links($u),
            'groups' => $this->rawGroups($u),
            'cards'  => $this->dashCards(),
        ]);
    }

    public function update(Request $r)
    {
        $u = auth()->user();
        $data = $r->validate([
            'home'         => ['nullable', 'string', 'max:60'],
            'nav_style'    => ['nullable', 'in:spaces,classic'],
            'hidden'       => ['nullable', 'array'],
            'hidden.*'     => ['string', 'max:60'],
            'hidden_top'   => ['nullable', 'array'],
            'hidden_top.*' => ['string', 'max:60'],
            'names'        => ['nullable', 'array'],
            'names.*'      => ['nullable', 'string', 'max:40'],
            'order'        => ['nullable', 'array'],
            'order.*'      => ['nullable', 'integer', 'min:0', 'max:99'],
            'dash_hidden'  => ['nullable', 'array'],
            'dash_hidden.*' => ['string', 'max:30'],
            'mute'         => ['nullable', 'array'],
            'mute.*'       => ['string', 'max:30'],
        ]);

        // شاشة البداية: من الكتالوج أو m:وحدة يراها — الاختيار غير الصالح يُهمل
        $home = (string) ($data['home'] ?? '');
        $validHome = $home === '' || $home === 'dashboard'
            || collect(hub_top_links($u))->contains(fn ($l) => $l['key'] === $home)
            || (str_starts_with($home, 'm:') && hub_mod(substr($home, 2)) && hub_can($u, substr($home, 2), 'v'));

        // ترتيب المجموعات: رقم لكل مجموعة → قائمة أسماء مرتبة
        $order = collect($data['order'] ?? [])->filter(fn ($v) => $v !== null)
            ->sort()->keys()->values()->all();

        // التسميات البديلة: الفارغ يسقط
        $names = collect($data['names'] ?? [])->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '')->all();

        // دمج فوق الموجود لا استبداله — كي لا يمحو حفظُ هذه الشاشة قسم `cols`
        // (أعمدة الجداول) الذي يديره مسار آخر
        $prefs = (array) $u->prefs;
        $prefs['home'] = $validHome && $home !== '' && $home !== 'dashboard' ? $home : null;
        // نمط التنقل: 'spaces' افتراضي (مساحات العمل) لا يُخزَّن، و'classic'
        // (القائمة الكاملة) يُخزَّن صراحةً — فالافتراضي يبقى بلا أثر في prefs
        $navStyle = ($data['nav_style'] ?? 'spaces') === 'classic' ? 'classic' : null;
        $prefs['nav'] = array_filter([
            'style'      => $navStyle,
            'hidden'     => array_values($data['hidden'] ?? []),
            'hidden_top' => array_values($data['hidden_top'] ?? []),
            'names'      => $names,
            'order'      => $order,
            // المثبّتات يديرها مسار toggle مستقل — تُحمَل هنا كما هي فلا يمحوها حفظُ هذه الشاشة
            'pins'       => array_values((array) data_get($u->prefs, 'nav.pins', [])),
        ]);
        $prefs['dash'] = array_filter([
            'hidden' => array_values(array_intersect($data['dash_hidden'] ?? [], array_keys($this->dashCards()))),
        ]);
        // كتم أنواع الإشعارات — ضمن القائمة القابلة للكتم فقط
        $prefs['mute'] = array_values(array_intersect($data['mute'] ?? [], array_keys(\App\Models\HubNotification::MUTEABLE)));
        $u->prefs = array_filter($prefs, fn ($v) => $v !== null && $v !== [] && $v !== '') ?: null;
        $u->save();

        return back()->with('ok', 'حُفظ تخصيصك — القائمة والبداية ولوحة التحكم صارت على ذوقك');
    }

    /** إعادة الضبط: يمسح كل التخصيص ويعيد الافتراضي */
    public function reset()
    {
        auth()->user()->update(['prefs' => null]);

        return back()->with('ok', 'أُعيد الضبط الافتراضي');
    }

    /**
     * تثبيت/فكّ وجهةٍ في رصيف «مثبّتاتي». الرمزُ يُتحقَّق أنه وجهةٌ يجوز لهذا
     * المستخدم تثبيتها (وحدةٌ يراها أو رابطٌ يُسمح له به) — فلا يُثبَّت ما لا
     * يُفتح. سقفٌ ١٢ كي يبقى الرصيفُ رصيفاً لا قائمةً ثانية.
     */
    public function togglePin(Request $r)
    {
        $u = auth()->user();
        $token = trim((string) $r->input('token'));

        if (! isset(hub_pin_targets($u)[$token])) {
            return back()->with('err', 'وجهةٌ لا تُثبَّت — غير معروفةٍ أو خارج صلاحيتك');
        }

        $pins = array_values((array) data_get($u->prefs, 'nav.pins', []));
        if (in_array($token, $pins, true)) {
            $pins = array_values(array_filter($pins, fn ($t) => $t !== $token));
            $msg = 'أُزيل من مثبّتاتك';
        } elseif (count($pins) >= 12) {
            return back()->with('err', 'بلغتَ سقف ١٢ مثبَّتاً — أزِل واحداً قبل إضافة آخر');
        } else {
            $pins[] = $token;
            $msg = '📌 أُضيف لمثبّتاتك — تجده أعلى الشريط';
        }

        $prefs = (array) $u->prefs;
        $prefs['nav'] = array_filter(((array) ($prefs['nav'] ?? [])) + ['pins' => []]);
        $prefs['nav']['pins'] = $pins;
        if (! $pins) unset($prefs['nav']['pins']);
        $prefs['nav'] = array_filter($prefs['nav']);
        $u->prefs = array_filter($prefs, fn ($v) => $v !== null && $v !== [] && $v !== '') ?: null;
        $u->save();

        return back()->with('ok', $msg);
    }

    /** حفظ أعمدة الجدول الظاهرة لوحدة — تُخزَّن بترتيب سجل الوحدة */
    public function saveCols(Request $r)
    {
        $data = $r->validate([
            'module' => ['required', 'string', 'max:60'],
            'cols'   => ['nullable', 'array', 'max:12'],
            'cols.*' => ['string', 'max:60'],
        ]);
        $def = hub_mod($data['module']);
        abort_unless($def && hub_can(auth()->user(), $data['module'], 'v'), 404);

        $valid = collect($def['fields'])->pluck('key')->all();
        $cols = $r->boolean('reset') ? []
            : array_values(array_intersect($data['cols'] ?? [], $valid));

        $u = auth()->user();
        $prefs = (array) $u->prefs;
        if ($cols) $prefs['cols'][$data['module']] = $cols;
        else unset($prefs['cols'][$data['module']]);
        $u->prefs = array_filter($prefs) ?: null;
        $u->save();

        return back()->with('ok', $cols ? 'حُفظت أعمدتك لهذه الوحدة' : 'عادت الأعمدة الافتراضية');
    }

    /** حفظ العرض الحالي (فلاتر + بحث + فرز) باسم */
    public function storeView(Request $r)
    {
        $data = $r->validate([
            'module'  => ['required', 'string', 'max:60'],
            'name'    => ['required', 'string', 'max:60'],
            'query'   => ['nullable', 'string', 'max:2000'],
            'default' => ['nullable', 'boolean'],
        ]);
        abort_unless(hub_mod($data['module']) && hub_can(auth()->user(), $data['module'], 'v'), 404);

        // تُحفظ معايير العرض فقط — لا صفحة ولا معرف عرض سابق
        parse_str((string) ($data['query'] ?? ''), $qs);
        unset($qs['page'], $qs['view']);
        $query = http_build_query($qs);

        // رد عادي لا abort: النموذج غير مُعزَّز بـhtmx فالتحويل يُظهر الرسالة في منطقة الفلاش
        if (\App\Models\SavedView::where('user_id', auth()->id())
            ->where('module', $data['module'])->count() >= 30) {
            return back()->with('err', 'بلغت حد ٣٠ عرضاً لهذه الوحدة — احذف عرضاً قديماً أولاً');
        }

        if ($r->boolean('default')) {
            \App\Models\SavedView::where('user_id', auth()->id())
                ->where('module', $data['module'])->update(['is_default' => false]);
        }

        $v = \App\Models\SavedView::create([
            'user_id' => auth()->id(), 'module' => $data['module'],
            'name' => $data['name'], 'query' => $query ?: null,
            'is_default' => $r->boolean('default'),
        ]);

        return redirect($v->url())->with('ok', 'حُفظ العرض «' . $v->name . '»');
    }

    /** تبديل العرض الافتراضي / حذفه — لصاحبه فقط */
    public function defaultView(string $id)
    {
        $v = \App\Models\SavedView::where('user_id', auth()->id())->findOrFail($id);
        if (! $v->is_default) {
            \App\Models\SavedView::where('user_id', auth()->id())
                ->where('module', $v->module)->update(['is_default' => false]);
        }
        $v->update(['is_default' => ! $v->is_default]);

        return back()->with('ok', $v->is_default ? 'صار «' . $v->name . '» عرضك الافتراضي' : 'أُلغي الافتراضي');
    }

    public function destroyView(string $id)
    {
        $v = \App\Models\SavedView::where('user_id', auth()->id())->findOrFail($id);
        $v->delete();

        return back()->with('ok', 'حُذف العرض «' . $v->name . '»');
    }

    /** المجموعات الخام (قبل تخصيص المستخدم) لعرضها في صفحة التخصيص */
    protected function rawGroups($u): array
    {
        $out = [];
        foreach (config('hub_nav', []) as $g) {
            $items = [];
            foreach ($g['items'] as $k) {
                if (hub_mod($k) && hub_can($u, $k, 'v')) {
                    $items[] = ['key' => $k, 'label' => hub_mod($k)['label']];
                }
            }
            if ($items) $out[] = ['g' => $g['g'], 'icon' => $g['icon'], 'items' => $items];
        }

        return $out;
    }
}
