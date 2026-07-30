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
    /** بطاقات لوحة التحكم القابلة للإخفاء */
    public const DASH_CARDS = [
        'counts' => 'بطاقات العدّ العلوية',
        'expiry' => '🔔 ينتهي قريباً',
        'apps'   => '📱 تقدم التطبيقات',
        'donut'  => '✅ المهام بالحالة',
        'recent' => '📌 آخر ما فتحت',
        'due'    => '⏰ مهام تقترب مواعيدها',
        'audits' => '🕘 آخر النشاطات',
    ];

    public function edit()
    {
        $u = auth()->user();

        return view('personalize', [
            'top'    => hub_top_links($u),
            'groups' => $this->rawGroups($u),
            'cards'  => self::DASH_CARDS,
        ]);
    }

    public function update(Request $r)
    {
        $u = auth()->user();
        $data = $r->validate([
            'home'         => ['nullable', 'string', 'max:60'],
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

        $u->prefs = array_filter([
            'home'        => $validHome ? $home : null,
            'nav'         => array_filter([
                'hidden'     => array_values($data['hidden'] ?? []),
                'hidden_top' => array_values($data['hidden_top'] ?? []),
                'names'      => $names,
                'order'      => $order,
            ]),
            'dash'        => array_filter([
                'hidden' => array_values(array_intersect($data['dash_hidden'] ?? [], array_keys(self::DASH_CARDS))),
            ]),
        ]) ?: null;
        $u->save();

        return back()->with('ok', 'حُفظ تخصيصك — القائمة والبداية ولوحة التحكم صارت على ذوقك');
    }

    /** إعادة الضبط: يمسح كل التخصيص ويعيد الافتراضي */
    public function reset()
    {
        auth()->user()->update(['prefs' => null]);

        return back()->with('ok', 'أُعيد الضبط الافتراضي');
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
