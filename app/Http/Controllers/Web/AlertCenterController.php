<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\Severity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **مركزُ التنبيهات** (WP-6.3 · §9.3) — حالةُ التنبيه لا سيلُ إشعاراته.
 *
 * الجرسُ يقول «حدث شيء» ثم يُقلَّم؛ وهنا **ذاكرةُ الشرط** (`alert_instances`):
 * متى ظهر أولَ مرة، كم تكرّر، من أقرّ به، ومتى تعافى — صفٌّ واحد لكل شرطٍ حيّ.
 *
 * التفويض (ق١): القراءةُ للمالك أو حامل monitor؛ والفعلُ (إقرار/فتحُ حادثة)
 * للمالك وحدَه + قيدُ تدقيق. **الإقرارُ سكّةٌ واحدة** (ق٣ + critic #5): يعيش في
 * `alert_instances.acknowledged_by/at` وحدَه — لا صفَّ ثانياً في `signal_states`.
 *
 * الاسمُ `alerts.center` — فالاسم `alerts` مملوكٌ لرادار «ينتهي قريباً» القائم.
 */
class AlertCenterController extends Controller
{
    protected function readGate(): void
    {
        abort_unless(hub_is_owner() || hub_monitor(), 403, 'مركزُ التنبيهات للمالك أو حامل علم المراقبة');
    }

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'الفعلُ على التنبيه للمالك وحدَه');
    }

    public function index(Request $r)
    {
        $this->readGate();
        abort_unless(Schema::hasTable('alert_instances'), 404);

        $st = hub_str($r->query('st'));
        $sev = in_array($r->query('sev'), Severity::LEVELS, true) ? $r->query('sev') : '';

        $q = DB::table('alert_instances')
            ->when($st !== '', fn ($w) => $w->where('status', $st))
            ->when($sev !== '', fn ($w) => $w->where('severity', $sev));

        // الأحدثُ رصداً أولاً — والفهرسُ (status, severity, last_at) يخدم التصفية
        $rows = $q->orderByDesc('last_at')->orderByDesc('id')->paginate(25)->withQueryString();

        // غيرُ المالك (monitor) يقرأ مطموسَ البريد — العناوينُ تُعرض <bdi> كما في مركز الأمن
        if (! hub_is_owner()) {
            $rows->getCollection()->transform(function ($i) {
                $i->title = \App\Support\SecurityFindings::maskPII((string) $i->title);
                $i->subject = $i->subject !== null ? \App\Support\SecurityFindings::maskPII((string) $i->subject) : null;

                return $i;
            });
        }

        $kpi = [
            'triggered'    => (int) DB::table('alert_instances')->where('status', 'triggered')->count(),
            'acknowledged' => (int) DB::table('alert_instances')->where('status', 'acknowledged')->count(),
            'critical'     => (int) DB::table('alert_instances')->where('status', '!=', 'resolved')->where('severity', 'critical')->count(),
            'resolved7'    => (int) DB::table('alert_instances')->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(7))->count(),
        ];

        // أسماءُ القواعد للربط — دفعةً واحدة لا لكل صف
        $ruleNames = DB::table('alert_rules')
            ->whereIn('id', $rows->getCollection()->pluck('rule_id')->filter()->unique()->values()->all() ?: ['-'])
            ->pluck('name', 'id');

        return view('alerts.center', [
            'rows' => $rows, 'kpi' => $kpi, 'st' => $st, 'sev' => $sev,
            'ruleNames' => $ruleNames, 'isOwner' => hub_is_owner(),
        ]);
    }

    /** إقرارُ تنبيه: triggered → acknowledged — يوقف تكرارَ الإشعار والعدّادُ يستمرّ */
    public function ack(string $id)
    {
        $this->gate();
        abort_unless(Schema::hasTable('alert_instances'), 404);

        $i = DB::table('alert_instances')->where('id', $id)->first();
        abort_unless($i, 404);
        if ($i->status !== 'triggered') {
            return back()->with('ok', $i->status === 'resolved' ? 'التنبيهُ تعافى أصلاً — لا إقرارَ يلزم' : 'التنبيهُ مُقَرٌّ به أصلاً');
        }

        DB::table('alert_instances')->where('id', $id)->update([
            'status' => 'acknowledged', 'acknowledged_by' => auth()->id(),
            'acknowledged_at' => now(), 'updated_at' => now(),
        ]);
        hub_audit('إقرار تنبيه', null, null, mb_substr((string) $i->title, 0, 290));

        return back()->with('ok', '👁️ أُقرّ بالتنبيه — الجرسُ يصمت والعدّادُ يستمرّ حتى يزول الشرط');
    }

    /** فتحُ حادثةٍ من تنبيه — عبر hub_open_incident (الناقلُ الواحد) ببصمة مفتاح التنبيه */
    public function incident(string $id)
    {
        $this->gate();
        abort_unless(Schema::hasTable('alert_instances'), 404);

        $i = DB::table('alert_instances')->where('id', $id)->first();
        abort_unless($i, 404);
        if ($i->incident_id && DB::table('incidents')->where('id', $i->incident_id)->whereNull('deleted_at')->exists()) {
            return back()->with('ok', 'للتنبيه حادثةٌ مرتبطةٌ أصلاً');
        }

        $kind = in_array((string) $i->domain, ['system', 'error', 'quality', 'execution'], true) ? 'ops' : 'security';
        $sevAr = ['critical' => 'حرج', 'high' => 'عالي', 'medium' => 'متوسط', 'low' => 'منخفض', 'info' => 'منخفض'][Severity::normalize($i->severity)];
        $inc = hub_open_incident((string) $i->title, $sevAr, $kind, 'alert:' . $i->dedup_key,
            ['dedup_key' => $i->dedup_key, 'subject' => $i->subject, 'count' => (int) $i->count, 'by' => (string) auth()->id()], 24);
        if (! $inc) {
            return back()->with('err', 'تعذّر فتحُ الحادثة — راجع مركز الأخطاء');
        }

        DB::table('alert_instances')->where('id', $id)->update(['incident_id' => $inc->id, 'updated_at' => now()]);
        hub_audit('فتح حادثة من تنبيه', 'incidents', (string) $inc->id, mb_substr((string) $i->title, 0, 290));

        return redirect()->route('m.show', ['module' => 'incidents', 'id' => $inc->id])
            ->with('ok', '🚨 فُتحت الحادثةُ وربُطت بالتنبيه');
    }
}
