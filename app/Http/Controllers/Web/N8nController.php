<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

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

        \App\Models\Setting::updateOrCreate(['key' => 'n8n.url'], ['value' => (string) ($d['url'] ?? '')]);
        // المفتاح: فارغٌ يُبقي المخزون؛ والمكتوب يُشفَّر (enc:)
        if (filled($d['key'] ?? null)) {
            \App\Models\Setting::updateOrCreate(['key' => 'n8n.key'],
                ['value' => 'enc:' . Crypt::encryptString($d['key'])]);
        }
        Cache::forget('settings:all');
        hub_audit('تعديل إعدادات النظام', 'settings', null, 'n8n.* — من مركز التكامل');

        return back()->with('ok', 'حُفظ ربط n8n — افتح لوحته من الزر أعلاه');
    }
}
