<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OdooConnection;
use App\Support\Odoo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;

/**
 * **خوادم أودو** — إدارة الاتصالات المتعددة من مركز التكاملات.
 *
 * الاتصالُ الافتراضي يبقى في الإعدادات (odoo.*)، وهنا تُدار الخوادمُ
 * الإضافية: بعضُ المشاريع لها أودو خاص. للمالك وحده — كسائر المركز.
 *
 * **روابطُ الاتصالات تمرّ بحارس الطلبات الصادرة** (`hub_outbound_ok`):
 * مفتاحُ `odoo.url` القديم موثَّقٌ عجزُه عن ذلك في كتالوج الإعدادات، وهذه
 * الشاشةُ الجديدة لا ترث العجز. `monitor.allow_private` يفتحها عمداً
 * لتنصيبٍ داخلي مغلق.
 */
class OdooConnectionController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'إدارة اتصالات أودو للمالكين فقط');
    }

    public function index()
    {
        $this->gate();

        $rows = OdooConnection::orderBy('name')->orderBy('id')->get();

        // خريطة المزيج: كم مشروعاً يستعمل كلَّ اتصال — بمسار JSON الذي يترجمه
        // Laravel لكل محرّك (‏`LIKE` على عمود JSON أصيل يُخطئ على MySQL 8)
        $uses = [];
        foreach ($rows as $c) {
            $uses[$c->id] = $this->projectsUsing($c->id)->count();
        }

        // المشاريع التي اختارت خادماً: اسمُها وخادمُها وعددُ قنواتها
        $names = $rows->pluck('name', 'id');
        $linked = DB::table('projects')->whereNull('deleted_at')
            ->whereNotNull('meta->odoo->conn')->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'meta'])
            ->map(function ($p) use ($names) {
                $o = (array) (json_decode((string) $p->meta, true)['odoo'] ?? []);
                $p->connName = $names[(string) ($o['conn'] ?? '')] ?? 'اتصال محذوف';
                $p->chCount = count((array) ($o['channels'] ?? []));

                return $p;
            });

        return view('integrations.odoo', [
            'rows' => $rows, 'uses' => $uses, 'linked' => $linked,
            'defaultReady' => Odoo::configured(),
            'odooMods' => \App\Support\Integrations::odooModules(),
        ]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->gate();
        $d = $this->validated($r, keyRequired: true);

        $c = OdooConnection::create([
            'name' => $d['name'], 'url' => $d['url'], 'db' => $d['db'],
            'username' => $d['username'], 'key_cipher' => $d['key'],
            'notes' => $d['notes'] ?? null, 'active' => true,
        ]);
        hub_audit('إضافة اتصال أودو', 'settings', null, $c->name . ' — ' . $c->url);

        return back()->with('ok', 'أُضيف اتصال «' . $c->name . '» — اختبره الآن قبل ربط أي مشروع به');
    }

    public function update(Request $r, string $id): RedirectResponse
    {
        $this->gate();
        $c = OdooConnection::findOrFail($id);
        $d = $this->validated($r, keyRequired: false);

        $credChanged = $c->url !== $d['url'] || $c->db !== $d['db']
            || $c->username !== $d['username'] || filled($d['key'] ?? null);

        $c->fill(['name' => $d['name'], 'url' => $d['url'], 'db' => $d['db'],
            'username' => $d['username'], 'notes' => $d['notes'] ?? null]);
        // مفتاحٌ فارغ عند التعديل = الإبقاء على المخزون — نمط حقول pass في الإعدادات
        if (filled($d['key'] ?? null)) $c->key_cipher = $d['key'];
        if ($credChanged) {
            $c->last_ok_at = null;
            $c->last_version = null;
            // لا نسفَ يدوياً: بصمةُ الاعتماد في مفاتيح الكاش تُدوّرها كلَّها تلقائياً
        }
        $c->save();
        hub_audit('تعديل اتصال أودو', 'settings', null, $c->name);

        return back()->with('ok', 'حُفظ اتصال «' . $c->name . '»'
            . ($credChanged ? ' — تغيّرت بياناتُ الدخول فأعد اختباره' : ''));
    }

    public function toggle(string $id): RedirectResponse
    {
        $this->gate();
        $c = OdooConnection::findOrFail($id);
        $c->active = ! $c->active;
        $c->save();
        hub_audit(($c->active ? 'تفعيل' : 'تعطيل') . ' اتصال أودو', 'settings', null, $c->name);

        return back()->with('ok', ($c->active ? 'فُعّل' : 'عُطّل') . ' اتصال «' . $c->name . '»'
            . ($c->active ? '' : ' — المشاريع المرتبطة به سترى سببَ التعطّل لا أرقاماً'));
    }

    /**
     * (WP-9.4 · §7.10) اختبارُ اتصالٍ — **على الفاحص الواحد** `ConnectionProbe`.
     *
     * كان هنا فاحصٌ وفي شاشة الإعدادات فاحصٌ آخر لاتصالٍ من الطبيعة نفسِها،
     * وكلاهما يعيد `$e->getMessage()` خاماً إلى الشاشة. الرسالةُ الآن مطموسةٌ
     * بالمُطهِّر ومعها زمنُ الاستجابة، والشكلُ واحدٌ للشاشتين.
     */
    public function test(string $id): RedirectResponse
    {
        $this->gate();
        $c = OdooConnection::findOrFail($id);

        $res = \App\Support\ConnectionProbe::odoo($c);
        if ($res['up'] !== true) {
            // الفشل لا يمسّ آخر نجاحٍ مسجَّل — التاريخ يبقى صادقاً
            return back()->withErrors(['conn' => 'اختبار «' . $c->name . '» — '
                . \App\Support\ConnectionProbe::line($res)]);
        }

        $c->forceFill(['last_ok_at' => now(),
            'last_version' => hub_str($res['detail']['version'] ?? '')])->save();

        return back()->with('ok', '«' . $c->name . '» — ' . \App\Support\ConnectionProbe::line($res));
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->gate();
        $c = OdooConnection::findOrFail($id);
        $n = $this->projectsUsing($c->id)->count();
        $c->delete();
        hub_audit('حذف اتصال أودو', 'settings', null, $c->name);

        return back()->with('ok', 'حُذف اتصال «' . $c->name . '»'
            . ($n ? " — {$n} مشروع كان يستعمله وسيرى «اتصال محذوف» حتى تختار له غيرَه" : ''));
    }

    /**
     * **إدارةُ الاتصال الافتراضي من هنا أيضاً** — كان موزَّعاً: الخوادمُ
     * الإضافية في هذه الشاشة والافتراضيُّ في الإعدادات، فيتنقّل المرءُ بينهما
     * ويظنّهما شيئين. المفاتيحُ هي نفسُها (`odoo.*`) تُكتب من البابين،
     * وشاشةُ الإعدادات تبقى كما هي — إضافةٌ لا كسر.
     */
    public function defaults(Request $r): RedirectResponse
    {
        $this->gate();
        $d = $r->validate([
            'url'      => ['required', 'url', 'max:300'],
            'db'       => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:200'],
            'key'      => ['nullable', 'string', 'max:500'],
        ], [], ['url' => 'رابط الخادم', 'db' => 'اسم القاعدة', 'username' => 'مستخدم القراءة', 'key' => 'مفتاح API']);

        $guard = hub_outbound_ok($d['url']);
        if (! $guard['ok']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'رُفض الرابط: ' . $guard['why']
                    . ' — إن كان أودو داخل شبكتك المغلقة فعّل «السماح بالعناوين الخاصة» من الإعدادات',
            ]);
        }

        // (WP-9.2) على الكاتب الواحد — والإبطالُ عنده (كاشُ أودو نفسُه يتدوّر
        // ببصمة الاعتماد في مفتاحه، فلا نسفَ يدويّاً له)
        \App\Support\Settings::batch('odoo', function () use ($d) {
            \App\Support\Settings::put('odoo.url', $d['url'], 'odoo');
            \App\Support\Settings::put('odoo.db', $d['db'], 'odoo');
            \App\Support\Settings::put('odoo.user', $d['username'], 'odoo');
            // مفتاحٌ فارغ يُبقي المخزون — والمكتوب يُشفَّر عند الكاتب كما من شاشة الإعدادات
            if (filled($d['key'] ?? null)) \App\Support\Settings::put('odoo.key', $d['key'], 'odoo');
        }, ['name' => 'odoo.* — من مركز التكاملات']);

        return back()->with('ok', 'حُفظ الاتصال الافتراضي — اختبره الآن');
    }

    /** المشاريع التي تختار هذا الاتصال في meta['odoo']['conn'] */
    protected function projectsUsing(string $connId)
    {
        // **مسارُ JSON لا `LIKE` على النصّ**: MySQL 8 يخزّن `json` أصيلاً ويُطبّعه
        // (‏`"conn": "…"` بمسافةٍ بعد النقطتين) فلا يُطابق النمطُ الحرفيّ أبداً —
        // فالعدُّ صفرٌ دائماً وحذفُ اتصالٍ مستعمَل يمرّ بلا تحذير. ومسارُ JSON
        // يُترجمه Laravel لكل محرّك.
        return DB::table('projects')->whereNull('deleted_at')
            ->where('meta->odoo->conn', $connId);
    }

    /** التحقق المشترك — ومعه حارس SSRF على الرابط */
    protected function validated(Request $r, bool $keyRequired): array
    {
        $d = $r->validate([
            'name'     => ['required', 'string', 'max:120'],
            'url'      => ['required', 'url', 'max:300'],
            'db'       => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'max:200'],
            'key'      => [$keyRequired ? 'required' : 'nullable', 'string', 'max:500'],
            'notes'    => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'اسم الاتصال', 'url' => 'رابط الخادم', 'db' => 'اسم القاعدة',
                'username' => 'مستخدم القراءة', 'key' => 'مفتاح API']);

        $guard = hub_outbound_ok($d['url']);
        if (! $guard['ok']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'url' => 'رُفض الرابط: ' . $guard['why']
                    . ' — إن كان أودو داخل شبكتك المغلقة فعّل «السماح بالعناوين الخاصة» من الإعدادات',
            ]);
        }

        return $d;
    }
}
