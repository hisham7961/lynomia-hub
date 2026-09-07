<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\DigitalAssets;
use App\Support\Workspaces;
use Illuminate\Http\Request;

/**
 * **مساحةُ العمل التقنية** (Work OS · الطور H · WP-H.3 · §37–38)
 *
 * **توسيعٌ لمساحة `/w/digital` لا بديلٌ عنها**: تبويباتٌ تجمع وحداتِ البنية
 * القائمةَ (خوادم/خزنة/قواعد/نطاقات…) بعدّاداتها المنطَّقة عبر `hub_scope` —
 * القارئُ القائمُ نفسُه، عدٌّ **مُرشَّحٌ** لا خام — وتحليلاتِ `DigitalAssets`
 * (نصفُ قطرِ الانفجار وصحةُ الخزنة) **من محرّكها المخبّأ لا بإعادة حساب**:
 * `DigitalAssets::all()` بمفتاح `hub_scope_key` المعزولِ بقارئه.
 *
 * الحرسُ ثلاثُ طبقاتٍ فوق بعضها، بالترتيب:
 *  ١) **رفضُ العميل الصلب ٤٠٤** — حسابُ عميلٍ (`hub_is_client`) **أو** أيُّ قارئٍ
 *     معزولٍ بعملاء (`hub_client_ids() !== null`): البنيةُ التقنيةُ لا يراها مَن
 *     نافذتُه نافذةُ عميلٍ أبداً — **فوق المصفوفة** لا بديلاً عنها (وPortalGuard
 *     قبل المتحكّم سياجٌ أوّلُ لحساب العميل؛ هنا يُعاد الرفضُ دفاعاً في العمق
 *     ويُوسَّع للداخليّ المعزول بعملاء الذي لا يمسّه الحارس).
 *  ٢) `$owner || hub_monitor` — كسائر اللوحات التحليلية (نظيرُ مركز الأصول الرقمية).
 *  ٣) `hub_org_analytics_guard` — التحليلاتُ على مستوى المنشأة كلِّها، فتُمنع عن
 *     المعزول بشركاتٍ (٤٠٣): نفسُ حارسِ المركز الذي تُجمَع أرقامُه هنا.
 *
 * والأسرارُ **مراجعُ** لا قيَم: عنوانٌ ونوعٌ فقط في كل ما يُعرَض — القيمةُ خلف
 * منفذ `revealSecret` المسجَّل (أثرُ «عرض حساس» + step-up) وحدَه.
 */
class TechWorkspaceController extends Controller
{
    /**
     * تبويباتُ المساحة: **تجميعٌ** لوحدات البنية القائمة في `hub_nav` — لا وحدةَ
     * جديدةً ولا سِكّةَ ثانية. تبويبٌ كلُّ وحداته محجوبةٌ عن القارئ لا يُبنى أصلاً
     * (ترشيحٌ خادميٌّ لا إخفاءُ JS)، و«نظرة عامة» و«التحليلات» بلا وحداتٍ فتبقيان.
     */
    protected const TABS = [
        'overview'  => ['label' => '🧭 نظرة عامة',         'modules' => []],
        'infra'     => ['label' => '🖥️ الخوادم والبنية',   'modules' => ['servers', 'dbs', 'domains', 'websites', 'apis']],
        'apps'      => ['label' => '📦 التطبيقات والنشر',  'modules' => ['apps', 'code', 'deploys', 'changes', 'deps', 'incidents']],
        'accounts'  => ['label' => '🔑 الحسابات والاتصال', 'modules' => ['accounts', 'emails', 'phones', 'carriers', 'social', 'posts']],
        'vault'     => ['label' => '🔐 الخزنة',            'modules' => ['vault']],
        'analytics' => ['label' => '🕸️ التحليلات',         'modules' => []],
    ];

    public function index(Request $r)
    {
        $u = auth()->user();

        // ١) الرفضُ الصلب: عميلٌ أو معزولٌ بعملاء → ٤٠٤ (لا نُثبت وجودَ البنية)
        abort_if(hub_is_client($u) || hub_client_ids() !== null, 404);

        // ٢) كسائر اللوحات التحليلية: مالكٌ أو حاملُ راية المراقبة
        abort_unless(hub_is_owner($u) || hub_monitor($u), 403,
            'مساحةُ العمل التقنية للمالكين ومن يحمل صلاحية المتابعة');

        // ٣) التحليلاتُ على مستوى المنشأة — يُمنع عنها المعزولُ بشركات (٤٠٣)
        hub_org_analytics_guard();

        // وحداتُ المساحة المرئيّة — من مساحة /w/digital نفسِها: hub_nav مصدرُ
        // الحقيقة و`hub_can` مُرشِّحاً (Workspaces::find) — لا قائمةَ وحداتٍ ثانية
        $ws = Workspaces::find('digital', $u);
        $visible = $ws['modules'] ?? [];

        // التبويبات المرشَّحة خادميّاً — بترتيب التعريف الثابت (لا قرعة)
        $tabs = [];
        foreach (self::TABS as $key => $def) {
            $mods = array_values(array_intersect($def['modules'], $visible));
            if ($def['modules'] && ! $mods) continue;
            $tabs[$key] = ['key' => $key, 'label' => $def['label'], 'modules' => $mods];
        }

        // تبويبٌ مطلوبٌ صراحةً وغيرُ متاح → ٤٠٤ لا صفحةٌ فارغةٌ صامتة
        $tab = (string) $r->query('tab', 'overview');
        abort_unless(isset($tabs[$tab]), 404);

        // عدّاداتُ الوحدات عبر القارئ القائم (hub_scope): العدّادُ **المُرشَّح**
        // لا الخام — عدٌّ خامٌ يفضح وجودَ ما يحجبه العزل. ترتيبُ المفاتيح ترتيبُ
        // hub_nav الثابت، فلا اعتمادَ على ترتيب صفوف.
        $counts = [];
        foreach ($visible as $mk) {
            $def = hub_mod($mk);
            if (! $def || empty($def['model'])) continue;
            $cls = '\\App\\Models\\' . $def['model'];
            if (! class_exists($cls)) continue;
            $counts[$mk] = (int) hub_scope($cls::query(), $mk)->count();
        }

        return view('workspaces.tech', [
            'tabs'       => array_values($tabs),
            'tab'        => $tab,
            'tabModules' => $tabs[$tab]['modules'],
            'allModules' => $visible,
            'counts'     => $counts,
            // المحرّكُ المخبّأ — تجميعٌ لا إعادةُ حساب (?fresh=1 يعيد الفحصَ كالمركز)
            'd'          => DigitalAssets::all((bool) $r->query('fresh')),
        ]);
    }
}
