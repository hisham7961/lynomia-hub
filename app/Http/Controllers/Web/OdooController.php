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

    /* ───────────── تخصيص أودو لكل مشروع: الاتصال والقنوات ───────────── */

    protected const CHANNEL_CAP = 12;   // سقفُ العرض: كل قناةٍ نداءان عند أول جلب

    /** شاشة التخصيص: اختيار الخادم، واكتشاف المميِّزات حيّاً، وإدارة القنوات */
    public function project(Request $r, string $id)
    {
        $row = $this->target('projects', $id);
        $cli = \App\Support\Odoo::forRow($row);

        $mode = (string) $r->query('mode', 'team');
        if (! in_array($mode, \App\Support\Odoo::CHANNEL_MODES, true)) $mode = 'team';

        // الخيارات تُجلب مزامنةً في try/catch — الشاشة لا تسقط بخادمٍ غائب
        $options = []; $err = null;
        if ($cli->ready()) {
            try { $options = $cli->channelOptions($mode); }
            catch (\Throwable $e) { $err = $e->getMessage(); }
        } else {
            $err = $cli->error() ?: 'لا اتصالَ مهيأً — أكمل الافتراضي في الإعدادات أو أضف خادماً من مركز التكاملات';
        }

        return view('odoo.project', [
            'row' => $row, 'cli' => $cli, 'mode' => $mode,
            'options' => $options, 'err' => $err,
            'conns' => \App\Support\Odoo::connections(),
            'channels' => (array) (((array) $row->meta)['odoo']['channels'] ?? []),
        ]);
    }

    /** تعيينُ خادم المشروع — قرارُ مالك: توجيهُ مشروعٍ لخادمٍ آخر يغيّر أرقامه كلها */
    public function setConn(Request $r, string $id)
    {
        abort_unless(hub_is_owner(), 403, 'اختيار خادم أودو للمشروع قرارُ مالك');
        $row = $this->target('projects', $id);

        $valid = array_column(\App\Support\Odoo::connections(), 'id');
        $conn = hub_str($r->input('conn'));
        abort_unless(in_array($conn, $valid, true), 422, 'اتصال غير معروف أو معطّل');

        $meta = (array) $row->meta;
        if ($conn === 'default') unset($meta['odoo']['conn']);
        else $meta['odoo']['conn'] = $conn;
        $row->meta = $meta;
        $row->save();

        // خادمٌ جديد = أرقامٌ أخرى: تُنسف كاشات قنوات المشروع كلها
        $this->forgetProjectChannels($row);

        return back()->with('ok', 'حُدّد خادم أودو لهذا المشروع');
    }

    /** إضافة قناة بيع — الشكل يُتحقق منه والمرجع من خيارات أودو الحية */
    public function addChannel(Request $r, string $id)
    {
        $row = $this->target('projects', $id);
        $d = $r->validate([
            'label'    => ['required', 'string', 'max:60'],
            'icon'     => ['nullable', 'string', 'max:8'],
            'mode'     => ['required', 'in:team,journal,tag,website'],
            'ref_id'   => ['required', 'integer', 'min:1'],
            'ref_name' => ['nullable', 'string', 'max:120'],
        ], [], ['label' => 'اسم القناة', 'mode' => 'المميِّز', 'ref_id' => 'المرجع']);

        $meta = (array) $row->meta;
        $channels = (array) ($meta['odoo']['channels'] ?? []);
        if (count($channels) >= self::CHANNEL_CAP) {
            return back()->with('err', 'بلغتَ سقف ' . self::CHANNEL_CAP . ' قناة — احذف قناةً قبل إضافة أخرى');
        }

        $channels[] = [
            'key'      => 'c_' . strtolower(\Illuminate\Support\Str::random(6)),
            'label'    => $d['label'],
            'icon'     => (string) ($d['icon'] ?? '🛒'),
            'mode'     => $d['mode'],
            'ref_id'   => (int) $d['ref_id'],
            'ref_name' => (string) ($d['ref_name'] ?? ''),
        ];
        $meta['odoo']['channels'] = $channels;
        $row->meta = $meta;
        $row->save();

        return back()->with('ok', 'أُضيفت قناة «' . $d['label'] . '» — أرقامها ستظهر في بطاقة المشروع');
    }

    /** حذف قناة */
    public function removeChannel(Request $r, string $id)
    {
        $row = $this->target('projects', $id);
        $key = hub_str($r->input('key'));

        $meta = (array) $row->meta;
        $channels = (array) ($meta['odoo']['channels'] ?? []);
        $meta['odoo']['channels'] = array_values(array_filter($channels,
            fn ($c) => ($c['key'] ?? '') !== $key));
        $row->meta = $meta;
        $row->save();
        \App\Support\Odoo::forRow($row)->forgetChannel($row->id, $key);

        return back()->with('ok', 'حُذفت القناة');
    }

    /** تحديث أرقام القنوات الآن — نسفُ كاشاتها */
    public function refreshChannels(string $id)
    {
        $row = $this->target('projects', $id);
        $this->forgetProjectChannels($row);

        return back()->with('ok', '🔄 حُدّثت أرقام القنوات')->withFragment('chans');
    }

    protected function forgetProjectChannels($row): void
    {
        $cli = \App\Support\Odoo::forRow($row);
        foreach ((array) (((array) $row->meta)['odoo']['channels'] ?? []) as $c) {
            $cli->forgetChannel($row->id, (string) ($c['key'] ?? ''));
        }
    }
}
