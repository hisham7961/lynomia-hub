<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Integrations;

/**
 * مركز التكاملات: شاشةٌ واحدة تجمع كل ما يربط الهَب بالخارج — كانت المعرفة
 * متناثرة بين شاشة الويبهوك وإعدادات أودو وصفحة المفاتيح، فلا يعرف المالك
 * ما المتاح ولا ما المربوط ولا أين تنزل بيانات n8n.
 */
class IntegrationController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز التكاملات للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        return view('integrations.index', array_merge([
            'installed' => Integrations::installed(),
            'catalog'   => Integrations::catalog(),
            'odooMods'  => Integrations::odooModules(),
        ], ['apiUsage' => \App\Support\Api::usage(7)]));
    }

    /** دليل الربط: الأحداث الصادرة، شكل الحمولة، التوقيع، وأين تنزل البيانات الواردة */
    public function guide()
    {
        $this->gate();

        // خريطة الاستقبال: أي وحدةٍ يكتب إليها n8n وبأي مفاتيح حقول
        $inbound = [];
        foreach (hub_modules() as $mk => $md) {
            $req = [];
            foreach ($md['fields'] as $f) {
                if (! empty($f['required'])) $req[] = $f['key'];
            }
            $inbound[$mk] = ['label' => $md['label'], 'required' => $req,
                             'fields' => count($md['fields'])];
        }

        return view('integrations.guide', [
            'events'  => Integrations::eventCatalog(),
            'inbound' => $inbound,
            'odooMods' => Integrations::odooModules(),
        ]);
    }
}
