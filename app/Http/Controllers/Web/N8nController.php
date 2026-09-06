<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **لوحةُ n8n في مركز التكامل** — n8n خدمةٌ منفصلة (Node) تعمل على خادمك (VPS)،
 * تُنصَّب من `deploy/n8n`. هنا يُوصَل مثيلُك بالنظام: رابطُه وحالتُه، والجسرُ في
 * الاتجاهين (الويبهوك الصادر ← عُقَد n8n · عُقَد n8n → الويبهوك الوارد للنظام).
 *
 * لا يُدمَج n8n في كود PHP — لذا لا نُشغّله من هنا، بل نصله ونوثّق ربطه.
 */
class N8nController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'لوحة n8n للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        return view('integrations.n8n', [
            'url'      => (string) setting('n8n.url', ''),
            'hasKey'   => (string) setting('n8n.key', '') !== '',
        ]);
    }

    /**
     * (WP-9.4 · §7.10) **فاحصُ اتصالٍ لـn8n** — لم يكن له فاحصٌ قطّ: يُحفظ الرابطُ
     * ثم يُفتح في لسانٍ جديد ليُعرف إن كان المثيلُ حيّاً. والفحصُ يمرّ بحارس
     * الطلبات الصادرة (`hub_outbound_ok`) كسائر الوجهات الخارجية، ورسالتُه
     * مطموسةٌ بالمُطهِّر، ومعه زمنُ الاستجابة — بالشكل نفسِه الذي يراه فاحصُ أودو.
     */
    public function test()
    {
        $this->gate();
        $res = \App\Support\ConnectionProbe::n8n();

        return $res['up'] === true
            ? back()->with('ok', \App\Support\ConnectionProbe::line($res))
            : back()->withErrors(['url' => \App\Support\ConnectionProbe::line($res)]);
    }

    public function save(Request $r)
    {
        $this->gate();
        $d = $r->validate([
            'url' => ['nullable', 'url', 'max:300'],
            'key' => ['nullable', 'string', 'max:500'],
        ], [], ['url' => 'رابط مثيل n8n', 'key' => 'مفتاح n8n API']);

        // الرابطُ يمرّ بحارس SSRF كسائر الوجهات الخارجية في النظام
        if (filled($d['url'] ?? null)) {
            $guard = hub_outbound_ok($d['url']);
            if (! $guard['ok']) {
                return back()->withErrors(['url' => 'رُفض الرابط: ' . $guard['why']
                    . ' — إن كان n8n داخل شبكتك المغلقة فعّل «السماح بالعناوين الخاصة»'])->withInput();
            }
        }

        // (WP-9.2) على الكاتب الواحد: التشفيرُ والإبطالُ والتدقيقُ وصفُّ التاريخ عنده
        \App\Support\Settings::batch('n8n', function () use ($d) {
            \App\Support\Settings::put('n8n.url', (string) ($d['url'] ?? ''), 'n8n');
            // المفتاح: فارغٌ يُبقي المخزون؛ والمكتوب يُشفَّر (enc:) عند الكاتب
            if (filled($d['key'] ?? null)) \App\Support\Settings::put('n8n.key', $d['key'], 'n8n');
        }, ['name' => 'n8n.* — من مركز التكامل']);

        return back()->with('ok', 'حُفظ ربط n8n — افتح لوحته من الزر أعلاه');
    }
}
