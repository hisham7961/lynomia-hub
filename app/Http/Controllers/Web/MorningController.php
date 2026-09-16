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

        /**
         * **الشارةُ تعدّ المشكلةَ لا مساحةَ العرض** (W-1 · الطور ١٦٧).
         *
         * كلُّ بطاقةٍ تقصّ صفوفَها إلى سقفٍ للعرض، وكانت الشارةُ تعدّ **المقصوصَ**
         * فتُقرأ ستَّ عشرةَ مهمّةً متأخّرةً «٨». والخطأُ في اتّجاهِ التهوينِ دائماً:
         * كلّما ازدادت المتأخّراتُ ثبتت الشارةُ على سقفِها، فيعمى المؤشّرُ كلّما
         * ازدادت الحاجةُ إليه.
         *
         * فصار `$n` **العددَ الحقيقيَّ** يُمرَّر صراحةً، ويبقى المسرودُ مقصوصاً
         * كما هو و«عرض الكل ←» في مكانه — صدقٌ في العدّاد لا إغراقٌ للصفحة.
         * ومن لم يُمرّر عدّاداً يبقى على العدِّ القديم (لا كسرَ لبطاقةٍ قائمة).
         */
        $add = function (string $ico, string $title, string $why, $rows, ?string $link = null, ?int $n = null) use (&$cards) {
            $rows = collect($rows);
            if ($rows->isEmpty()) return;
            $cards[] = ['ico' => $ico, 'title' => $title, 'why' => $why, 'rows' => $rows,
                        'link' => $link, 'n' => max($n ?? $rows->count(), $rows->count())];
        };

        // ── قرارات تنتظرك ──
        if (hub_can($u, 'approvals', 'v')) {
            $apQ = hub_scope(DB::table('approvals')->whereNull('deleted_at'), 'approvals')->where('status', 'معلّق');
            $apN = (clone $apQ)->count();
            $ap = $apQ->orderBy('due')->limit(8)->get(['id', 'title', 'due']);
            $add('✋', 'قرارات تنتظر حسمك', 'عمليات موقوفة لن تُنفَّذ قبل اعتمادك',
                $ap->map(fn ($r) => ['t' => $r->title, 's' => $r->due ? 'الموعد ' . substr((string) $r->due, 0, 10) : '',
                                     'u' => route('m.show', ['approvals', $r->id]), 'tone' => 'wn']),
                route('m.index', 'approvals'), $apN);
        }

        // ── طلبات داخلية بانتظار تقييم ──
        if (Schema::hasTable('internal_requests') && hub_can($u, 'requests', 'v')) {
            $rqQ = hub_scope(DB::table('internal_requests')->whereNull('deleted_at'), 'requests')
                ->whereIn('status', ['جديد', 'قيد التقييم', 'بانتظار الاعتماد'])
                ;
            $rqN = (clone $rqQ)->count();
            $rq = $rqQ->orderByDesc('created_at')->limit(6)->get(['id', 'title', 'prio_req', 'status']);
            $add('📨', 'طلبات داخلية بلا قرار', 'طلبات فريقك واقفة عند التقييم',
                $rq->map(fn ($r) => ['t' => $r->title, 's' => trim(($r->prio_req ?: '') . ' · ' . $r->status, ' ·'),
                                     'u' => route('m.show', ['requests', $r->id]), 'tone' => 'wn']),
                route('m.index', 'requests'), $rqN);
        }

        // ── حوادث تقنية مفتوحة ──
        if (Schema::hasTable('incidents') && hub_can($u, 'incidents', 'v')) {
            $incQ = hub_scope(DB::table('incidents')->whereNull('deleted_at'), 'incidents')
                ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد']);
            $incN = (clone $incQ)->count();
            $inc = $incQ->orderByDesc('started_at')->limit(6)->get(['id', 'title', 'severity', 'status']);
            $add('🚨', 'حوادث تقنية مفتوحة', 'خدمات متأثرة الآن',
                $inc->map(fn ($r) => ['t' => $r->title, 's' => trim(($r->severity ?: '') . ' · ' . $r->status, ' ·'),
                                      'u' => route('m.show', ['incidents', $r->id]), 'tone' => 'bad']),
                route('m.index', 'incidents'), $incN);
        }

        // ── تذاكر تجاوزت الـ SLA ──
        if (hub_can($u, 'tickets', 'v')) {
            $late = collect();
            $open = hub_scope(\App\Models\Ticket::query(), 'tickets')
                        ->whereNull('deleted_at')
                        ->whereNotIn('status', ['تم الحل', 'مغلقة'])->limit(80)->get();

            /**
             * **أوّلُ ردٍّ يُحمَّل دفعةً واحدة** — لا استعلاماً لكلِّ تذكرة.
             *
             * `hub_sla($t)` بلا وسيطٍ ثانٍ تسأل القاعدةَ عن أوّلِ ردٍّ **لكلِّ**
             * تذكرة، فكلفةُ هذه الصفحةِ كانت تنمو خطّيّاً بعددِ التذاكرِ المفتوحة
             * — وهي أوّلُ ما يفتحه كلُّ موظّفٍ كلَّ صباح، أسوأُ موضعٍ لنمطٍ يكبر.
             * والمخرجُ موجودٌ في الدالّةِ نفسِها منذ البداية (`$firstReply` مُمرَّراً)
             * ويستعمله `ExecutionStats` فعلاً؛ هذه الصفحةُ وحدَها لم تستعمله.
             */
            $firsts = $open->isEmpty() ? collect() : DB::table('comments')
                ->where('module', 'tickets')->whereIn('record_id', $open->pluck('id'))
                ->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('internal', false)->orWhereNull('internal'))
                ->select('record_id', DB::raw('MIN(created_at) as at'))->groupBy('record_id')
                ->pluck('at', 'record_id');

            foreach ($open as $t) {
                $sla = hub_sla($t, $firsts[(string) $t->id] ?? null);
                $miss = array_filter(['أول رد' => ! empty($sla['respLate']), 'الحل' => ! empty($sla['resLate'])]);
                if ($miss) {
                    $late->push(['t' => $t->subject,
                                 's' => 'تجاوزت ' . implode(' و', array_keys($miss)) . ' · ' . ($sla['policy'] ?? ''),
                                 'u' => route('m.show', ['tickets', $t->id]), 'tone' => 'bad']);
                }
            }
            // حدٌّ باقٍ يُقال: لا يُفحَص إلّا أوّلُ ٨٠ تذكرةٍ مفتوحة،
            // فالعدّادُ صادقٌ حتى هذا السقفِ لا مطلقاً.
            $add('⏰', 'تذاكر تجاوزت الاتفاقية', 'وعدٌ للعميل تأخر عن موعده',
                $late->take(8), route('support'), $late->count());
        }

        // ── مهام متأخرة ──
        // كل بندٍ في هذه الصفحة يفحص صلاحية وحدته، وهذا وحده كان يكتفي بالنطاق:
        // فمن لا يرى المهام أصلاً كان يقرأ عناوينها في ملخّص صباحه
        $tkQ = hub_can($u, 'tasks', 'v')
            ? hub_scope(DB::table('tasks')->whereNull('deleted_at'), 'tasks')
            ->whereNotNull('due')->whereDate('due', '<', today())
            ->whereNotIn('status', ['منجزة', 'مكتملة', 'ملغاة'])
            : null;
        $tkN = $tkQ ? (clone $tkQ)->count() : 0;
        $tk = $tkQ ? $tkQ->orderBy('due')->limit(8)->get(['id', 'title', 'due']) : collect();
        $add('🔥', 'مهام تجاوزت موعدها', 'التزامات مضى وقتها ولم تُغلق',
            $tk->map(fn ($r) => ['t' => $r->title, 's' => 'كان ' . substr((string) $r->due, 0, 10),
                                 'u' => route('m.show', ['tasks', $r->id]), 'tone' => 'bad']),
            route('m.index', 'tasks'), $tkN);

        // ── مستحقات مالية ──
        if (hub_can($u, 'fin', 'v')) {
            $dueQ = hub_scope(DB::table('fin_documents')->whereNull('deleted_at'), 'fin')
                ->whereNotNull('due')->whereDate('due', '<=', today()->addDays(7))
                ->whereRaw('COALESCE(paid,0) < COALESCE(total,0)');
            $dueN = (clone $dueQ)->count();
            $due = $dueQ->orderBy('due')->limit(8)->get(['id', 'doc_no', 'partner', 'total', 'paid', 'due']);
            $add('💸', 'مستحقات خلال أسبوع', 'فواتير لم تُسدَّد بالكامل وموعدها قريب',
                $due->map(fn ($r) => ['t' => trim(($r->doc_no ?: '') . ' — ' . ($r->partner ?: ''), ' —'),
                                      's' => 'متبقٍ ' . number_format((float) $r->total - (float) $r->paid, 2) . ' · ' . substr((string) $r->due, 0, 10),
                                      'u' => route('m.show', ['fin', $r->id]),
                                      'tone' => $r->due < today()->toDateString() ? 'bad' : 'wn']),
                route('m.index', 'fin'), $dueN);
        }

        // ── ينتهي قريباً ──
        /*
         * **لافتةٌ تقول نافذتَها** (W-3 · الطور ١٦٧). كانت هذه البطاقةُ تقول
         * «ينتهي قريباً» **وشارةُ الشريطِ إلى جانبِها تقول الكلمتَين نفسَهما** —
         * وخلفَهما نافذتان: هذه نافذةُ الرادارِ كلُّها (`hub_radar_window()`)،
         * وتلك متأخّرٌ أو خلال ٧ أيام (`hub_expiry_count()`). ولا رقمَ منهما
         * خاطئ؛ الكلمةُ وحدَها هي التي كذبت. ومن يرى ٥٥ و٥٠ متلاصقَين لا
         * يستنتج نافذتَين بل يستنتج عطباً — وهو ما حذّر منه `AlertController`
         * يومَ PROD-05: «تناقضُ شاشتين أسوأُ من صمتِهما».
         *
         * فالعنوانُ يصرّح بنافذتِه (كبطاقةِ «مستحقات خلال أسبوع» فوقَها)،
         * وسطرُ «لماذا» يُسمّي الرقمَ الأصغرَ **وصاحبَه** فلا يُترك التوفيقُ
         * بين الرقمَين للقارئ. ويُقرأ الأصغرُ من `hub_expiry_count()` نفسِها —
         * تعريفٌ واحدٌ لسؤالٍ واحد، لا نسخةٌ ثانيةٌ من الترشيحِ هنا.
         *
         * **وحدٌّ متبقٍّ مكتوب:** `hub_expiry()` تقصّ عند ٢٠٠ صفٍّ بعد الترتيب
         * بالأقربِ انتهاءً، فما جاوز المئتَين يُقرأ «٢٠٠».
         */
        $expAll  = collect(hub_expiry());
        $expWeek = hub_expiry_count();
        $exp = $expAll->take(8)
            ->map(fn ($e) => [
                't' => $e['name'] ?? '—',
                's' => trim(($e['mlabel'] ?? '') . ' · ' . ($e['flabel'] ?? '') . ' ' . ($e['date'] ?? '')
                       . (isset($e['days']) ? ($e['days'] < 0 ? ' (انتهى منذ ' . abs($e['days']) . ' يوماً)'
                                                              : ' (بعد ' . $e['days'] . ' يوماً)') : ''), ' ·'),
                'u' => isset($e['module'], $e['id']) ? hub_expiry_url($e) : null,
                'tone' => (($e['days'] ?? 99) < 0) ? 'bad' : 'wn']);
        $add('⏳', 'ينتهي خلال ' . hub_radar_window() . ' يوماً',
            'رخص ودومينات وشهادات على وشك الانتهاء — منها ' . $expWeek
            . ' متأخّرٌ أو ينتهي خلال ٧ أيام، وهو ما تعدّه شارةُ الشريط الجانبيّ',
            $exp, route('alerts'), $expAll->count());

        // ── غياب اليوم ──
        if (hub_can($u, 'leaves', 'v')) {
            $lvQ = hub_scope(DB::table('leave_requests')->whereNull('deleted_at'), 'leaves')->where('status', 'معتمد')
                ->whereDate('date_from', '<=', today())->whereDate('date_to', '>=', today());
            $lvN = (clone $lvQ)->count();
            $lv = $lvQ->limit(8)->get(['id', 'emp_id', 'type']);
            $names = hub_ref_labels('hr', $lv->pluck('emp_id')->all());
            $add('🏝️', 'غائبون اليوم', 'من لن تجده على رأس العمل',
                $lv->map(fn ($r) => ['t' => $names[$r->emp_id] ?? '—', 's' => $r->type ?: 'إجازة', 'u' => null, 'tone' => '']),
                route('m.index', 'leaves'), $lvN);
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
            // شيئاً — الرسالة والموضع والتكرار هي ما يُبنى عليه قرار.
            // (WP-3.4) من القارئ الواحد ErrorStats — كانت نسخةً من خمسٍ متباعدة.
            $newErrs = \App\Support\ErrorStats::topNew(4);
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
        // النطاقُ كان مفروضاً والصلاحيةُ منسيّة — فمن لا يملك وحدةَ المشاريع
        // يقرأ أسماءَها وصحّتَها في ملخّص صباحه. الحارسُ على نمط بطاقة المهام.
        //
        // **والبطاقةُ تقود إلى بابٍ يُفتَح لقارئها** (محاكاةُ الشهر · M-F5): شرطُ
        // ظهورِها `projects:v`، وكلُّ روابطها كانت تقصد لوحةَ التكاليف التي تشترط
        // رايةَ `finAnalytics` — **شرطان من عالمَين**، و٢٧ من ٣٠ موظّفاً في المحاكاة
        // يحملون الأوّلَ دون الثاني. فحاملُ الرايةِ يبقى يُقاد إلى تحليلِ التكلفةِ
        // كما كان، ومن لا يحملها يُقاد إلى **سجلِّ المشروعِ نفسِه** — وفيه صحّتُه
        // التي استدعت البطاقةَ لأجلها. لا وجهةَ تُحذف؛ وجهةٌ تُصحَّح.
        $bad = collect();
        if (hub_can($u, 'projects', 'v')) {
            $toCosts = hub_can_org_analytics($u) && hub_monitor_group('finAnalytics', $u)
                && hub_can_project_finance($u);
            foreach (hub_scope(DB::table('projects')->whereNull('deleted_at'), 'projects')
                        ->whereNotIn('status', ['مكتمل', 'ملغى'])->limit(25)->get(['id', 'name']) as $p) {
                $h = hub_project_health($p->id);
                if (($h['score'] ?? 100) < 55) {
                    $bad->push(['t' => $p->name, 's' => 'صحة ' . $h['score'] . '٪ — ' . $h['label'],
                                'u' => $toCosts ? route('costs.index', ['p' => $p->id])
                                                : route('m.show', ['projects', $p->id]),
                                'tone' => 'bad']);
                }
            }
        }
        $add('📉', 'مشاريع متعثرة', 'صحتها دون ٥٥٪ حسب التأخير والميزانية والمهام والمخاطر',
            $bad->take(6), ($toCosts ?? false) ? route('costs.index') : route('m.index', 'projects'));

        return view('morning', ['cards' => $cards, 'when' => now()]);
    }
}
