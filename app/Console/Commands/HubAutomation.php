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
    protected $description = 'توليد المستندات المتكررة وتقييم قواعد التنبيه';

    protected bool $dry = false;

    public function handle(): int
    {
        $this->monitorUsers = null;   // ذاكرة المستلمين تصلح لتشغيلةٍ واحدة
        $this->dry = (bool) $this->option('dry');
        if ($this->dry) $this->warn('وضع المعاينة — لن يُكتب شيء');

        $g = $this->recurring();
        $a = $this->alertRules();
        $e = $this->esignReminders();
        $c = $this->contractsAuto();
        $b = $this->budgetsAuto();
        $o = $this->obligationsAuto();
        $p = $this->pruneNotifications();

        $this->info("المتكررات: {$g['docs']} مستند مولّد، {$g['manual']} تذكير يدوي · القواعد: {$a['hits']} تنبيه ({$a['rules']} قاعدة)، {$a['esc']} مُتصاعد، {$a['outbox']} رسالة صادرة · توقيعات: {$e} تذكير · عقود: {$c['expired']} انتهاء، {$c['drafts']} مسودة تجديد · ميزانيات: {$b} تنبيه · التزامات: {$o} متأخر · إشعارات: {$p} مُقلَّم");

        \App\Models\Setting::updateOrCreate(['key' => 'heartbeat.automation'], ['value' => now()->toIso8601String()]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        return self::SUCCESS;
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
                    foreach ($this->recipients(null) as $uid) {
                        HubNotification::create([
                            'user_id' => $uid, 'kind' => 'budget:' . $b->id,
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
                            'doc_no'     => 'REC-' . now()->format('ym') . '-' . strtoupper(Str::random(4)),
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
    protected function alertRules(): array
    {
        $hits = 0; $rulesRun = 0; $outbox = 0; $esc = 0;

        $rules = AlertRule::whereNull('deleted_at')->where('status', 'مفعّلة')->get();

        foreach ($rules as $rule) {
            $md = hub_mod($rule->mod);
            if (! $md) continue;

            // الحقل: مفتاح من تعريف الوحدة أو اسم عمود مباشر
            $col = collect($md['fields'])->firstWhere('key', $rule->field)['col']
                ?? (Schema::hasColumn($md['table'], (string) $rule->field) ? $rule->field : null);
            if (! $col) { $this->line("تخطٍ: {$rule->name} — حقل غير معروف"); continue; }

            $q = DB::table($md['table'])->whereNull('deleted_at');
            $v = (string) $rule->val;
            // مقارنة عمود بعمود: القيمة اسم حقلٍ من الوحدة نفسها (قائمة بيضاء من سجلها)
            // — بها يحيا «حد إعادة الطلب» لكل صنف و«حد التنبيه» لكل صندوق
            $vcol = fn () => collect($md['fields'])->firstWhere('key', $v)['col']
                ?? (Schema::hasColumn($md['table'], $v) ? $v : null);
            match ($rule->op) {
                'أكبر من'               => $q->where($col, '>', (float) $v),
                'أصغر من'               => $q->where($col, '<', (float) $v),
                'أكبر من عمود'          => ($c2 = $vcol()) ? $q->whereNotNull($c2)->whereColumn($col, '>', $c2) : $q->whereRaw('1=0'),
                'أصغر من عمود'          => ($c2 = $vcol()) ? $q->whereNotNull($c2)->whereColumn($col, '<', $c2) : $q->whereRaw('1=0'),
                'يساوي'                 => $q->where($col, $v),
                'يحتوي'                 => $q->where($col, 'LIKE', "%{$v}%"),
                'فارغ'                  => $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
                'أيام متبقية أقل من'    => $q->whereNotNull($col)->whereDate($col, '<=', today()->addDays((int) $v)),
                'أيام مضت أكثر من'      => $q->whereNotNull($col)->whereDate($col, '<=', today()->subDays((int) $v)),
                default                 => $q->whereRaw('1=0'),
            };

            $disp = hub_display_col($rule->mod);
            $every = max(1, (int) ($rule->every ?: 7));

            // **مانعُ التكرار داخل SQL قبل الحدّ** (v2.316): كان `limit(50)` بلا
            // ترتيب، ثمّ يُطبَّق مانعُ التكرار والتنطيق في PHP **بعد** القصّ. فمتى
            // تجاوز المستحقّون خمسين، عادت الخمسون نفسُها كلَّ يوم ثمّ سقطت كلُّها
            // بمانع التكرار، و**الباقي لا يُجلَب أبداً**: تجويعٌ دائم — والقاعدةُ
            // تبدو ناجحةً في كلّ قياس، وهو فشلٌ يُنتج ثقةً كاذبة. وترتيبٌ حتميّ
            // بـ`id` كي لا تختلف الخمسون بين المحرّكين.
            $q->whereNotIn('id', HubNotification::where('kind', 'rule:' . $rule->id)
                ->whereNotNull('record_id')
                ->whereDate('created_at', '>=', today()->subDays($every))
                ->distinct()->pluck('record_id')->all());

            $rows = $q->orderBy('id')->limit(50)->get(['id', $disp . ' as _n']);
            if ($rows->isEmpty()) continue;
            $rulesRun++;
            // عتبةُ التصعيد: مشكلةٌ ظلّت تُطلِق القاعدة أطولَ من ثلاث دوراتٍ كاملة
            // «قيلَ لك ولم تُحلّ» — تُدفَع عبر كل القنوات ويُوسَم إشعارُها مُتصاعداً.
            // قابلةٌ للضبط عالميّاً (notify.escalate_after)، وإلا تكيّفٌ مع دورية القاعدة.
            $escAfter    = (int) setting('notify.escalate_after', 0);
            $escalateAge = $escAfter > 0 ? $escAfter : max(3, $every * 3);
            $to    = $this->recipientUsers($rule->to_id);

            // النطاق يُفرض لكل مستلم على حدة — القاعدة لا تُسرّب عنوان سجل خارج نطاقه
            $rowIds = $rows->pluck('id')->all();
            $visible = [];
            foreach ($to as $ru) {
                $visible[$ru->id] = hub_scope(
                    DB::table($md['table'])->whereNull('deleted_at')->whereIn('id', $rowIds),
                    $rule->mod, $ru)->pluck('id')->map(fn ($i) => (string) $i)->flip()->all();
            }

            foreach ($rows as $row) {
                $canSee = $to->filter(fn ($ru) => isset($visible[$ru->id][(string) $row->id]));
                if ($canSee->isEmpty()) continue;

                // منع التكرار: نفس القاعدة ونفس السجل خلال «كل N يوم».
                // whereDate لا نافذة datetime: الإشعار يُختم now() (دقّة ثانية)
                // والفحص كان now()-N days بالضبط — فانزياحُ الكرون ثوانٍ يُخرج
                // إشعار الأمس من النافذة فتُعيد قواعد every=1 الإطلاق يوميّاً.
                $dup = HubNotification::where('kind', 'rule:' . $rule->id)
                    ->where('record_id', $row->id)
                    ->whereDate('created_at', '>=', today()->subDays($every))
                    ->exists();
                if ($dup) continue;

                // منذ متى ونحن نطلق على هذه المشكلة بعينها؟ أقدمُ إشعارٍ لنفس
                // القاعدة والسجل هو ختمُ أوّل رصدٍ لها؛ تجاوزُه عتبةَ التصعيد يعني
                // أنها لم تُحلّ رغم الإبلاغ — فيرتفع الإلحاح ويُدفَع عبر القنوات.
                // بدايةُ السلسلة المتّصلة: نمشي الإشعارات من الأحدث، وأيُّ فجوةٍ أكبرَ
                // من دورةٍ (every) تعني أنها حُلّت ثم عادت — فتبدأ سلسلةٌ جديدة. (كان
                // min المطلق يجعل نوبةً قديمةً حُلّت تُصعّد النوبةَ الجديدةَ فور عودتها.)
                $stamps = HubNotification::where('kind', 'rule:' . $rule->id)
                    ->where('record_id', $row->id)->orderByDesc('created_at')
                    ->pluck('created_at')->map(fn ($s) => Carbon::parse($s)->startOfDay())->values();
                $chainStart = $stamps->first();
                for ($i = 1; $i < $stamps->count(); $i++) {
                    if ($chainStart->diffInDays($stamps[$i], true) > $every + 1) break;   // فجوةٌ ← انقطاع
                    $chainStart = $stamps[$i];
                }
                $escalated = $chainStart && $chainStart->lte(today()->subDays($escalateAge));
                // مطلقٌ لا موقّع — Carbon 3 يجعل diffInDays موقّعاً افتراضياً (أيامٌ سالبة)
                $days      = $escalated ? (int) $chainStart->diffInDays(today(), true) : 0;

                $base = trim(($rule->msg ?: $rule->name) . ' — ' . Str::limit((string) $row->_n, 60));
                $text = $escalated ? "🔺 مُتصاعد (لم يُعالَج منذ {$days} يوماً): {$base}" : $base;
                $hits++;
                if ($escalated) $esc++;

                foreach ($canSee as $ru) {
                    if ($this->dry) continue;
                    HubNotification::create([
                        'user_id'   => $ru->id,
                        'kind'      => 'rule:' . $rule->id,
                        'text'      => Str::limit($text, 590),
                        'module'    => $rule->mod,
                        'record_id' => $row->id,
                        'read'      => false,
                        'created_at'=> now(),
                    ]);
                }

                // القناة المعتادة، ويفرضها التصعيد جميعاً — المشكلة المزمنة لا
                // تُترَك حبيسةَ الجرس وحده حين تكفّ عن كونها روتيناً يومياً.
                $chan = (string) $rule->chan;
                foreach (['تلجرام' => 'tg', 'بريد' => 'mail'] as $word => $ch) {
                    if ($escalated || str_contains($chan, $word) || str_contains($chan, 'الكل')) {
                        $outbox++;
                        if (! $this->dry) OutboxMessage::create([
                            'user_id'    => $canSee->first()?->id,
                            'kind'       => 'rule:' . $rule->id,
                            'channel'    => $ch,
                            'target'     => null,               // يملؤها عامل التسليم (n8n)
                            'text'       => Str::limit($text, 790),
                            'state'      => 'queued',
                            'created_at' => now(),
                        ]);
                    }
                }
            }
        }

        return ['hits' => $hits, 'rules' => $rulesRun, 'outbox' => $outbox, 'esc' => $esc];
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

            return HubNotification::where('read', true)->where('created_at', '<', now()->subDays(90))->delete()
                 + HubNotification::where('created_at', '<', now()->subDays(365))->delete();
        } catch (\Throwable $e) {
            report($e);
            return 0;
        }
    }
}
