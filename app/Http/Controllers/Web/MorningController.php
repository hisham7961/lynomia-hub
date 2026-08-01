<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التشغيل اليومي — «ماذا يحتاجني اليوم؟» في شاشة واحدة.
 * كل بطاقة تعرض ما يستوجب فعلاً لا مجرد عدّاد، وتُخفى إن كانت فارغة
 * حتى لا تغرق الصفحة الحقيقي في الصفري.
 */
class MorningController extends Controller
{
    public function index()
    {
        $u = auth()->user();
        $cards = [];

        $add = function (string $ico, string $title, string $why, $rows, ?string $link = null) use (&$cards) {
            $rows = collect($rows);
            if ($rows->isEmpty()) return;
            $cards[] = ['ico' => $ico, 'title' => $title, 'why' => $why, 'rows' => $rows, 'link' => $link];
        };

        // ── قرارات تنتظرك ──
        if (hub_can($u, 'approvals', 'v')) {
            $ap = hub_scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')->where('status', 'معلّق')
                ->orderBy('due')->limit(8)->get(['id', 'title', 'due']);
            $add('✋', 'قرارات تنتظر حسمك', 'عمليات موقوفة لن تُنفَّذ قبل اعتمادك',
                $ap->map(fn ($r) => ['t' => $r->title, 's' => $r->due ? 'الموعد ' . substr((string) $r->due, 0, 10) : '',
                                     'u' => route('m.show', ['approvals', $r->id]), 'tone' => 'wn']),
                route('m.index', 'approvals'));
        }

        // ── طلبات داخلية بانتظار تقييم ──
        if (Schema::hasTable('internal_requests') && hub_can($u, 'requests', 'v')) {
            $rq = hub_scope(DB::table('internal_requests')->whereNull('deleted_at'), 'requests')
                ->whereIn('status', ['جديد', 'قيد التقييم', 'بانتظار الاعتماد'])
                ->orderByDesc('created_at')->limit(6)->get(['id', 'title', 'prio_req', 'status']);
            $add('📨', 'طلبات داخلية بلا قرار', 'طلبات فريقك واقفة عند التقييم',
                $rq->map(fn ($r) => ['t' => $r->title, 's' => trim(($r->prio_req ?: '') . ' · ' . $r->status, ' ·'),
                                     'u' => route('m.show', ['requests', $r->id]), 'tone' => 'wn']),
                route('m.index', 'requests'));
        }

        // ── حوادث تقنية مفتوحة ──
        if (Schema::hasTable('incidents') && hub_can($u, 'incidents', 'v')) {
            $inc = hub_scope(DB::table('incidents')->whereNull('deleted_at'), 'incidents')
                ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
                ->orderByDesc('started_at')->limit(6)->get(['id', 'title', 'severity', 'status']);
            $add('🚨', 'حوادث تقنية مفتوحة', 'خدمات متأثرة الآن',
                $inc->map(fn ($r) => ['t' => $r->title, 's' => trim(($r->severity ?: '') . ' · ' . $r->status, ' ·'),
                                      'u' => route('m.show', ['incidents', $r->id]), 'tone' => 'bad']),
                route('m.index', 'incidents'));
        }

        // ── تذاكر تجاوزت الـ SLA ──
        if (hub_can($u, 'tickets', 'v')) {
            $late = collect();
            // نمرّر السجل الحقيقي: hub_sla يحتاج معرّفه ليجد أول رد على التذكرة
            foreach (hub_scope(\App\Models\Ticket::query(), 'tickets')
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', ['تم الحل', 'مغلقة'])->limit(80)->get() as $t) {
                $sla = hub_sla($t);
                $miss = array_filter(['أول رد' => ! empty($sla['respLate']), 'الحل' => ! empty($sla['resLate'])]);
                if ($miss) {
                    $late->push(['t' => $t->subject,
                                 's' => 'تجاوزت ' . implode(' و', array_keys($miss)) . ' · ' . ($sla['policy'] ?? ''),
                                 'u' => route('m.show', ['tickets', $t->id]), 'tone' => 'bad']);
                }
            }
            $add('⏰', 'تذاكر تجاوزت الاتفاقية', 'وعدٌ للعميل تأخر عن موعده', $late->take(8), route('support'));
        }

        // ── مهام متأخرة ──
        $tk = hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->whereNotNull('due')->whereDate('due', '<', today())
            ->whereNotIn('status', ['منجزة', 'مكتملة', 'ملغاة'])
            ->orderBy('due')->limit(8)->get(['id', 'title', 'due']);
        $add('🔥', 'مهام تجاوزت موعدها', 'التزامات مضى وقتها ولم تُغلق',
            $tk->map(fn ($r) => ['t' => $r->title, 's' => 'كان ' . substr((string) $r->due, 0, 10),
                                 'u' => route('m.show', ['tasks', $r->id]), 'tone' => 'bad']),
            route('m.index', 'tasks'));

        // ── مستحقات مالية ──
        if (hub_can($u, 'fin', 'v')) {
            $due = hub_scope(DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
                ->whereNotNull('due')->whereDate('due', '<=', today()->addDays(7))
                ->whereRaw('COALESCE(paid,0) < COALESCE(total,0)')
                ->orderBy('due')->limit(8)->get(['id', 'doc_no', 'partner', 'total', 'paid', 'due']);
            $add('💸', 'مستحقات خلال أسبوع', 'فواتير لم تُسدَّد بالكامل وموعدها قريب',
                $due->map(fn ($r) => ['t' => trim(($r->doc_no ?: '') . ' — ' . ($r->partner ?: ''), ' —'),
                                      's' => 'متبقٍ ' . number_format((float) $r->total - (float) $r->paid, 2) . ' · ' . substr((string) $r->due, 0, 10),
                                      'u' => route('m.show', ['fin', $r->id]),
                                      'tone' => $r->due < today()->toDateString() ? 'bad' : 'wn']),
                route('m.index', 'fin'));
        }

        // ── ينتهي قريباً ──
        $exp = collect(hub_expiry())->take(8)
            ->map(fn ($e) => [
                't' => $e['name'] ?? '—',
                's' => trim(($e['mlabel'] ?? '') . ' · ' . ($e['flabel'] ?? '') . ' ' . ($e['date'] ?? '')
                       . (isset($e['days']) ? ($e['days'] < 0 ? ' (انتهى منذ ' . abs($e['days']) . ' يوماً)'
                                                              : ' (بعد ' . $e['days'] . ' يوماً)') : ''), ' ·'),
                'u' => isset($e['module'], $e['id']) ? route('m.show', [$e['module'], $e['id']]) : null,
                'tone' => (($e['days'] ?? 99) < 0) ? 'bad' : 'wn']);
        $add('⏳', 'ينتهي قريباً', 'رخص ودومينات وشهادات على وشك الانتهاء', $exp, route('alerts'));

        // ── غياب اليوم ──
        if (hub_can($u, 'leaves', 'v')) {
            $lv = hub_scope(DB::table('leave_requests')->whereNull('deleted_at'), 'leaves')->where('status', 'معتمد')
                ->whereDate('date_from', '<=', today())->whereDate('date_to', '>=', today())
                ->limit(8)->get(['id', 'emp_id', 'type']);
            $names = hub_ref_labels('hr', $lv->pluck('emp_id')->all());
            $add('🏝️', 'غائبون اليوم', 'من لن تجده على رأس العمل',
                $lv->map(fn ($r) => ['t' => $names[$r->emp_id] ?? '—', 's' => $r->type ?: 'إجازة', 'u' => null, 'tone' => '']),
                route('m.index', 'leaves'));
        }

        // ── تشغيلي وأمني (للمالك) ──
        if (hub_is_owner($u)) {
            $ops = collect();
            $bk = setting('heartbeat.backup');
            if (! $bk || \Illuminate\Support\Carbon::parse($bk)->lt(now()->subHours(26))) {
                $ops->push(['t' => 'النسخ الاحتياطي لم يعمل خلال ٢٦ ساعة',
                            's' => $bk ? 'آخر تشغيل ' . \Illuminate\Support\Carbon::parse($bk)->diffForHumans() : 'لم يعمل قط',
                            'u' => route('ops.index'), 'tone' => 'bad']);
            }
            // «اختبار استعادة النسخ» أُخرج من الواجهة بطلب المالك — لا تنبيه له.
            // (المسار والبيانات باقيان: لا حذف ولا هجرة مدمّرة.)
            // الأخطاء الجديدة بأسمائها لا بعددها: «٣ أخطاء بانتظار المعالجة» لا تقول
            // شيئاً — الرسالة والموضع والتكرار هي ما يُبنى عليه قرار
            $newErrs = DB::table('error_events')->where('status', 'جديد')
                ->orderByDesc('count')->limit(4)->get(['id', 'message', 'file', 'line', 'kind', 'count']);
            foreach ($newErrs as $er) {
                $where = $er->file ? str_replace(base_path() . '/', '', $er->file) . ($er->line ? ':' . $er->line : '') : '';
                $ops->push([
                    't' => \Illuminate\Support\Str::limit($er->message, 80),
                    's' => trim(($er->count > 1 ? "تكرّر {$er->count} مرة · " : '')
                        . (['php' => 'استثناء PHP', 'api' => 'خطأ API', 'js' => 'خطأ متصفح', 'slow' => 'طلب بطيء'][$er->kind] ?? $er->kind))
                        . ($where ? ' · ' . $where : ''),
                    'u' => route('errors.show', $er->id),
                    'tone' => $er->kind === 'slow' ? 'wn' : 'bad',
                ]);
            }
            $fails = DB::table('audits')->where('action', 'دخول فاشل')
                ->where('created_at', '>=', now()->subDay())->count();
            if ($fails >= 5) {
                $ops->push(['t' => "{$fails} محاولة دخول فاشلة خلال ٢٤ ساعة", 's' => 'راجع مركز الأمان',
                            'u' => route('security.index'), 'tone' => 'bad']);
            }
            if (DB::table('outbox')->where('state', 'failed')->count()) {
                $ops->push(['t' => 'رسائل صادرة فاشلة في الطابور', 's' => 'تلجرام أو بريد لم يصل',
                            'u' => route('ops.index'), 'tone' => 'wn']);
            }
            $add('🖥️', 'تنبيهات تشغيلية وأمنية', 'ما يخص سلامة النظام نفسه', $ops, route('ops.index'));
        }

        // ── مشاريع متعثرة ──
        $bad = collect();
        foreach (hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects')
                    ->whereNotIn('status', ['مكتمل', 'ملغى'])->limit(25)->get(['id', 'name']) as $p) {
            $h = hub_project_health($p->id);
            if (($h['score'] ?? 100) < 55) {
                $bad->push(['t' => $p->name, 's' => 'صحة ' . $h['score'] . '٪ — ' . $h['label'],
                            'u' => route('costs.index', ['p' => $p->id]), 'tone' => 'bad']);
            }
        }
        $add('📉', 'مشاريع متعثرة', 'صحتها دون ٥٥٪ حسب التأخير والميزانية والمهام والمخاطر',
            $bad->take(6), route('costs.index'));

        return view('morning', ['cards' => $cards, 'when' => now()]);
    }
}
