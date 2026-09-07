<?php

namespace App\Support;

use App\Models\AlertRule;
use App\Models\HubNotification;
use App\Models\IpRule;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **محرّكُ التنبيه الواحد** (WP-6.3 · §9) — سكّتان على ذاكرةٍ واحدة:
 *
 * ١) **اليوميّة** (`daily`): قواعدُ الحقول القائمة (حدٌّ/فراغ/أيام متبقية…) —
 *    الجوهرُ منقولٌ حرفياً من `HubAutomation::alertRules` **بلا تغيير سلوك**:
 *    الصلاحيةُ قبل النطاق، والتنطيقُ لكل مستلمٍ قبل الحدّ، وترقيمٌ بمؤشّر
 *    المعرّف، ومانعُ تكرارٍ وتصعيدٌ على ذاكرة `notifications_hub` كما كانا.
 *
 * ٢) **النافذية** (`evaluate` كلَّ ٥ دقائق): قواعدُ «X خلال Y دقيقة» بمصدرٍ
 *    مسمّى (`source`) — فشلُ الدخول، والمنعُ، وتغييرُ الأدوار، وقفلُ الطوارئ،
 *    والأخطاءُ الحرجة، والمجدولاتُ الميتة. إطلاقُها يكتب/يحدّث `alert_instances`
 *    بمفتاح `dedup_key` الفريد: **ذاكرةٌ تنجو من تقليم الإشعارات** — ذاكرةُ
 *    التكرار اليومَ (`notifications_hub.kind='rule:<id>'`) تُقلَّم بعد ٩٠/٣٦٥
 *    يوماً في `pruneNotifications` فيفقد التكرارُ والتصعيدُ ذاكرتَهما صامتَين.
 *
 * حالةُ التنبيه (§9.3): `triggered → acknowledged → resolved` — الإقرارُ الدائم
 * في `alert_instances.acknowledged_by/at` **وحدَه** (ق٣ + critic #5: لا إقرارَ
 * ثانياً في `signal_states`)، والحلُّ آليٌّ حين يزول الشرطُ في التقييم التالي
 * **بقيد «تعافت» لا بحذفٍ صامت**. والتبريدُ لكل قاعدة (`cooldown_min` وإلا
 * `security.alert_cooldown_min`) يمنع طرقَ الجرس كلَّ خمس دقائق لشرطٍ واحد.
 *
 * وكشفُ الحوادث التشغيلية (§3.9): بصماتُ `ops:*` تفتح حادثةً واحدةً عبر
 * `hub_open_incident` (تمرّ بالناقل فتعمل المساراتُ المبذورة)، وعند الشفاء
 * يُلحَق قيدُ «تعافت الخدمة» ولا تُغلق الحادثةُ آلياً أبداً (§13).
 */
class AlertEngine
{
    /** المصادرُ النافذية المسمّاة — سجلّ «rules» في config/hub.php يعرضها خيارات */
    public const SOURCES = [
        'security.failed_logins'  => 'فشلُ الدخول في النافذة — وأيُّ IP يطرق عدّةَ حسابات (رشُّ كلمات مرور)',
        'security.denials'        => 'وصولٌ مرفوض/تخمينُ روابط في النافذة — إجمالاً ولكل IP',
        'security.role_change'    => 'تغييرُ دورٍ أو صلاحياتِ مستخدمٍ في النافذة',
        'security.lockdown'       => 'قفلُ الطوارئ مرفوع',
        'errors.critical_count'   => 'أخطاءٌ حرجة غيرُ محلولة رُئيت في النافذة',
        'health.scheduler'        => 'مجدولةٌ متعطّلة (لم تنبض ضمن نافذتها القصوى)',
    ];

    /** درجةُ Severity القياسية → مفردةُ الحوادث المذكّرة (لنداء hub_open_incident) */
    protected const SEV_AR = ['critical' => 'حرج', 'high' => 'عالي', 'medium' => 'متوسط', 'low' => 'منخفض', 'info' => 'منخفض'];

    /** شدّةٌ افتراضية لكل مصدرٍ حين لا تعلنها القاعدة */
    protected const SOURCE_SEV = [
        'security.failed_logins' => 'high',   'security.denials' => 'medium',
        'security.role_change'   => 'high',   'security.lockdown' => 'critical',
        'errors.critical_count'  => 'critical', 'health.scheduler' => 'high',
    ];

    /**
     * (Work OS · الطور I · WP-I.2 · §41/§68) المصدران اللذان تصلح إشارتُهما
     * لكل-IP (subject=عنوان) تغذيةً للحظر الآليّ — **استهلاكٌ لا اشتقاق**:
     * التجميعُ والعتبةُ والنافذة كلُّها في `fireSource` القائم، وهذا المُطلِقُ
     * لا يعيد عدَّ صفٍّ واحد. سلّمُ التصعيد الافتراضيّ حين يفسد الإعداد.
     */
    protected const AUTOBLOCK_SOURCES = ['security.failed_logins', 'security.denials'];
    protected const AUTOBLOCK_DEFAULT_STEPS = [15, 60, 1440];

    /** ذاكرةُ المستلمين لتشغيلةٍ واحدة (كما كانت في HubAutomation) */
    protected ?\Illuminate\Support\Collection $monitorUsers = null;

    public function __construct(
        protected bool $dry = false,
        /** @var callable|null مُبلِّغُ سطرٍ (يمرّره الأمر لعرض التخطّي) */
        protected $logger = null,
    ) {
    }

    protected function line(string $msg): void
    {
        if ($this->logger) ($this->logger)($msg);
    }

    /* ═════════ ١) السكّة اليومية — منقولةٌ حرفياً من HubAutomation::alertRules ═════════ */

    public function daily(): array
    {
        $hits = 0; $rulesRun = 0; $outbox = 0; $esc = 0;

        $rules = AlertRule::whereNull('deleted_at')->where('status', 'مفعّلة')->get();

        foreach ($rules as $rule) {
            // (WP-6.3) قاعدةٌ نافذية (لها مصدرٌ مسمّى) سكّتُها `evaluate` كلَّ ٥
            // دقائق لا الدورةُ اليومية — القواعدُ الحقلية القديمة لا تحمل source
            // أصلاً فلا يتغيّر سلوكُها حرفاً واحداً.
            if (filled($rule->source ?? null)) continue;

            $md = hub_mod($rule->mod);
            if (! $md) continue;

            // الحقل: مفتاح من تعريف الوحدة أو اسم عمود مباشر
            $fdef = collect($md['fields'])->firstWhere('key', $rule->field);
            $col = $fdef['col']
                ?? (Schema::hasColumn($md['table'], (string) $rule->field) ? $rule->field : null);
            if (! $col) { $this->line("تخطٍ: {$rule->name} — حقل غير معروف"); continue; }
            // نوعُ الحقل يحسم دلالة «فارغ»: على العدديّ والتاريخيّ = NULL وحده،
            // فمقارنةُ '' على عمودٍ رقميّ تُطابق الصفرَ على MySQL (يحوّل '' إلى 0)
            // ولا شيء على SQLite — انقسامٌ صامت. نظيرُ ModuleController:147.
            $ftype = $fdef['type'] ?? null;

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
                'فارغ'                  => in_array($ftype, ['num', 'big', 'date', 'dt'], true)
                                            ? $q->whereNull($col)
                                            : $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
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

            $to = $this->recipientUsers($rule->to_id);

            // **الصلاحيةُ قبل النطاق**: النطاق يُفرض لكل مستلم أدناه، لكن مستلماً
            // في الشركة الصحيحة قد لا يملك رؤيةَ الوحدة أصلاً (راية monitor لا
            // تمنح مصفوفةَ الصلاحيات). فبلا هذا الفحص تصل مبالغُ ميزانياتٍ وأرقامُ
            // فواتير من مُنع من الوحدة — تسريبٌ عبر التنبيه. رايةٌ عامةٌ بلا وحدة
            // (`mod` فارغ) لا تُفحَص كي لا تُخرَس القواعدُ العامة.
            if (filled($rule->mod)) {
                $to = $to->filter(fn ($ru) => $ru->role?->is_owner || hub_can($ru, $rule->mod, 'v'))->values();
            }
            if ($to->isEmpty()) continue;

            /*
             * **والتنطيقُ قبل الحدّ كذلك** (v2.337): v2.316 نقلت مانعَ التكرار
             * إلى SQL قبل `limit(50)` وتركت التنطيقَ **بعده**. فقاعدةٌ موجَّهةٌ
             * إلى مستخدمٍ معزول: خمسون صفّاً خارج نطاقه تملأ النافذة، وتسقط
             * كلُّها عند فحص الرؤية بلا كتابةٍ ولا دخولٍ في سجلّ منع التكرار،
             * فتعود **هي نفسُها** غداً و`orderBy('id')` حتميّ — والسجلُّ المرئيُّ
             * لا يُشعَر عنه أبداً. نفسُ التجويع الذي عولج، من الباب الآخر.
             *
             * فتُقرأ الدفعاتُ بالتتابع ويُرشَّح كلٌّ برؤية المستلمين، حتى تكتمل
             * خمسون **مرئية** أو تنفد المرشَّحات. والسقفُ عشرُ دفعاتٍ كي لا
             * يتحوّل الحارسُ إلى مسحٍ كاملٍ لجدولٍ كبير.
             */
            $rows = collect();
            $cursor = '';
            for ($page = 0; $page < 10 && $rows->count() < 50; $page++) {
                $batch = (clone $q)->where('id', '>', $cursor)
                    ->orderBy('id')->limit(50)->get(['id', $disp . ' as _n']);
                if ($batch->isEmpty()) break;
                $cursor = (string) $batch->last()->id;

                $ids = $batch->pluck('id')->all();
                $seen = [];
                foreach ($to as $ru) {
                    foreach (hub_scope(DB::table($md['table'])->whereNull('deleted_at')->whereIn('id', $ids),
                        $rule->mod, $ru)->pluck('id') as $vid) $seen[(string) $vid] = true;
                }
                $rows = $rows->concat($batch->filter(fn ($r) => isset($seen[(string) $r->id])));
            }
            $rows = $rows->take(50)->values();
            if ($rows->isEmpty()) continue;
            $rulesRun++;
            // عتبةُ التصعيد: مشكلةٌ ظلّت تُطلِق القاعدة أطولَ من ثلاث دوراتٍ كاملة
            // «قيلَ لك ولم تُحلّ» — تُدفَع عبر كل القنوات ويُوسَم إشعارُها مُتصاعداً.
            // قابلةٌ للضبط عالميّاً (notify.escalate_after)، وإلا تكيّفٌ مع دورية القاعدة.
            $escAfter    = (int) setting('notify.escalate_after', 0);
            $escalateAge = $escAfter > 0 ? $escAfter : max(3, $every * 3);

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

    /** المستلمون كنماذج مستخدمين: المحدد في القاعدة، وإلا المالكون + حاملو monitor */
    protected function recipientUsers($toId): \Illuminate\Support\Collection
    {
        if ($toId) return User::whereNull('deleted_at')->where('id', $toId)->with('role')->get()->values();

        return $this->monitorUsers ??= User::whereNull('deleted_at')->with('role')->get()
            ->filter(fn ($u) => $u->role?->is_owner || hub_flag($u, 'monitor'))
            ->values();
    }

    /* ═════════ ٢) السكّة النافذية — كلَّ ٥ دقائق ═════════ */

    /**
     * تقييمُ القواعد النافذية + كشفُ الحوادث التشغيلية (§3.9).
     *
     * @param array|null $health نتيجةُ Health::check() محسوبةً سلفاً (الاختباراتُ
     *   تمرّر حالةً مصطنعة؛ والأمرُ يحسبها مرّةً واحدةً للتشغيلة كلِّها)
     */
    public function evaluate(?array $health = null): array
    {
        $out = ['fired' => 0, 'resolved' => 0, 'incidents' => 0, 'notifs' => 0, 'autoblocks' => 0];
        if (! Schema::hasTable('alert_instances')) return $out;

        $health ??= Health::check();

        $rules = Schema::hasColumn('alert_rules', 'source')
            ? AlertRule::whereNull('deleted_at')->where('status', 'مفعّلة')
                ->whereNotNull('source')->where('source', '!=', '')
                ->orderBy('created_at')->orderBy('id')->get()
            : collect();

        foreach ($rules as $rule) {
            try {
                $fired = $this->fireSource($rule, $health);
            } catch (\Throwable $e) {
                // قاعدةٌ متعثّرة لا تُسقط البقية ولا تحلّ صفوفَها (شرطُها مجهولٌ لا زائل)
                report($e);
                continue;
            }

            $liveKeys = [];
            foreach ($fired as $f) {
                $liveKeys[] = $key = $this->dedupKey($rule, $f['subject'] ?? null);
                $this->upsert($rule, $key, $f, $out);
                // (WP-I.2) الحظرُ الآليّ يستهلك إشارةَ الرشق نفسَها لحظةَ إطلاقها —
                // لا مسحَ ثانٍ ولا عدَّ ثانٍ؛ وعطلُه لا يمسّ التنبيه (معزولٌ داخله)
                $this->autoBlock($rule, $f, $out);
            }
            $this->resolveCleared($rule, $liveKeys, $out);
        }

        $this->detectOps($health, $out);

        return $out;
    }

    /** مفتاحُ عدم التكرار — بعرض عموده (١٩١) حرفياً عند الكاتب */
    protected function dedupKey(AlertRule $rule, ?string $subject): string
    {
        $k = 'alert:' . $rule->source . ':' . $rule->id . ($subject !== null && $subject !== '' ? ':' . $subject : '');

        return mb_substr($k, 0, 191);
    }

    /**
     * إطلاقاتُ مصدرٍ واحد في نافذته: قائمةُ {subject?, title, count} — فارغةٌ = الشرطُ زائل.
     * العتبةُ من `val` (نصُّ القاعدة القائم) وإلا افتراضُ المصدر.
     */
    protected function fireSource(AlertRule $rule, array $health): array
    {
        $win = max(1, (int) ($rule->window_min ?: 60));
        $from = now()->subMinutes($win);
        $thr = max(1, (int) ((float) $rule->val ?: 0) ?: match ($rule->source) {
            'security.failed_logins', 'security.denials' => 5,
            default => 1,
        });

        switch ($rule->source) {
            case 'security.failed_logins':
                $q = DB::table('audits')->whereIn('action', SecurityEvents::actions('AUTH_FAILURE'))
                    ->where('created_at', '>=', $from);
                $fired = [];
                $n = (int) (clone $q)->count();
                if ($n >= $thr) $fired[] = ['subject' => null, 'title' => "{$n} محاولة دخول فاشلة خلال {$win} دقيقة", 'count' => $n];
                // نفسُ العنوان يطرق عدّةَ حسابات = رشُّ كلمات مرور — إطلاقٌ لكل IP
                $ips = (clone $q)->whereNotNull('ip')->where('ip', '!=', '')
                    ->groupBy('ip')->selectRaw('ip, count(*) as n, count(distinct name) as u')
                    ->havingRaw('count(*) >= ?', [$thr])->havingRaw('count(distinct name) >= 2')
                    ->orderBy('ip')->limit(20)->get();
                foreach ($ips as $r) {
                    $fired[] = ['subject' => (string) $r->ip, 'severity' => 'critical',
                        'title' => "العنوان {$r->ip} طرق {$r->u} حسابات بـ{$r->n} محاولة فاشلة خلال {$win} دقيقة", 'count' => (int) $r->n];
                }

                return $fired;

            case 'security.denials':
                $q = DB::table('access_denials')->where('created_at', '>=', $from);
                $fired = [];
                $n = (int) (clone $q)->count();
                if ($n >= $thr) $fired[] = ['subject' => null, 'title' => "{$n} وصولاً مرفوضاً/تخمينَ رابطٍ خلال {$win} دقيقة", 'count' => $n];
                $ips = (clone $q)->whereNotNull('ip')->where('ip', '!=', '')
                    ->groupBy('ip')->selectRaw('ip, count(*) as n')
                    ->havingRaw('count(*) >= ?', [$thr])->orderBy('ip')->limit(20)->get();
                foreach ($ips as $r) {
                    $fired[] = ['subject' => (string) $r->ip,
                        'title' => "العنوان {$r->ip} رُفض {$r->n} مرة خلال {$win} دقيقة", 'count' => (int) $r->n];
                }

                return $fired;

            case 'security.role_change':
                // تغييرُ دور/صلاحيات: الأعمدةُ المخزّنة (ق٢ · الطور ٥) تصنّف الصفوفَ
                // الجديدة — والنافذةُ قصيرةٌ فكلُّ صفوفها جديدةٌ مصنَّفة
                $n = (int) DB::table('audits')->where('created_at', '>=', $from)
                    ->where(fn ($w) => $w->whereIn('category', ['ROLE_CHANGED', 'PERMISSION_CHANGED'])
                        ->orWhere('module', 'roles'))
                    ->count();

                return $n >= $thr
                    ? [['subject' => null, 'title' => "{$n} تغييراً في الأدوار/الصلاحيات خلال {$win} دقيقة", 'count' => $n]]
                    : [];

            case 'security.lockdown':
                return (string) setting('security.lockdown', '0') === '1'
                    ? [['subject' => null, 'title' => 'قفلُ الطوارئ مفعّل — كلُّ من ليس مالكاً مصدود', 'count' => 1]]
                    : [];

            case 'errors.critical_count':
                if (! hub_has_col('error_events', 'severity')) return [];
                $n = (int) DB::table('error_events')->where('severity', 'CRITICAL')
                    ->where('status', '!=', 'محلول')->where('last_seen', '>=', $from)->count();

                return $n >= $thr
                    ? [['subject' => null, 'title' => "{$n} خطأً حرجاً غيرَ محلولٍ رُئي خلال {$win} دقيقة", 'count' => $n]]
                    : [];

            case 'health.scheduler':
                // من نموذج الصحّة المحسوب مرّةً للتشغيلة — «لم تنبض قطّ» مجهولةٌ
                // لا ميتة (تنصيبٌ جديد)، فلا إطلاقَ عليها ولا حلَّ لصفّها
                $fired = [];
                foreach (($health['components']['scheduler']['data']['jobs'] ?? []) as $jk => $j) {
                    if (($j['status'] ?? '') !== Health::UNAVAILABLE) continue;
                    $fired[] = ['subject' => (string) $jk,
                        'title' => 'المجدولة «' . ($j['label'] ?? $jk) . '» متعطّلة — آخرُ نبضةٍ منذ ' . (int) ($j['age_min'] ?? 0) . ' دقيقة', 'count' => 1];
                }

                return $fired;
        }

        $this->line("تخطٍ: {$rule->name} — مصدرٌ غير معروف ({$rule->source})");

        return [];
    }

    /**
     * كتابة/تحديثُ صفّ الذاكرة والإشعارُ بحسب الحالة والتبريد:
     * جديد/عائدٌ بعد حلٍّ ⇒ triggered + إشعار؛ مستمرٌّ ⇒ العدّادُ ينمو ويُعاد
     * الإشعارُ بعد التبريد فقط؛ **مُقَرٌّ به ⇒ لا إشعارَ أبداً** حتى يُحلّ ويعود.
     */
    protected function upsert(AlertRule $rule, string $key, array $f, array &$out): void
    {
        $out['fired']++;
        if ($this->dry) return;

        $now = now();
        $sev = Severity::normalize($rule->severity ?: ($f['severity'] ?? (self::SOURCE_SEV[$rule->source] ?? 'high')));
        if (isset($f['severity'])) $sev = Severity::atLeast($f['severity'], $sev) ? Severity::normalize($f['severity']) : $sev;
        // عرضُ العمود ٣٠٠ — القصُّ عند الكاتب (critic #36): SQLite تبتر صامتةً وMySQL ترمي
        $title = mb_substr((string) $f['title'], 0, 300);

        $row = DB::table('alert_instances')->where('dedup_key', $key)->first();
        $isNew = ! $row;
        $reopened = $row && $row->status === 'resolved';

        if ($isNew) {
            DB::table('alert_instances')->insert([
                'rule_id' => $rule->id, 'dedup_key' => $key,
                'domain' => mb_substr((string) ($rule->domain ?: 'security'), 0, 24),
                'module' => $rule->mod ? mb_substr((string) $rule->mod, 0, 60) : null,
                'subject' => isset($f['subject']) ? mb_substr((string) $f['subject'], 0, 120) : null,
                'severity' => $sev, 'title' => $title, 'status' => 'triggered',
                'first_at' => $now, 'last_at' => $now, 'count' => max(1, (int) ($f['count'] ?? 1)),
                'company_id' => null,
                'request_id' => mb_substr((string) Api::requestId(), 0, 40) ?: null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $upd = ['count' => (int) $row->count + 1, 'last_at' => $now, 'severity' => $sev,
                    'title' => $title, 'updated_at' => $now];
            if ($reopened) {
                // عودةُ الشرط بعد الشفاء: نوبةٌ جديدة على الصفّ نفسِه (الفريدُ ذاكرةٌ
                // دائمة) — إقرارُ النوبة الماضية لا يُسكِت الجديدة
                $upd += ['status' => 'triggered', 'resolved_at' => null,
                         'acknowledged_by' => null, 'acknowledged_at' => null];
            }
            DB::table('alert_instances')->where('id', $row->id)->update($upd);
        }

        // الإقرارُ يوقف تكرارَ الإشعار — العدّادُ فوق ينمو والجرسُ صامت (§9.3)
        if (! $isNew && ! $reopened && $row->status === 'acknowledged') return;

        // التبريد: قاعدةٌ ضبطت cooldown_min وإلا المفتاحُ العام
        $cool = max(1, (int) ($rule->cooldown_min ?: 0) ?: (int) setting('security.alert_cooldown_min', 60));
        if (! $isNew && ! $reopened && $row->notified_at
            && Carbon::parse($row->notified_at)->gt($now->copy()->subMinutes($cool))) return;

        $this->notifyRule($rule, trim(($rule->msg ?: $rule->name) . ' — ' . $title), $out);
        DB::table('alert_instances')->where('dedup_key', $key)->update(['notified_at' => $now]);

        // حادثةٌ آليّة عند أول إطلاق النوبة (auto_incident) — تمرّ بالناقل فتعمل
        // المساراتُ المبذورة، وتكرارُها ببصمة مفتاح التنبيه لا بالعنوان
        if (($isNew || $reopened) && ! empty($rule->auto_incident)) {
            $kind = in_array((string) $rule->domain, ['system', 'error', 'quality', 'execution'], true) ? 'ops' : 'security';
            $inc = hub_open_incident($title, self::SEV_AR[$sev] ?? 'عالي', $kind, 'alert:' . $key,
                ['rule_id' => (string) $rule->id, 'dedup_key' => $key, 'subject' => $f['subject'] ?? null], 24);
            if ($inc) {
                DB::table('alert_instances')->where('dedup_key', $key)->update(['incident_id' => $inc->id]);
                $out['incidents']++;
            }
        }
    }

    /**
     * الحلُّ الآليّ حين يزول الشرط (§9.3) — **بقيدٍ لا بصمت**: الصفُّ يبقى بتاريخه
     * (لا حذفَ أبداً)، والمستلمون يُخبَرون بـ«تعافت»، وإن رُبط بحادثةٍ أُلحق قيدُ
     * الشفاء في خطّها الزمنيّ ولا تُغلق الحادثةُ آلياً (قرارُ إنسان — §13).
     */
    protected function resolveCleared(AlertRule $rule, array $liveKeys, array &$out): void
    {
        if ($this->dry) return;

        $q = DB::table('alert_instances')->where('rule_id', $rule->id)->where('status', '!=', 'resolved');
        if ($liveKeys) $q->whereNotIn('dedup_key', $liveKeys);
        foreach ($q->orderBy('id')->get() as $row) {
            DB::table('alert_instances')->where('id', $row->id)
                ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);
            $out['resolved']++;
            $this->notifyRule($rule,
                '✅ تعافت: زال شرطُ «' . mb_substr((string) $row->title, 0, 120) . '» في التقييم التالي',
                $out, channels: false);
            if ($row->incident_id) {
                $this->appendIncidentNote((string) $row->incident_id,
                    'تعافت الخدمة — زال شرطُ التنبيه: ' . mb_substr((string) $row->title, 0, 180));
            }
        }
    }

    /* ═════════ (Work OS · الطور I · WP-I.2 · §41/§68) الحظرُ الآليّ المتصاعد ═════════ */

    /**
     * **المُطلِقُ الآليّ — يستهلك إشارةَ الرشق القائمة ولا يعيد اشتقاقَها.**
     *
     * يُنادى لكل إطلاقةٍ من `fireSource` لحظةَ خروجها: إن كانت لكل-IP
     * (subject=عنوان، من مصدرَي `AUTOBLOCK_SOURCES`) والمفتاحُ مشتعلاً
     * (`security.autoblock_enabled` — **مطفأٌ افتراضياً**) والعدُّ المحسوبُ سلفاً
     * فوق عتبةِ الحظر، أُنشئت قاعدةُ `IpRule` (origin=auto) بمدّةِ درجتها من
     * سلّم `security.autoblock_steps` (١٥←٦٠←١٤٤٠ دقيقة افتراضاً) وبُثّ
     * `ip_auto_blocked` عبر الناقل الواحد — **مرّةً لكل إنشاء**.
     *
     * القواعدُ الصلبة (المواصفة §39–42):
     *  · **فشلٌ مفردٌ لا يحظر أبداً**: أرضيةُ عتبةٍ ٢ مفروضةٌ هنا لا في الإعداد.
     *  · **لا حظرَ لمحميّ**: عنوانٌ موثوق (`security.trusted_ips` عبر المُطابِق
     *    الواحد `ip_allowed`)، أو معروفٌ لمالكٍ (`user_ips` — قناةُ التعافي)،
     *    أو تحميه قاعدةُ سماحٍ حيّة (allow يفوز block دائماً).
     *  · **لا عاصفةَ أحداث**: قاعدةُ حظرٍ حيّةٌ تُطابق العنوانَ = رشقٌ مستمرٌّ
     *    مُعالَجٌ فعلاً — لا قاعدةَ ثانية ولا حدثاً ثانياً؛ الحدثُ التالي بعد
     *    انقضائها فقط (وهو درجةُ السلّم التالية إن عاد ضمن النافذة).
     *  · **العودُ يُصعِّد**: آخرُ قاعدةٍ آليّةٍ (غيرِ ملغاة) للعنوان انقضت قبل
     *    أقلَّ من `security.autoblock_window_min` دقيقة ⇒ الدرجةُ التالية بسقفِ
     *    أعلى السلّم؛ والملغاةُ قرارُ إنسانٍ فلا تُصعِّد. وعند القمة تُفتح
     *    حادثةٌ آليّة واحدة (بصمة `autoblock:<ip>` — تُثرى لا تُكرَّر).
     *  · **العطلُ لا يُعدي**: كلُّه في try — جدولٌ غائبٌ أو خطأُ كتابةٍ لا
     *    يُسقط التقييمَ ولا التنبيه (والفرضُ نفسُه fail-open في WP-I.3).
     */
    protected function autoBlock(AlertRule $rule, array $f, array &$out): void
    {
        if ($this->dry) return;
        if (! in_array((string) $rule->source, self::AUTOBLOCK_SOURCES, true)) return;

        $ip = trim((string) ($f['subject'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return;
        if ((string) setting('security.autoblock_enabled', '0') !== '1') return;

        try {
            if (! Schema::hasTable('ip_rules')) return;

            // العتبةُ من الإعداد بأرضيةٍ صلبة ٢: فشلٌ مفردٌ لا يصنع حظراً ولو
            // ضُبط الإعدادُ صفراً — والعدُّ هو الذي حسبه fireSource سلفاً (استهلاك)
            if ((int) ($f['count'] ?? 0) < max(2, (int) setting('security.autoblock_threshold', 10))) return;

            // الموثوقُ عبر المُطابِق الواحد ip_allowed (دقيق/CIDR/عائلتان) — لا محلِّلَ ثانٍ
            if (ip_allowed($ip, (string) setting('security.trusted_ips', ''))) return;

            // القواعدُ الحيّة دفعةً واحدة: سماحٌ يُطابِق = محميّ؛ حظرٌ يُطابِق = مُعالَجٌ فعلاً
            $live = IpRule::query()->active()->orderBy('created_at')->orderBy('id')->get();
            foreach ($live as $r) {
                if ($r->matches($ip)) return;
            }

            // عنوانٌ معروفٌ لمالكٍ (ذاكرةُ user_ips القائمة) — قناةُ تعافٍ لا هدفُ حظر
            if ($this->ownerKnownIp($ip)) return;

            // درجةُ السلّم: آخرُ قاعدةٍ آليّةٍ غيرِ ملغاةٍ للعنوان نفسِه انقضت ضمن
            // نافذة العود ⇒ التالية؛ وإلا فمن أوّل السلّم. ترتيبٌ حتميّ (id قاطع).
            $steps = $this->autoblockSteps();
            $level = 0;
            $prev = IpRule::query()->where('origin', 'auto')->where('mode', 'block')
                ->where('ip', $ip)->whereNull('revoked_at')
                ->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($prev) {
                $ended = $prev->expires_at ?? $prev->created_at;
                $win = max(1, (int) setting('security.autoblock_window_min', 60));
                if ($ended !== null && $ended->gt(now()->subMinutes($win))) {
                    $level = min((int) $prev->escalation_level + 1, count($steps) - 1);
                }
            }

            $minutes = $steps[$level];
            $sev = Severity::normalize((string) ($f['severity'] ?? (self::SOURCE_SEV[$rule->source] ?? 'high')));
            $block = IpRule::create([
                'ip'               => $ip,
                'mode'             => 'block',
                'origin'           => 'auto',
                'severity'         => $sev,
                'reason'           => mb_substr("حظرٌ آليّ (درجة {$level} — {$minutes} دقيقة): " . (string) ($f['title'] ?? ''), 0, 400),
                'expires_at'       => now()->addMinutes($minutes),
                'escalation_level' => $level,
                'request_id'       => mb_substr((string) Api::requestId(), 0, 64) ?: null,
                'by_id'            => null,          // آليٌّ — لا فاعلَ بشريّاً
            ]);
            $out['autoblocks']++;

            // الحدثُ الدلاليّ ip_auto_blocked — مرّةً لكل إنشاءٍ (لا لكل تقييم):
            // يمرّ بالناقل الواحد فتعمل الويبهوكس والمسارات عليه كأيّ حدث
            FlowRunner::fire('auto_blocked', 'ip_rules', $block);

            // قمةُ السلّم = معتدٍ عاد رغم كل الدرجات — حادثةٌ للتحقيق البشريّ،
            // ببصمةٍ فتُثرى المفتوحةُ ولا تتكرّر (hub_open_incident يتكفّل)
            if ($level === count($steps) - 1) {
                $inc = hub_open_incident(
                    "العنوان {$ip} بلغ أعلى درجات الحظر الآليّ ({$minutes} دقيقة) — عودٌ متكرّر رغم الحظر",
                    self::SEV_AR[$sev] ?? 'عالي', 'security', 'autoblock:' . $ip,
                    ['ip' => $ip, 'rule_id' => (string) $rule->id, 'ip_rule_id' => (string) $block->id,
                     'count' => (int) ($f['count'] ?? 0), 'escalation_level' => $level], 24);
                if ($inc && $inc->wasRecentlyCreated) $out['incidents']++;
            }
        } catch (\Throwable $e) {
            // انقطاعُ الدفاع لا يصير انقطاعَ تنبيه — يُبلَّغ ولا يكسر التقييم
            report($e);
        }
    }

    /** سلّمُ التصعيد من `security.autoblock_steps` (دقائق) — وإلا الافتراضيُّ الصلب */
    protected function autoblockSteps(): array
    {
        $steps = [];
        foreach (explode(',', (string) setting('security.autoblock_steps', '15,60,1440')) as $s) {
            if ((int) trim($s) >= 1) $steps[] = (int) trim($s);
        }

        return $steps !== [] ? $steps : self::AUTOBLOCK_DEFAULT_STEPS;
    }

    /** هل العنوانُ معروفٌ لمالكٍ؟ — ذاكرةُ `user_ips` القائمة، لا حاسبَ ألفةٍ ثانياً */
    protected function ownerKnownIp(string $ip): bool
    {
        if (! Schema::hasTable('user_ips')) return false;

        return DB::table('user_ips')
            ->join('users', 'users.id', '=', 'user_ips.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('users.deleted_at')->where('roles.is_owner', true)
            ->where('user_ips.ip', $ip)->exists();
    }

    /** إشعارُ مستلمي القاعدة (الجرسُ دائماً، والقنواتُ الخارجية بحسب chan) */
    protected function notifyRule(AlertRule $rule, string $text, array &$out, bool $channels = true): void
    {
        $to = $this->recipientUsers($rule->to_id);
        if (filled($rule->mod)) {
            $to = $to->filter(fn ($ru) => $ru->role?->is_owner || hub_can($ru, $rule->mod, 'v'))->values();
        }
        foreach ($to as $ru) {
            hub_notify($ru->id, 'rule:' . $rule->id, $text, $rule->mod ?: null);
            $out['notifs']++;
        }
        if (! $channels || $to->isEmpty()) return;
        $chan = (string) $rule->chan;
        foreach (['تلجرام' => 'tg', 'بريد' => 'mail'] as $word => $ch) {
            if (str_contains($chan, $word) || str_contains($chan, 'الكل')) {
                OutboxMessage::create([
                    'user_id' => $to->first()?->id, 'kind' => 'rule:' . $rule->id, 'channel' => $ch,
                    'target' => null, 'text' => Str::limit($text, 790), 'state' => 'queued', 'created_at' => now(),
                ]);
            }
        }
    }

    /* ═════════ ٣) كشفُ الحوادث التشغيلية (§3.9) ═════════ */

    /**
     * بصماتُ `ops:*` من نموذج الصحّة: كلُّ بصمةٍ تفتح **حادثةً واحدة** (تكرارٌ
     * بالبصمة عبر `hub_open_incident` — فتمرّ بالناقل وتعمل مساراتُ «🚨 حادث
     * حرج» المبذورة)، وتُثرى بالأدلّة ما دامت مفتوحة. وعند الشفاء يُلحَق قيدُ
     * «تعافت الخدمة» مرّةً واحدة — **ولا إغلاقَ آليّاً أبداً** (§13: إغلاقُ
     * الأدلّة قرارُ إنسان). «مجهول» ليس شفاءً: مكوّنٌ غاب فحصُه لا يُعافى ادّعاءً.
     */
    protected function detectOps(array $health, array &$out): void
    {
        $c = (array) ($health['components'] ?? []);
        $fired = [];    // fingerprint => [العنوان، الشدّة، الدليل]
        $healthy = [];  // بصماتٌ شُوهد شرطُها زائلاً صراحةً

        $seen = fn (?array $comp) => in_array($comp['status'] ?? '', [Health::HEALTHY, Health::DEGRADED], true);

        if (($c['db']['status'] ?? '') === Health::UNAVAILABLE) {
            $fired['ops:db_unavailable'] = ['قاعدةُ البيانات لا تُجيب — النظامُ متوقّف فعلياً', 'حرج', $c['db']['data'] ?? []];
        } elseif ($seen($c['db'] ?? null)) $healthy[] = 'ops:db_unavailable';

        $pct = $c['storage']['data']['disk_pct'] ?? null;
        if ($pct !== null && Health::diskStatus((int) $pct) === Health::UNAVAILABLE) {
            $fired['ops:disk_critical'] = ["القرصُ ممتلئ {$pct}٪ — الكتابةُ (نسخٌ ومرفقاتٌ وجلسات) على وشك الفشل", 'حرج', ['disk_pct' => (int) $pct]];
        } elseif ($pct !== null) $healthy[] = 'ops:disk_critical';

        foreach (($c['scheduler']['data']['jobs'] ?? []) as $jk => $j) {
            if (($j['status'] ?? '') === Health::UNAVAILABLE) {
                $fired['ops:scheduler_dead:' . $jk] = ['المجدولة «' . ($j['label'] ?? $jk) . '» ميتة — آخرُ نبضةٍ منذ ' . (int) ($j['age_min'] ?? 0) . ' دقيقة', 'عالي',
                    ['job' => $jk, 'age_min' => $j['age_min'] ?? null, 'at' => $j['at'] ?? null]];
            } elseif (in_array($j['status'] ?? '', [Health::HEALTHY, Health::DEGRADED], true)) {
                $healthy[] = 'ops:scheduler_dead:' . $jk;
            }
        }

        if (($c['outbox']['status'] ?? '') === Health::UNAVAILABLE) {
            $fired['ops:outbox_backlog'] = ['الصندوقُ الصادر متكدّس: ' . (string) ($c['outbox']['why'] ?? ''), 'عالي', $c['outbox']['data'] ?? []];
        } elseif ($seen($c['outbox'] ?? null)) $healthy[] = 'ops:outbox_backlog';

        foreach (($c['integrations']['data']['items'] ?? []) as $ik => $it) {
            if (($it['status'] ?? '') === Health::UNAVAILABLE) {
                $fired['ops:dep_failed:' . $ik] = ['التكامل «' . ($it['name'] ?? $ik) . '» فاشل: ' . (string) ($it['last_error'] ?? ''), 'عالي',
                    ['key' => $ik, 'last_fail_at' => $it['last_fail_at'] ?? null]];
            } elseif (in_array($it['status'] ?? '', [Health::HEALTHY, Health::DEGRADED], true)) {
                $healthy[] = 'ops:dep_failed:' . $ik;
            }
        }

        if (($c['errors']['status'] ?? '') === Health::UNAVAILABLE) {
            $fired['ops:error_spike'] = ['موجةُ أخطاءٍ حرجة: ' . (string) ($c['errors']['why'] ?? ''), 'عالي', $c['errors']['data'] ?? []];
        } elseif ($seen($c['errors'] ?? null)) $healthy[] = 'ops:error_spike';

        if ($this->dry) return;

        foreach ($fired as $fp => [$title, $sev, $ev]) {
            $inc = hub_open_incident($title, $sev, 'ops', $fp, Redactor::arr(is_array($ev) ? $ev : []), 24);
            if ($inc && $inc->wasRecentlyCreated) $out['incidents']++;
        }

        // الشفاء: قيدُ «تعافت الخدمة» على الحادثة المفتوحة بالبصمة — مرّةً واحدة
        // (وسمُ meta.recovered يمحوه hub_open_incident إن عاد الشرط فيُكتب من جديد)
        if (! Schema::hasTable('incidents') || ! $healthy) return;
        try {
            $open = \App\Models\Incident::whereNull('deleted_at')
                ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
                ->when(hub_has_col('incidents', 'kind'),
                    fn ($q) => $q->where('kind', 'ops'),
                    fn ($q) => $q->where('meta->kind', 'ops'))
                ->orderBy('created_at')->orderBy('id')->get();
            foreach ($open as $i) {
                $fp = (string) ($i->meta['fingerprint'] ?? '');
                if ($fp === '' || ! in_array($fp, $healthy, true) || ! empty($i->meta['recovered'])) continue;
                $this->appendIncidentNote((string) $i->id, 'تعافت الخدمة — الشرطُ زال في الفحص التالي؛ الحادثةُ تبقى مفتوحةً لقرار الإنسان', markRecovered: true);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** إلحاقُ قيدٍ في خطّ الحادثة الزمنيّ (meta.events) — يقرؤه فرعُ incidents في hub_timeline */
    protected function appendIncidentNote(string $incidentId, string $note, bool $markRecovered = false): void
    {
        try {
            $i = \App\Models\Incident::whereNull('deleted_at')->find($incidentId);
            if (! $i) return;
            $meta = (array) $i->meta;
            $meta['events'] = array_merge((array) ($meta['events'] ?? []),
                [['at' => now()->toIso8601String(), 'note' => mb_substr($note, 0, 300)]]);
            if ($markRecovered) $meta['recovered'] = true;
            $i->meta = $meta;
            $i->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
