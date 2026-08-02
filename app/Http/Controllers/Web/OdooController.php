<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** الربط الذكي بأودو: ربط سجل (مشروع/شركة/عميل) بشريك أودو — عرض فقط */
class OdooController extends Controller
{
    protected array $linkable = ['projects', 'companies', 'clients'];

    public function link(Request $r, string $module, string $id)
    {
        $m = $this->target($module, $id);
        $d = $r->validate([
            'pid'   => ['required', 'integer', 'min:1'],
            'pname' => ['nullable', 'string', 'max:200'],
        ]);

        $m->meta = ((array) $m->meta) + [];
        $meta = (array) $m->meta;
        $meta['odoo_partner_id'] = (int) $d['pid'];
        $meta['odoo_partner_name'] = (string) ($d['pname'] ?? '');
        $m->meta = $meta;
        $m->save();
        \App\Support\Odoo::forRow($m)->forgetStats((int) $d['pid']);

        return back()->with('ok', '🔗 رُبط السجل بأودو — الأرقام ستظهر في البطاقة')->withFragment('odoo');
    }

    public function unlink(string $module, string $id)
    {
        $m = $this->target($module, $id);
        $meta = (array) $m->meta;
        unset($meta['odoo_partner_id'], $meta['odoo_partner_name']);
        $m->meta = $meta;
        $m->save();

        return back()->with('ok', 'فُك الربط بأودو')->withFragment('odoo');
    }

    public function refresh(string $module, string $id)
    {
        $m = $this->target($module, $id);
        if ($pid = (int) (((array) $m->meta)['odoo_partner_id'] ?? 0)) {
            \App\Support\Odoo::forRow($m)->forgetStats($pid);
        }

        return back()->with('ok', '🔄 حُدّثت أرقام أودو')->withFragment('odoo');
    }

    protected function target(string $module, string $id)
    {
        abort_unless(in_array($module, $this->linkable, true), 404);
        abort_unless(hub_can(auth()->user(), $module, 'e'), 403, 'ربط أودو يتطلب صلاحية تعديل السجل');
        $def = hub_mod($module);
        $class = '\\App\\Models\\' . $def['model'];

        return hub_scope($class::query(), $module)->findOrFail($id);
    }
}
