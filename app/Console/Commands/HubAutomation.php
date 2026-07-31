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
        $this->dry = (bool) $this->option('dry');
        if ($this->dry) $this->warn('وضع المعاينة — لن يُكتب شيء');

        $g = $this->recurring();
        $a = $this->alertRules();
        $e = $this->esignReminders();

        $this->info("المتكررات: {$g['docs']} مستند مولّد، {$g['manual']} تذكير يدوي · القواعد: {$a['hits']} تنبيه ({$a['rules']} قاعدة)، {$a['outbox']} رسالة صادرة · توقيعات: {$e} تذكير");

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
                            'description'=> 'وُلّد تلقائياً من المتكرر: ' . $rec->name . ($rec->cat ? ' — ' . $rec->cat : ''),
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

                $rec->next = Carbon::parse($rec->next)->addMonths($months)->toDateString();
            }

            if (! $this->dry) $rec->saveQuietly();   // تقديم الموعد بلا ضجيج تدقيق
        }

        return ['docs' => $docs, 'manual' => $manual];
    }

    /* ───── 2) قواعد التنبيه ───── */
    protected function alertRules(): array
    {
        $hits = 0; $rulesRun = 0; $outbox = 0;

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
            match ($rule->op) {
                'أكبر من'               => $q->where($col, '>', (float) $v),
                'أصغر من'               => $q->where($col, '<', (float) $v),
                'يساوي'                 => $q->where($col, $v),
                'يحتوي'                 => $q->where($col, 'LIKE', "%{$v}%"),
                'فارغ'                  => $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
                'أيام متبقية أقل من'    => $q->whereNotNull($col)->whereDate($col, '<=', today()->addDays((int) $v)),
                'أيام مضت أكثر من'      => $q->whereNotNull($col)->whereDate($col, '<=', today()->subDays((int) $v)),
                default                 => $q->whereRaw('1=0'),
            };

            $disp = hub_display_col($rule->mod);
            $rows = $q->limit(50)->get(['id', $disp . ' as _n']);
            if ($rows->isEmpty()) continue;
            $rulesRun++;

            $every = max(1, (int) ($rule->every ?: 7));
            $to    = $this->recipients($rule->to_id);

            foreach ($rows as $row) {
                // منع التكرار: نفس القاعدة ونفس السجل خلال «كل N يوم»
                $dup = HubNotification::where('kind', 'rule:' . $rule->id)
                    ->where('record_id', $row->id)
                    ->where('created_at', '>=', now()->subDays($every))
                    ->exists();
                if ($dup) continue;

                $text = trim(($rule->msg ?: $rule->name) . ' — ' . Str::limit((string) $row->_n, 60));
                $hits++;

                foreach ($to as $uid) {
                    if ($this->dry) continue;
                    HubNotification::create([
                        'user_id'   => $uid,
                        'kind'      => 'rule:' . $rule->id,
                        'text'      => Str::limit($text, 590),
                        'module'    => $rule->mod,
                        'record_id' => $row->id,
                        'read'      => false,
                        'created_at'=> now(),
                    ]);
                }

                $chan = (string) $rule->chan;
                foreach (['تلجرام' => 'tg', 'بريد' => 'mail'] as $word => $ch) {
                    if (str_contains($chan, $word) || str_contains($chan, 'الكل')) {
                        $outbox++;
                        if (! $this->dry) OutboxMessage::create([
                            'user_id'    => $to[0] ?? null,
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

        return ['hits' => $hits, 'rules' => $rulesRun, 'outbox' => $outbox];
    }

    /** المستلمون: المحدد في القاعدة، وإلا المالكون + حاملو علم monitor */
    protected function recipients($toId): array
    {
        if ($toId) return [$toId];

        return User::whereNull('deleted_at')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'monitor'))
            ->pluck('id')->values()->all();
    }

    /** إشعار للمالكين وحاملي monitor */
    protected function notifyMonitors(string $kind, string $text, ?string $module, ?string $recordId): void
    {
        if ($this->dry) return;
        foreach ($this->recipients(null) as $uid) {
            HubNotification::create([
                'user_id'    => $uid,
                'kind'       => $kind,
                'text'       => Str::limit($text, 590),
                'module'     => $module,
                'record_id'  => $recordId,
                'read'       => false,
                'created_at' => now(),
            ]);
        }
    }
}
