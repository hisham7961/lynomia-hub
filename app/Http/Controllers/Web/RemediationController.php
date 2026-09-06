<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\KpiDef;
use App\Models\Objective;
use App\Models\Ticket;
use App\Support\DataQuality;
use App\Support\KpiCentre;
use App\Support\OkrCentre;
use App\Support\Remediation;
use Illuminate\Http\Request;

/**
 * زرُّ «أنشئ مهمّةَ معالجة» (WP-8.5 · spec §6.13) — مدخلٌ واحدٌ لأربعة مصادر.
 *
 * والقاعدةُ التي تفصله عن زرٍّ يفتح مهامَّ فارغة: **لا مهمّةَ لما ليس نتيجة.**
 * كلُّ صنفٍ يُتحقَّق من مصدره الحيّ قبل الكتابة — المؤشّرُ خارجَ هدفه فعلاً،
 * والهدفُ عليه علَمٌ فعلاً، والتذكرةُ خرقت SLA فعلاً، وقاعدةُ الجودة لها نتائجُ
 * فعلاً. وإلا ٤٢٢: «لا شيء يُعالَج هنا» أصدقُ من مهمّةٍ تولد مغلقة.
 */
class RemediationController extends Controller
{
    protected function gate(): void
    {
        abort_unless(hub_monitor(), 403,
            'أفعالُ المعالجة للمالكين ومن يحمل صلاحية المتابعة');
    }

    public function store(Request $r)
    {
        $this->gate();

        $kind = hub_str($r->input('kind'));
        abort_unless(isset(Remediation::KINDS[$kind]), 422, 'صنفُ معالجةٍ غير معروف');

        $input = $r->validate([
            'assignee_id' => ['nullable', 'uuid', \Illuminate\Validation\Rule::exists('users', 'id')],
            'priority'    => ['nullable', \Illuminate\Validation\Rule::in(['عاجلة', 'عالية', 'متوسطة', 'منخفضة'])],
            'due'         => ['nullable', 'date'],
        ]);

        $origin = match ($kind) {
            'kpi'     => $this->fromKpi($r),
            'okr'     => $this->fromObjective($r),
            'sla'     => $this->fromTicket($r),
            'quality' => $this->fromQuality($r),
        };

        ['task' => $task, 'created' => $created] = Remediation::open($origin, $input);

        return redirect()->route('m.show', ['tasks', $task->id])->with('ok', $created
            ? '🛠 أُنشئت مهمّةُ المعالجة — أسنِدها وحدّد موعدها'
            : 'فُتحت من قبل لهذه النتيجة — هذه مهمّتها');
    }

    /* ────────── المصادر الأربعة ────────── */

    /** مؤشّرٌ خارج الهدف — أو فلترٌ ميّت، وكلاهما نتيجةٌ تُعالَج (§6.9) */
    protected function fromKpi(Request $r): array
    {
        $k = KpiDef::findOrFail(hub_str($r->input('ref')));
        $row = collect(KpiCentre::rows(auth()->user()))->firstWhere('id', $k->id);
        abort_unless($row && in_array($row['health'], ['off', 'dead'], true), 422,
            'هذا المؤشّر ليس خارج هدفه — لا شيء يُعالَج');

        $num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $body = "مؤشّرٌ خارج الهدف من مركز المؤشّرات.\n\n"
            . "المؤشّر: {$k->name}\n"
            . 'القيمة: ' . $num($row['value']) . ' · الهدف: ' . $num($row['target'])
            . ' (' . ($row['good'] === 'up' ? 'الأعلى أفضل' : 'الأقلّ أفضل') . ")\n"
            . 'الانحراف: ' . $num($row['variance']) . "\n"
            . 'المعادلة: ' . $row['explain'] . "\n"
            . ($row['dead']
                ? "\n⚠️ فلترٌ لا يطابق السجل: " . collect($row['dead'])
                    ->map(fn ($d) => "«{$d['status']}» في {$d['label']} — {$d['why']}")->implode(' · ')
                : '');

        return ['kind' => 'kpi', 'module' => null, 'record' => $k->id,
                'key' => 'kpi:' . $k->id, 'title' => $k->name, 'name' => $k->name,
                'body' => $body, 'company_id' => null, 'project_id' => null];
    }

    /** هدفٌ عليه علَم: فات موعدَه أو متعثّرٌ أو راكد (§6.10) */
    protected function fromObjective(Request $r): array
    {
        $id = hub_str($r->input('ref'));
        // التنطيقُ قبل القراءة: هدفٌ خارج نطاق القارئ لا يُفتح له فعلٌ ولا يُفشى وجودُه
        $o = hub_scope(Objective::query(), 'okrs')->whereNull('deleted_at')
            ->where('id', $id)->firstOrFail();

        $row = collect(OkrCentre::board(auth()->user())['rows'] ?? [])
            ->first(fn ($x) => $x['o']->id === $o->id);
        abort_unless($row && ($row['overdue'] || $row['blocked'] || $row['stalled']), 422,
            'هذا الهدف بلا علَمٍ يستدعي المعالجة');

        $flags = collect(['overdue' => 'فات موعدَه', 'blocked' => 'متعثّر',
                          'stalled' => 'راكد منذ ' . OkrCentre::STALL_DAYS . ' أيامٍ فأكثر'])
            ->filter(fn ($lbl, $k) => $row[$k])->values()->implode(' · ');

        $body = "هدفٌ يحتاج معالجةً من مركز الأهداف.\n\n"
            . "الهدف: {$o->title}\n"
            . 'المستوى: ' . ($o->level ?: '—') . ($o->period ? ' · الدورة: ' . $o->period : '') . "\n"
            . 'الإنجاز المحسوب: ' . ($row['pct'] === null ? 'غير مقيس' : $row['pct'] . '٪') . "\n"
            . ($o->due ? 'الاستحقاق: ' . substr((string) $o->due, 0, 10) . "\n" : '')
            . "الأعلام: {$flags}";

        return ['kind' => 'okr', 'module' => 'okrs', 'record' => $o->id,
                'key' => 'okr:' . $o->id, 'title' => $o->title, 'name' => $o->title,
                'body' => $body, 'company_id' => $o->company_id, 'project_id' => $o->project_id];
    }

    /** تذكرةٌ خرقت SLA — بالمحرّك القائم `hub_sla` لا بحسابٍ ثانٍ */
    protected function fromTicket(Request $r): array
    {
        $id = hub_str($r->input('ref'));
        $t = hub_scope(Ticket::query(), 'tickets')->whereNull('deleted_at')
            ->where('id', $id)->firstOrFail();

        $sla = hub_sla($t);
        abort_unless($sla['respLate'] || $sla['resLate'], 422, 'هذه التذكرة لم تخرق SLA');

        $breach = collect(['respLate' => 'تأخّرُ الاستجابة', 'resLate' => 'تأخّرُ الحل'])
            ->filter(fn ($lbl, $k) => $sla[$k])->values()->implode(' · ');

        $body = "خرقُ SLA من مركز الدعم.\n\n"
            . 'التذكرة: ' . ($t->subject ?? $t->id) . "\n"
            . "السياسة: {$sla['policy']}\n"
            . "الخرق: {$breach}\n"
            . 'موعدُ الاستجابة: ' . $sla['respDue']->toDateTimeString() . "\n"
            . 'موعدُ الحل: ' . $sla['resDue']->toDateTimeString();

        return ['kind' => 'sla', 'module' => 'tickets', 'record' => $t->id,
                'key' => 'sla:' . $t->id, 'title' => (string) ($t->subject ?? $t->id),
                'name' => (string) ($t->subject ?? $t->id), 'body' => $body,
                'company_id' => $t->company_id ?? null, 'project_id' => $t->project_id ?? null];
    }

    /**
     * نتيجةُ جودةٍ — **للمالك وحدَه**: مسحُ الجودة غيرُ منطَّق ويُظهر أسماءَ
     * سجلاتٍ من كل الوحدات تحت مفتاحٍ كاشٍ عامّ (نفسُ حارس تبويب «البيانات»).
     */
    protected function fromQuality(Request $r): array
    {
        abort_unless(hub_is_owner(), 403,
            'نتائجُ الجودة غيرُ منطَّقة — معالجتُها للمالك وحدَه');

        $module = hub_str($r->input('module'));
        $rule = hub_str($r->input('rule'));
        $def = hub_mod($module);
        abort_unless($def, 422, 'وحدةٌ غير معروفة');

        $finding = collect(DataQuality::scan()['checks'] ?? [])
            ->first(fn ($c) => $c['module'] === $module && $c['key'] === $rule);
        abort_unless($finding, 422, 'لا نتيجةَ جودةٍ بهذا المفتاح — لا شيء يُعالَج');

        $body = "نتيجةُ جودةِ بيانات من مركز الجودة.\n\n"
            . 'الوحدة: ' . $def['label'] . "\n"
            . 'النقص: ' . $finding['label'] . "\n"
            . 'العدد: ' . $finding['count'] . ' من ' . $finding['total'] . " سجلاً\n"
            . 'الشدّة: ' . \App\Support\Severity::label((string) ($finding['sev'] ?? '')) . "\n\n"
            . 'لماذا يهمّ: ' . ($finding['why'] ?? '') . "\n"
            . 'الإصلاح: ' . ($finding['fix'] ?? '');

        return ['kind' => 'quality', 'module' => $module, 'record' => null,
                'key' => 'quality:' . $module . ':' . $rule,
                'title' => $def['label'] . ' — ' . $finding['label'],
                'name' => $def['label'] . ' — ' . $finding['label'],
                'body' => $body, 'company_id' => null, 'project_id' => null];
    }
}
