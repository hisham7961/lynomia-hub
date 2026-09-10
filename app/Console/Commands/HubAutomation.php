<?php

namespace App\Console\Commands;

use App\Models\AlertRule;
use App\Models\FinDocument;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\RecurringDoc;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * محرك الأتمتة اليومي:
 *  1) المصروفات المتكررة: يولّد مستندات مالية عند حلول «التوليد القادم» ويُقدّم الموعد حسب الدورة.
 *  2) قواعد التنبيه: يقيّم القواعد المفعّلة وينشئ إشعارات داخلية + رسائل outbox (تلجرام/بريد) حسب القنوات.
 */
class HubAutomation extends Command
{
    protected $signature = 'hub:automation {--dry : عرض ما سيحدث دون كتابة}';

    /**
     * ختمُ الغياب ليوم أمس: يومُ عملٍ مجدولٌ بلا حضورٍ ولا إجازةٍ معتمدة =
     * غياب — **يختمه الكنسُ لا لحظةُ التقييم**، فمن سجّل متأخراً لم يُظلَم.
     * (غيابُ التقرير وحدَه ليس غياباً أبداً — حالتُه «حاضر — بلا تقرير».)
     */
    protected function workdayClose(): int
    {
        if ($this->dry || ! \Illuminate\Support\Facades\Schema::hasTable('attendance')) return 0;
        try {
            return \App\Support\Workday::close();
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }
    /**
     * مصالحةُ تقارير الحضور (§52): شبكةُ أمانٍ يوميّةٌ فوق الاشتقاقِ الحيّ — تُشعِر
     * بالتقريرِ الناقصِ بعد المهلة مرّةً واحدةً (§39). معزولةُ الفشلِ كسائرِ الخطوات.
     */
    protected function reconcileReports(): int
    {
        if ($this->dry || ! \Illuminate\Support\Facades\Schema::hasTable('attendance')) return 0;
        try {
            // الأمرُ يطبع سطرَه المفصَّل؛ نعيد ٠/١ لعدّادِ السطرِ الملخَّص فقط
            return \Illuminate\Support\Facades\Artisan::call('attendance:reconcile-reports') === self::SUCCESS ? 1 : 0;
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    protected $description = 'توليد المستندات المتكررة وتقييم قواعد التنبيه';

    protected bool $dry = false;

    public function handle(): int
    {
        $t0 = microtime(true);
        $this->monitorUsers = null;   // ذاكرة المستلمين تصلح لتشغيلةٍ واحدة
        $this->dry = (bool) $this->option('dry');
        if ($this->dry) $this->warn('وضع المعاينة — لن يُكتب شيء');

        $g = $this->recurring();
        // عزلُ الفشل كسائر الخطوات (v2.399): قاعدةٌ واحدة ترمي كانت تُسقط التذكيرات والعقود والنبضة
        try {
            $a = $this->alertRules();
        } catch (\Throwable $e) {
            report($e);
            $a = ['hits' => 0, 'rules' => 0, 'esc' => 0, 'outbox' => 0];
        }
        $e = $this->esignReminders();
        $c = $this->contractsAuto();
        $b = $this->budgetsAuto();
        $o = $this->obligationsAuto();
        $p = $this->pruneNotifications();
        $k = $this->okrRefresh();
        $w = $this->workdayClose();
        $rr = $this->reconcileReports();
        $s = $this->signalsPrune();
        $m = $this->marginSnapshot();

        $this->info("المتكررات: {$g['docs']} مستند مولّد، {$g['manual']} تذكير يدوي · القواعد: {$a['hits']} تنبيه ({$a['rules']} قاعدة)، {$a['esc']} مُتصاعد، {$a['outbox']} رسالة صادرة · توقيعات: {$e} تذكير · عقود: {$c['expired']} انتهاء، {$c['drafts']} مسودة تجديد · ميزانيات: {$b} تنبيه · التزامات: {$o} متأخر · إشعارات: {$p} مُقلَّم · أهداف: {$k} محدَّث · حضور: {$w} غياب مختوم · تقارير: {$rr} تنبيهُ نقص · إشارات: {$s} تصرّفٌ يتيمٌ مُشذَّب · هوامش: {$m} لقطة");

        if (! $this->dry) \App\Support\Health::beat('automation', (int) round((microtime(true) - $t0) * 1000));
        return self::SUCCESS;
    }

    /**
     * لقطةُ هامشِ المشاريع اليوميّة — بها وحدها يصير للهامش **تاريخ** (v2.398).
     *
     * `hub_project_pl` يحسب هامشَ اللحظة من الفواتير والساعات والسيرفرات، ولا يحفظ
     * شيئاً — فلا سبيلَ لقول «الهامشُ يتدهور» بلا سلسلةٍ زمنية. تُكتب نقطةٌ واحدةٌ
     * في اليوم لكلِّ مشروعٍ غيرِ مغلق في `metric_points` القائم (وحدة `projects`،
     * مقياس `pl_margin`، مصدر `auto`) — لا مخزنَ جديد، والمفتاحُ الفريد يُحدّث لا
     * يُكرّر إن أُعيد التشغيل في اليوم نفسه، والتقليمُ السنويّ أدناه يشمله.
     * مشروعٌ بلا إيرادٍ هامشُه `null` فلا يُكتب — «لا قياس» ليس صفراً.
     * تُقرأ في `hub_recommendations` (١٤: تدهورُ الهامش) — وفي سياق النظام بلا
     * مستخدمٍ فتكون النقطةُ واحدةً صادقةً للجميع، كما okrRefresh.
     */
    protected function marginSnapshot(): int
    {
        if ($this->dry || ! \Illuminate\Support\Facades\Schema::hasTable('metric_points')) return 0;
        try {
            $ids = DB::table('projects')->whereNull('deleted_at')
                ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', hub_closed_states()))
                ->orderBy('id')->pluck('id');
            $n = 0;
            $day = now()->startOfDay();
            foreach ($ids as $id) {
                $pl = hub_project_pl((string) $id, true);
                if (! $pl || ($pl['margin'] ?? null) === null) continue;
                hub_metric_put('projects', (string) $id, 'pl_margin', (float) $pl['margin'], $day, 'auto',
                    ['profit' => (float) ($pl['profit'] ?? 0), 'revenue' => (float) ($pl['revenue']['invoiced'] ?? 0)]);
                $n++;
            }

            return $n;
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * تشذيبُ تصرّفاتِ الإشارات اليتيمة: إشارةٌ حُلَّت وزالت (لم تُرَ منذ مدّة) فلا
     * معنى لحالة تصرّفها. لا محرّكَ إشاراتٍ ثانٍ — الإشاراتُ تبقى محسوبةً حيّاً في
     * `hub_recommendations`/مركز الفعل، وتصلُ الملخّصَ اليوميّ عبر `hub:digest` أصلاً.
     */
    protected function signalsPrune(): int
    {
        if ($this->dry) return 0;
        try {
            return \App\Support\ActionCenter::prune();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * تثبيتُ قيم الأهداف من مصادرها — **في سياق النظام**.
     *
     * كانت الأعمدةُ المشتركة (`key_results.current_value` و`objectives.progress`)
     * تُكتب من فتح الشاشة، فيدهسها قارئٌ مقيَّد النطاق برقمِه الجزئيّ (v2.331).
     * والقراءةُ صارت لا تكتب — فالتثبيتُ يقع هنا بلا مستخدمٍ أصلاً: نطاقٌ كامل
     * ورقمٌ واحدٌ صادقٌ للجميع، مرةً في كل دورةِ أتمتة لا مع كل نقرة.
     */
    protected function okrRefresh(): int
    {
        if ($this->dry || ! \Illuminate\Support\Facades\Schema::hasTable('objectives')) return 0;

        $n = 0;
        try {
            foreach (\App\Models\Objective::whereNull('deleted_at')
                        ->whereNotIn('status', ['ملغى', 'مكتمل'])
                        ->orderBy('id')->limit(500)->pluck('id') as $id) {
                if (hub_okr_progress($id, true, false, true)) $n++;
            }
        } catch (\Throwable $e) {
            $this->warn('تعذّر تحديث الأهداف: ' . $e->getMessage());
        }

        return $n;
    }

    /**
     * CLM م4: تذكيرات الموقّعين المتلكئين — لكل عتبة من setting('esign.remind_days')
     * («3,7» افتراضاً) تذكيرٌ واحد بالضبط: عدد أحداث reminded يلحق عدد العتبات
     * المقطوعة فلا تكرار مهما أُعيد التشغيل.
     */
    protected function esignReminders(): int
    {
        $sent = 0;
        try {
            $thresholds = array_values(array_filter(array_map('intval',
                preg_split('/[\s,،]+/u', (string) (setting('esign.remind_days') ?: '3,7')))));
            if (! $thresholds) return 0;

            $pending = \App\Models\ContractSigner::where('status', 'بانتظار التوقيع')
                ->whereNotNull('email')->where('role', 'موقّع')->limit(500)->get();
            foreach ($pending as $s) {
                $req = \App\Models\SignRequest::find($s->request_id);
                if (! $req || $req->status !== 'بانتظار التوقيع' || $req->cancelled_at) continue;
                if ($req->expires_at && now()->gt($req->expires_at)) continue;
                if ($req->mode === 'متسلسل' && \App\Models\ContractSigner::where('request_id', $req->id)
                        ->where('role', 'موقّع')->where('order', '<', $s->order)
                        ->where('status', '!=', 'وُقّع')->exists()) continue;

                $since = $req->sent_at ?: $req->created_at;
                if (! $since) continue;
                $days = (int) now()->diffInDays($since, true);
                $crossed = count(array_filter($thresholds, fn ($t) => $days >= $t));
                $already = \App\Models\ContractEvent::where('request_id', $req->id)
                    ->where('signer_id', $s->id)->where('event', 'reminded')->count();
                if ($crossed <= $already) continue;

                if (! $this->dry) {
                    \App\Models\OutboxMessage::create([
                        'kind' => 'sign_reminder', 'channel' => 'mail', 'target' => $s->email,
                        'text' => 'تذكير: وثيقة «' . \Illuminate\Support\Str::limit($req->title, 60)
                            . '» بانتظار توقيعك منذ ' . $days . ' يوماً: ' . route('sign.show', $s->token),
                        'state' => 'queued', 'created_at' => now(),
                    ]);
                    \App\Models\ContractEvent::log('reminded', $req, ['signer_id' => $s->id]);
                }
                $sent++;
            }
        } catch (\Throwable $e) {
            report($e);   // التذكيرات لا تُسقط بقية الأتمتة
        }

        return $sent;
    }

    /**
     * CLM م8: أتمتة دورة العقد.
     *  - الانتهاء التلقائي: ساري تجاوز نهايته → «منتهي» بحفظ Eloquent فيطلق
     *    contract.expired فعلاً لأول مرة — خلف setting('contracts.auto_expire')
     *    المعطل افتراضياً لإصدارٍ كامل (قرار الانقلاب الآلي للمنشأة لا لنا).
     *  - مسودة التجديد المبكرة: عقدٌ تجديده «تلقائي» وحقل الإشعار notice مضبوط
     *    تُبنى مسودة تجديده قبل النهاية بمدة الإشعار (idempotent عبر spawnRenewal).
     */
    protected function contractsAuto(): array
    {
        $expired = 0; $drafts = 0;
        try {
            // بالسلسلة لا بالنوع: hub:set يخزّن العدد 1 فيُقرأ int و`=== '1'` تفشل —
            // كان التفعيلُ من الأمر الموثَّق نفسِه لا يعمل
            if ((string) setting('contracts.auto_expire') === '1') {
                $due = \App\Models\Contract::where('status', 'ساري')
                    ->whereNotNull('date_end')->whereDate('date_end', '<', today())->limit(200)->get();
                foreach ($due as $c) {
                    if (! $this->dry) {
                        $c->status = 'منتهي';
                        $c->save();
                        \App\Support\FlowRunner::fire('status', 'contracts', $c, 'منتهي');
                        $this->notifyMonitors('contract-exp',
                            'انتهى العقد «' . \Illuminate\Support\Str::limit($c->title, 60) . '» تلقائياً بتجاوز نهايته',
                            'contracts', $c->id);
                    }
                    $expired++;
                }
            }

            // **المرشّحُ الخشن في SQL قبل الحدّ** (v2.316): `limit(200)` كان يُطبَّق
            // **قبل** مرشّح نافذة الإشعار في PHP، فمئتا عقدٍ مؤهّل تُخفي المستحقَّ
            // إن وقع خارج أوّلها — وهو يبقى «ساري» فيحتلّ خانتَه للأبد ولا تُنشأ
            // مسودةُ تجديده أبداً. ترتيبٌ حتميّ (`date_end` ثمّ `id`) والأقربُ
            // نهايةً أولاً، بعد حصر SQL بما يمكن أن يستحقّ أصلاً.
            $maxNotice = (int) \App\Models\Contract::where('status', 'ساري')->where('renewal', 'تلقائي')
                ->max('notice');
            $renewable = \App\Models\Contract::where('status', 'ساري')->where('renewal', 'تلقائي')
                ->whereNotNull('notice')->where('notice', '>', 0)->whereNotNull('date_end')
                ->whereDate('date_end', '<=', today()->addDays(max(1, $maxNotice)))
                ->orderBy('date_end')->orderBy('id')->limit(200)->get()
                ->filter(fn ($c) => \Illuminate\Support\Carbon::parse($c->date_end)
                    ->lte(today()->addDays((int) $c->notice)));
            foreach ($renewable as $c) {
                if ($this->dry) { $drafts++; continue; }
                if ($new = \App\Http\Controllers\Web\ContractActionsController::spawnRenewal($c)) {
                    $drafts++;
                    $this->notifyMonitors('contract-renew',
                        'أُنشئت مسودة تجديد «' . \Illuminate\Support\Str::limit($c->title, 60)
                            . '» (' . $new->doc_no . ') قبل نهايته بمدة الإشعار — راجعها وأرسلها',
                        'contracts', $new->id);
                }
            }
        } catch (\Throwable $e) {
            report($e);   // أتمتة العقود لا تُسقط بقية المحرك
        }

        return ['expired' => $expired, 'drafts' => $drafts];
    }

    /**
     * تنبيه «حد الميزانية»: alert_pct كان حقلاً ميتاً — ميزانية نشطة بلغ
     * استهلاكها الفعلي حدَّها تُنبّه المراقبين (مرة كل ٧ أيام لكل ميزانية).
     */
    protected function budgetsAuto(): int
    {
        $hits = 0;
        try {
            $budgets = DB::table('budgets')->whereNull('deleted_at')
                ->where('status', 'نشطة')->whereNotNull('alert_pct')->where('alert_pct', '>', 0)
                ->where('amount', '>', 0)->limit(300)->get();
            foreach ($budgets as $b) {
                $ba = hub_budget_actual($b);
                if ($ba['pct'] === null || $ba['pct'] < (int) $b->alert_pct) continue;
                $dup = HubNotification::where('kind', 'budget:' . $b->id)
                    ->where('created_at', '>=', now()->subDays(7))->exists();
                if ($dup) continue;
                $hits++;
                if (! $this->dry) {
                    // **التنطيقُ لكل مستلمٍ على حدة** (v2.337): كانت الميزانياتُ
                    // تُقرأ بلا نطاق ويُوزَّع نصُّها — اسمُ الميزانية ومبلغُها
                    // ونسبتُها — على **كل** مالكٍ وحاملِ علم مراقبة، فيقرأ مراقبُ
                    // شركةٍ ميزانيةَ شركةٍ أخرى. والحارسُ نفسُه قائمٌ في
                    // `notifyMonitors` بهذا الملف، وهذا المسارُ لا يمرّ به.
                    foreach ($this->recipientUsers(null) as $ru) {
                        if (! hub_scope(DB::table('budgets')->whereNull('deleted_at')
                            ->where('id', $b->id), 'budgets', $ru)->exists()) continue;

                        HubNotification::create([
                            'user_id' => $ru->id, 'kind' => 'budget:' . $b->id,
                            'text' => Str::limit("📊 الميزانية «{$b->name}» بلغت {$ba['pct']}٪ من مخصصها"
                                . ' (' . number_format($ba['spent'], 2) . ' من ' . number_format($ba['amount'], 2) . ')', 590),
                            'module' => 'budgets', 'record_id' => $b->id,
                            'read' => false, 'created_at' => now(),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            report($e);   // تنبيهات الميزانية لا تُسقط بقية المحرك
        }

        return $hits;
    }

    /* ───── 1) المصروفات المتكررة ───── */
    protected function recurring(): array
    {
        $docs = 0; $manual = 0;
        $cycles = ['شهري' => 1, 'ربع سنوي' => 3, 'نصف سنوي' => 6, 'سنوي' => 12];

        $due = RecurringDoc::whereNull('deleted_at')
            ->where('status', 'مفعّل')
            ->whereNotNull('next')
            ->whereDate('next', '<=', today())
            ->get();

        foreach ($due as $rec) {
          try {
            $months = $cycles[$rec->cycle] ?? 1;
            $guard  = 0;

            // التعويض عن مواعيد فائتة (بحد أقصى 24 دورة)
            while ($rec->next && Carbon::parse($rec->next)->lte(today()) && $guard++ < 24) {
                $onDate = substr((string) $rec->next, 0, 10);

                if ($rec->auto_post) {
                    $doc = null;
                    if (! $this->dry) {
                        $doc = FinDocument::create([
                            'doc_no'     => FinDocument::nextRecurringNo(),   // متسلسلٌ فريدٌ لا عشوائيٌّ يتصادم
                            'kind'       => $rec->kind ?: 'مصروف',
                            'partner'    => $rec->partner,
                            'date'       => $onDate,
                            'due'        => $onDate,
                            'amount'     => $rec->amount,
                            'tax'        => 0,
                            'total'      => $rec->amount,
                            'currency'   => $rec->currency,
                            'project_id' => $rec->project_id,
                            'company_id' => $rec->company_id,
                            // الأبعاد كاملة: كانت تضيع عند التوليد (البند يُحشر نصاً في الوصف)
                            // فتعمى تقارير البنود ومراكز التكلفة عن كل المولَّد آلياً
                            'cc_id'      => $rec->cc_id,
                            'cat'        => $rec->cat,
                            'method'     => $rec->method,
                            'description'=> 'وُلّد تلقائياً من المتكرر: ' . $rec->name,
                        ]);
                    }
                    $docs++;
                    $this->notifyMonitors('recur',
                        "توليد تلقائي: {$rec->name} — " . number_format((float) $rec->amount, 2) . ' ' . ($rec->currency ?: ''),
                        'fin', $doc?->id);
                } else {
                    $manual++;
                    $this->notifyMonitors('recur-manual',
                        "مستحق ({$onDate}): {$rec->name} — أنشئ المستند يدوياً",
                        'recur', $rec->id);
                }

                // NoOverflow: مرساةُ ٣١ يناير كانت تقفز فبراير (addMonths يفيض
                // إلى ٣ مارس) وتنجرف للأبد — نفس صنف عيب التقارير المُصلَح
                $rec->next = Carbon::parse($rec->next)->addMonthsNoOverflow($months)->toDateString();

                // **حفظُ next مع كل دورة لا بعد الحلقة كلها**: كانت الفواتير
                // تُلتزم فوراً وnext يُحفظ مرةً واحدة في النهاية — فعطلٌ في الدورة
                // الرابعة يترك الثلاث المُنشأة بمؤشّرٍ قديم فتُعاد. الآن كل دورةٍ
                // تُقدّم المؤشّر فورَ إنشائها فلا إعادةَ توليد.
                if (! $this->dry) $rec->saveQuietly();
            }
          } catch (\Throwable $e) {
              // متكرّرٌ واحدٌ لا يوقف البقية، وعطلُه يُبلَّغ لا يُبتلع
              report($e);
          }
        }

        return ['docs' => $docs, 'manual' => $manual];
    }

    /* ───── 2) قواعد التنبيه ───── */
    // ── Control Plane: Phase 6 (WP-6.3) ── الجوهرُ استُخرج إلى App\Support\AlertEngine
    // **بلا تغيير سلوك** (الصلاحيةُ قبل النطاق، التنطيقُ لكل مستلمٍ قبل الحدّ،
    // ترقيمٌ بمؤشّر المعرّف، التصعيد) — يستدعيه هذا الأمرُ اليوميّ هنا، ويستدعي
    // `hub:alerts-evaluate` سكّتَه النافذية كلَّ ٥ دقائق.
    protected function alertRules(): array
    {
        return (new \App\Support\AlertEngine($this->dry, fn ($m) => $this->line($m)))->daily();
    }


    /** المستلمون: المحدد في القاعدة، وإلا المالكون + حاملو علم monitor */
    protected function recipients($toId): array
    {
        return $this->recipientUsers($toId)->pluck('id')->values()->all();
    }

    /** المستلمون كنماذج مستخدمين — يلزمنا المستخدم نفسه لفرض نطاقه على القاعدة */
    protected function recipientUsers($toId): \Illuminate\Support\Collection
    {
        if ($toId) return User::whereNull('deleted_at')->where('id', $toId)->with('role')->get()->values();

        // with('role') وذاكرةُ التشغيلة الواحدة: كان كلُّ نداءٍ يحمّل كلَّ
        // المستخدمين ثم دورَ كلٍّ باستعلامٍ مستقل (N+1) — مع كل إشعارٍ مولَّد.
        // تُصفَّر في مطلع handle() فلا تتلوث تشغيلاتُ العملية الواحدة.
        return $this->monitorUsers ??= User::whereNull('deleted_at')->with('role')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'monitor'))
            ->values();
    }

    protected ?\Illuminate\Support\Collection $monitorUsers = null;

    /**
     * إشعار للمالكين وحاملي monitor — **منطَّقاً لكلّ مستلم** (v2.316).
     *
     * كان يُرسَل للجميع بلا `hub_scope`، بخلاف `alertRules` في الملف نفسه الذي
     * ينطّق لكلّ مستلم على حدة. فمراقبُ شركةٍ يُشعَر بعقود شركةٍ أخرى **وبأسمائها
     * في نصّ الإشعار** — تسريبٌ يعبر حدَّ العزل من مسارٍ آليّ لا يراه أحد.
     * والتنطيقُ لا يُخرس شيئاً: غيرُ المحدود (المالك) يمرّ كما كان، ومَن لا
     * يُعرَف للسجلِّ وحدةٌ أو معرّف يُشعَر كما كان (لا سجلَّ يُقاس عليه).
     */
    protected function notifyMonitors(string $kind, string $text, ?string $module, ?string $recordId): void
    {
        if ($this->dry) return;

        $md = $module ? hub_mod($module) : null;
        foreach ($this->recipientUsers(null) as $u) {
            if ($md && $recordId && ! hub_scope(
                DB::table($md['table'])->whereNull('deleted_at')->where('id', $recordId), $module, $u)->exists()) {
                continue;   // السجلُّ خارج نطاق هذا المستلم — لا يُشعَر باسمه
            }
            HubNotification::create([
                'user_id'    => $u->id,
                'kind'       => $kind,
                'text'       => Str::limit($text, 590),
                'module'     => $module,
                'record_id'  => $recordId,
                'read'       => false,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * الالتزام المتجاوز استحقاقَه يُقلب «متأخر» آلياً — كانت الحالة موثّقةً في
     * الهجرة ومبذورةً قاعدةَ تنبيهٍ ومسارَ عملٍ («🔴 التزام تعاقدي متأخر»)
     * ولا شيء في النظام يكتبها: حبرٌ على ورق لا يُطلق تنبيهاً أبداً.
     */
    protected function obligationsAuto(): int
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('contract_obligations')) return 0;
            $due = \App\Models\ContractObligation::where('status', 'قائم')
                ->whereNotNull('due')->where('due', '<', now()->toDateString())->get();
            if ($this->dry) return $due->count();

            $n = 0;
            foreach ($due as $ob) {
                $ob->forceFill(['status' => 'متأخر'])->save();
                // عبر النموذج لا update جماعي: مسارات «متأخر» وقواعده المبذورة تُطلَق
                \App\Support\FlowRunner::fire('status', 'obligations', $ob, 'متأخر');
                $n++;
            }

            return $n;
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }

    /**
     * تقليم جرس الإشعارات: كان notifications_hub يتراكم بلا حذفٍ إطلاقاً بينما
     * الجرسُ يَعُدّ عليه في كل تحميل صفحة — المقروء يذهب بعد ٩٠ يوماً،
     * وكلُّ شيء بعد سنة (الأثر الدائم في سجل التدقيق لا هنا).
     */
    protected function pruneNotifications(): int
    {
        try {
            if ($this->dry) return 0;

            // عدّادٌ لكل جدولٍ على حدة — فالتقليم ليس صامتاً (§13): في آخر
            // التشغيلة يُكتب سطرُ تدقيقٍ واحدٌ لكل جدولٍ قُلّم فعلاً بعدده.
            $per = [];
            // مدّتا الجرس من الإعدادات لا من الشيفرة (WP-2.3 · critic #16) — بافتراضيّي
            // الأمس حرفياً: المقروءُ ٩٠ يوماً، وكلُّ شيءٍ (غيرُ المقروء المُهمَل) بعد سنةٍ
            // على الأقل مهما صغُر المفتاح — الأثرُ الدائم في سجل التدقيق لا هنا.
            $nKeep = max(7, (int) setting('retention.notifications_days', 90));
            $n = $per['notifications_hub'] = HubNotification::where('read', true)->where('created_at', '<', now()->subDays($nKeep))->delete()
               + HubNotification::where('created_at', '<', now()->subDays(max(365, $nKeep)))->delete();

            // **وسجلُّ الويبهوك الوارد** (v2.324): سطحٌ عامّ يكتب صفّاً لكل نداء
            // بحمولته، وكان بلا تقليمٍ إطلاقاً — نموٌّ غيرُ محدود يملأ القرص.
            if (\Illuminate\Support\Facades\Schema::hasTable('inbound_hook_events')) {
                $n += $per['inbound_hook_events'] = DB::table('inbound_hook_events')
                    ->where('created_at', '<', now()->subDays(max(7, (int) setting('retention.inbound_hooks_days', 90))))->delete();
            }

            // **ونقاطُ المقاييس** (v2.342): فحصُ التوافر يكتب نقطةً لكل موقعٍ
            // وسيرفرٍ **كل خمس دقائق** — أي مئاتُ الآلاف سنويّاً — وكان الجدولُ
            // الوحيدَ بلا تقليمٍ بين أربعةٍ شقيقة. والنظامُ يُرفع على استضافةٍ
            // مشتركة بقرصٍ محدود. سنةٌ كاملة تكفي لكل رسمٍ زمنيّ في الشاشات.
            // والمدّةُ من مفتاح retention.metric_points_days (WP-2.3 · critic #16)
            // بعدما ضاعف الطورُ الثاني الحجمَ (لقطاتُ ops كلَّ ٥ دقائق + تاريخُ النبضات).
            // **وتاريخُ تشغيل المجدولات** ('ops', <job>, 'run') الذي تكتبه Health::beat
            // تليمترٌ عمرُه سنةٌ **عمداً** (critic #23): اتجاهُ المدّة والفشل المتتالي
            // يكفيهما ذلك — الأثرُ الدائم في سجل التدقيق، لا صفوفُ قياسٍ تعيش للأبد.
            // والحذفُ **على دفعاتٍ** (نمطُ page_visits · critic #28): مسحةٌ واحدة على
            // جدولٍ بلغ مئاتِ الآلاف كانت تقفله طويلاً تحت محرّكٍ صارم.
            if (\Illuminate\Support\Facades\Schema::hasTable('metric_points')) {
                $mpKeep = max(30, (int) setting('retention.metric_points_days', 365));
                $per['metric_points'] = 0;
                do {
                    $gone = DB::table('metric_points')
                        ->where('at', '<', now()->subDays($mpKeep)->toDateTimeString())
                        ->limit(5000)->delete();
                    $n += $gone; $per['metric_points'] += $gone;
                } while ($gone >= 5000);
            }

            // **وزياراتُ الصفحات** (v2.350): كان التشذيبُ يقع **داخل طلب المستخدم**
            // (فرصةُ ١٪) — حذفٌ على عمودٍ بلا فهرسٍ في أثناء تحميل صفحة. نُقل هنا
            // بجوار إخوته، على دفعاتٍ محدودة كي لا يقفل الجدولَ طويلاً.
            // عدّاداتُ استخدام API: ٩٠ يوماً تكفي للتحليل (v2.399)
            $per['api_usage'] = \App\Support\Api::pruneUsage((int) setting('api.usage_keep_days', 90));

            // **سياسةُ احتفاظٍ لسجلات التشغيل** (v2.399) — كانت بلا سقفٍ إطلاقاً:
            // الصندوقُ الصادر المُسلَّم، وتسليماتُ الويبهوك الفاشلة، والأخطاءُ المحلولة أو البائتة.
            // (سلسلةُ التدقيق لا يقلّمها هذا الأمر ولا غيرُه: سياسةُ احتفاظها وصفيّةٌ
            // معلَنة — audit.retention_days للأبد افتراضاً، ولا كودَ تقليمٍ لها عمداً · ق٦.)
            // المدَدُ من الإعدادات لا من الشيفرة.
            if (\Illuminate\Support\Facades\Schema::hasTable('outbox')) {
                $n += $per['outbox'] = DB::table('outbox')->whereIn('state', ['sent', 'failed'])
                    ->where('created_at', '<', now()->subDays(max(30, (int) setting('retention.outbox_days', 180))))->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('webhook_deliveries')) {
                $n += $per['webhook_deliveries'] = DB::table('webhook_deliveries')->where('state', 'failed')
                    ->where('created_at', '<', now()->subDays(max(14, (int) setting('retention.webhook_failed_days', 90))))->delete();
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('error_events')) {
                $keep = max(30, (int) setting('retention.errors_days', 180));
                $per['error_events'] = DB::table('error_events')->where('status', 'محلول')->where('last_seen', '<', now()->subDays($keep))->delete();
                // الصفُّ العمريّ (ضعفُ الاحتفاظ) كان يحذف **كلَّ شيء** بصرف النظر
                // عن الحالة — فيختفي عطلٌ حرجٌ مفتوح صامتاً (§13: لا تقليمَ أدلةٍ
                // إنتاجيةٍ صامتاً). الآن يُقلَّم بالعمر ما دون HIGH شدّةً فقط
                // (قيمُ ErrorTaxonomy المخزّنة كما هي)؛ المفتوحُ HIGH/CRITICAL —
                // وكذلك القديمُ غيرُ المصنَّف (severity فارغة: صفوفٌ سبقت أعمدةَ
                // التصنيف، وقد تكون حرجة) — يبقى حتى يُحلّ فيلتقطه الصفُّ الأول.
                $per['error_events'] += DB::table('error_events')
                    ->where('last_seen', '<', now()->subDays($keep * 2))
                    ->whereIn('severity', ['INFO', 'WARNING', 'ERROR'])->delete();
                $n += $per['error_events'];
            }

            // ── Control Plane: Phase 3 (WP-3.2) ──
            // **وعيّناتُ وقوع الأخطاء** (error_occurrences): سقفَ الصفوف لكل حدثٍ
            // يفرضه الكاتبُ نفسُه (ErrorLog::occurrence)، وهذا مقصُّ العمر —
            // عيّنةٌ أقدمُ من retention.error_occurrences_days لا تفيد التشخيص،
            // والأثرُ الباقي هو الحدثُ المجمَّع أعلاه. حذفٌ على دفعاتٍ (نمطُ
            // page_visits) يقوده فهرسُ (occurred_at) فلا مسحَ كاملاً.
            if (\Illuminate\Support\Facades\Schema::hasTable('error_occurrences')) {
                $oKeep = max(7, (int) setting('retention.error_occurrences_days', 30));
                $per['error_occurrences'] = 0;
                do {
                    $gone = DB::table('error_occurrences')
                        ->where('occurred_at', '<', now()->subDays($oKeep)->toDateTimeString())
                        ->limit(5000)->delete();
                    $n += $gone; $per['error_occurrences'] += $gone;
                } while ($gone >= 5000);
            }
            if (\Illuminate\Support\Facades\Schema::hasTable('page_visits')) {
                // ── Control Plane: Phase 7 (WP-7.4) ── الزياراتُ وحدَها كانت بثابتِ
                // ٩٠ في الشيفرة بينما إخوتُها بمفاتيحَ معلَنة — retention.visits_days
                // (spec §13)، بحدٍّ أدنى ٣٠: مقصٌّ أقصرُ من شهرٍ يُفقد أثرَ التحقيق
                // الأمنيّ قبل أن يُفتح. الافتراضيُّ ٩٠ حرفياً فلا يتغيّر سلوكُ أحد.
                $vKeep = max(30, (int) setting('retention.visits_days', 90));
                $per['page_visits'] = 0;
                do {
                    $gone = DB::table('page_visits')->where('at', '<', now()->subDays($vKeep))
                        ->limit(5000)->delete();
                    $n += $gone; $per['page_visits'] += $gone;
                } while ($gone >= 5000);
            }

            // **ورادارُ الكشف** (v2.356): كل ٤٠٣ أو تخمينِ رابطٍ يكتب صفّاً — سطحٌ
            // قد يفيض تحت طرقٍ متعمَّد. حدٌّ زمنيٌّ وسقفٌ صلبٌ معاً كإخوته أعلاه.
            $n += $per['access_denials'] = \App\Support\SecurityRadar::prune();

            // **وقطعُ الرفعات المهجورة**: اتصالٌ انقطع في منتصف رفعةٍ مقطَّعة يترك
            // نصفَ ملفٍ على القرص. تُكنَس عند كل إنهاء رفعةٍ أيضاً، وهذه شبكةُ
            // أمانٍ ليوم لا يُنهي فيه أحدٌ رفعةً أصلاً.
            $n += $per['chunk_files'] = \App\Support\ChunkedUpload::prune();

            // **وكاشُ الاستكشاف البائت**: باركود سُئل عنه المزوّدون قبل أشهرٍ
            // طويلة لا يستحق صفاً — إعادةُ مسحِه تسألهم من جديد فتتجدد إجابتُه.
            if (\Illuminate\Support\Facades\Schema::hasTable('identity_lookups')) {
                $n += $per['identity_lookups'] = \App\Support\Discovery\Engine::prune();
            }

            // **سياسة احتفاظٍ لبيانات الأمن** (v2.369): سجلُّ الجلسات وألفةُ العناوين
            // لا يُحتفَظ بهما للأبد — بياناتُ تتبّعٍ حسّاسةٌ تُقلَّم بمدّةٍ مضبوطة.
            // (سلسلةُ التدقيق خارج كل تقليم: كشفُ العبث يحتاج التاريخَ كلَّه —
            // سياسةُ احتفاظها وصفيّةٌ معلَنة في audit.retention_days، للأبد افتراضاً · ق٦.)
            $sessKeep = max(30, (int) setting('security.sessions_keep_days', 180));
            if (\Illuminate\Support\Facades\Schema::hasTable('sessions_log')) {
                $n += $per['sessions_log'] = \Illuminate\Support\Facades\DB::table('sessions_log')
                    ->where('last_seen_at', '<', now()->subDays($sessKeep))->where('revoked', true)->delete();
            }
            $ipKeep = max(30, (int) setting('security.ip_keep_days', 365));
            if (\Illuminate\Support\Facades\Schema::hasTable('user_ips')) {
                $n += $per['user_ips'] = \Illuminate\Support\Facades\DB::table('user_ips')
                    ->where('last_seen_at', '<', now()->subDays($ipKeep))->delete();
            }

            // **ونقاطُ المسار الخام** (v2.371): سياسةُ خصوصيةٍ صريحة — الإحداثيات
            // الدقيقة لا تبقى للأبد؛ المسارُ المبسَّط على الجلسة يكفي للتاريخ.
            $n += $per['track_points'] = \App\Support\Tracking::prune();

            // ── Control Plane: Phase 2 (WP-2.2) ──
            // **ودلاءُ قياس HTTP**: صفٌّ لكل (حاوية ٥ دقائق × سطح × فعل × مسار)
            // يكتبه Observability::terminate مع كل طلب — تليمتريا لا سجلُّ أعمال،
            // و٩٠ يوماً تكفي لكل اتجاهٍ تعرضه شاشاتُ RED. الحذفُ على دفعاتٍ
            // (نمطُ page_visits) كي لا يُقفَل جدولٌ ساخنٌ يكتب فيه كلُّ طلبٍ حيّ؛
            // والفريدُ (bucket_at, …) يقود شرطَ bucket_at وحدَه فلا مسحَ كاملاً.
            if (\Illuminate\Support\Facades\Schema::hasTable('http_metric_buckets')) {
                $keep = max(7, (int) setting('retention.http_buckets_days', 90));
                $per['http_metric_buckets'] = 0;
                do {
                    $gone = DB::table('http_metric_buckets')
                        ->where('bucket_at', '<', now()->subDays($keep)->toDateTimeString())
                        ->limit(5000)->delete();
                    $n += $gone; $per['http_metric_buckets'] += $gone;
                } while ($gone >= 5000);
            }

            // أثرُ التقليم (§13): لا حذفَ صامتاً — سطرُ تدقيقٍ واحدٌ لكل جدولٍ
            // قُلّم فعلاً، بعدد المحذوف. يعمل بلا مستخدمٍ مسجَّل (console):
            // hub_audit تترك user_id فارغاً كما في أمر hub:audit-verify.
            // والتشغيلةُ النظيفة (لا محذوف) لا تكتب شيئاً.
            foreach ($per as $table => $gone) {
                if ((int) $gone > 0) hub_audit('تقليم احتفاظ', null, null, $table . ': ' . (int) $gone . ' صف');
            }

            return $n;
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }
}
