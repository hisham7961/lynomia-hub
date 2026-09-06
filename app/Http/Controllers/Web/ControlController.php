<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\ActionCenter;
use App\Support\AttentionQueue;
use App\Support\Audit;
use App\Support\DataQuality;
use App\Support\ErrorStats;
use App\Support\ExecutionStats;
use App\Support\Health;
use App\Support\SecurityFindings;
use App\Support\SecurityPosture;
use App\Support\TimeRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **نظرةُ التحكّم** (WP-10.1 · spec §32 · §12).
 *
 * ═══ ما هذه الصفحة، وما ليست ═══
 * هي **مفترقُ طرق** لا لوحةٌ عملاقة (spec §32 صريحة: لا لوحةَ تكرّر التفاصيل).
 * ستُّ بطاقاتٍ قارئةٍ فقط، كلٌّ تقول رقماً واحداً وتحيل إلى المركز الذي يملكه:
 *
 *   ① الأمن     ← `SecurityPosture::summary` (الدرجة) + `SecurityFindings::openCounts` (الحرج)
 *   ② التشغيل   ← `Health::check` (الحالة والمكوّنات) + الحوادثُ المفتوحة بسكّة «المفتوح» الواحدة
 *   ③ الأخطاء   ← `ErrorStats::cards` (الحرجُ غيرُ المحلول)
 *   ④ التدقيق   ← آخرُ صفٍّ في `audit_verifications`، وعند غيابه `Audit::verifyTail`
 *   ⑤ الجودة    ← `DataQuality::scan` (الدرجة والنواقص)
 *   ⑥ التنفيذ   ← `ExecutionStats::executionSummary` (المتأخّرُ والمفتوح والالتزام)
 *
 * **لا رقمَ يُحسب هنا**: كلُّ سطرٍ نقلٌ من مخرَج محرّكه. وما لا يقيسه محرّكُه
 * يبقى `null` ويُعرَض «—» لا صفراً (spec §26): مركزٌ لم يبدأ العملَ بعدُ يقول
 * ذلك صادقاً، وصفرٌ مكانَ غيابِ قياسٍ يُقرأ شهادةَ سلامةٍ كاذبة.
 *
 * ═══ الكلفة ═══
 * `Health::check` وحدَه عشراتُ الاستعلامات، فالبطاقاتُ خلف `hub_screen(...,
 * stamped: true)` بمهلة ٦٠ ثانية وبختمِ جداولها — و«آخرُ حساب» معروضٌ بسطر
 * `partials/cc/freshness` فلا يُقدَّم رقمٌ قديمٌ على أنه لحظيّ. و`?fresh=1`
 * يُبطلها متى أراد القارئ.
 *
 * ═══ الحارس (ق١) ═══
 * الصفحةُ للمالك، ولحاملِ راية المراقبة **ما تمنحه ق١ وحدَه**: النتائجُ الأمنية
 * (مركزُها `owner‖monitor`) والجودةُ والتنفيذ (أرقامُ منشأةٍ مجمَّعة ⇒ يلزمها
 * `hub_org_analytics_guard` أيضاً). أمّا التشغيلُ والأخطاءُ فمراكزُهما للمالك
 * وحدَه، والتدقيقُ لحاملِ راية `audit` — **وبطاقةٌ محجوبةٌ لا تُحسَب أصلاً**،
 * فلا يدفع القارئُ كلفةَ قياسٍ لا يُعرض له ولا يتسرّب رقمٌ في صفحةٍ مخبّأة.
 */
class ControlController extends Controller
{
    /** مهلةُ خبيئة البطاقات — ٦٠ ثانية (مهلةُ صفّ التدخّل نفسُها) */
    public const TTL = 60;

    /** كبسولةُ بطاقة التنفيذ حين لا يطلب القارئ غيرَها */
    public const RANGE = '30d';

    /**
     * جدولُ الختم: حادثةٌ تُفتح الآن تُبطل الخبيئةَ فوراً لا بعد دقيقة.
     *
     * **ولا يُدرَج غيرُه عمداً**، لسببين مكتوبين لا مسكوتٍ عنهما:
     *  — أدلّةُ الأمن والأخطاء والتحقّق تُكتب بـ`DB::table` لا بنماذج، وختمُ
     *    `hub_data_bump` يُطلق من حدث `saved` على النماذج وحدَها — فإدراجُها
     *    هنا ختمٌ لا يُضرب أبداً، أي وعدٌ بلا وفاء.
     *  — و`tasks`/`objectives` كثيرةُ التغيّر: ختمُها يُبطل الصفحةَ مع كلّ حفظِ
     *    مهمّة فيُعاد `Health::check` (عشراتُ الاستعلامات) كلَّ ثوانٍ — والمهلةُ
     *    أصدق: أرقامُ الجودة والتنفيذ لا تفسد في دقيقة.
     * فالمهلةُ (٦٠ ثانية) هي **الحدُّ الأقصى المُعلَن** للتأخّر، ويقوله سطرُ
     * الطزاجة في الشاشة، ويُلغيه `?fresh=1`.
     */
    protected const TABLES = ['incidents'];

    protected function gate(): void
    {
        abort_unless(hub_is_owner() || hub_monitor(), 403,
            'مستوى التحكّم للمالك أو حاملِ راية المراقبة');
    }

    public function index()
    {
        $this->gate();

        $range = TimeRange::fromRequest(null, self::RANGE);

        /*
         * **نموذجُ الصحّة يُحسب مرّةً واحدةً في الطلب — كسولاً.**
         * يطلبه اثنان هنا: بطاقةُ التشغيل، وصفُّ التدخّل (`AttentionQueue` عبر
         * `ActionCenter::signals`). و`Health::check` بلا خبيئةٍ داخلية وعشراتُ
         * الاستعلامات، فحسابُه مرّتين إعادةُ حسابٍ صريحة. والمزوّدُ **كسولٌ** لا
         * مصفوفةٌ محسوبةٌ سلفاً لأنّ الطالبَين مشروطان: صفحةٌ دافئةُ البطاقات
         * والصفِّ معاً لا تحتاجه أصلاً، فحسابُه مقدَّماً كان سيُحمّل الفتحةَ
         * الدافئةَ (تسعةُ استعلامات) ستّينَ استعلاماً بلا قارئ.
         * (النمطُ نفسُه في `AlertEngine::detect($rules, $health)`.)
         */
        $model = null;
        $health = function () use (&$model): array { return $model ??= Health::check(); };

        $s = hub_screen('control.home:' . $range->key(), self::TTL,
            fn () => $this->cards($range, $health), self::TABLES, true);

        /*
         * الصفُّ **حيٌّ لا مخبّأٌ مع البطاقات**: `ActionCenter::signals` تُسقِط
         * تصرّفَ المستخدم من `signal_states` عند كل قراءة، فتأجيلٌ وقع قبل ثانية
         * يُخفي بندَه فوراً (مادّةُ البنود نفسُها مخبّأةٌ داخل `AttentionQueue`).
         * و`signals` لا `feed`: «ما ينتظرني» صندوقُ الوحدات ومكانُه شاشتُه —
         * وبناؤه هنا عشراتُ الاستعلامات لقسمٍ لا تعرضه هذه الصفحة.
         */
        $sig = ActionCenter::signals((bool) request()->query('fresh'), null, $health);

        return view('control.index', [
            'cards' => $s['data'],
            'at'    => $s['at'],
            'ttl'   => self::TTL,
            'range' => $range,
            'queue' => AttentionQueue::forControl($sig['visible']),
        ]);
    }

    /* ────────── البطاقات ────────── */

    /**
     * ستُّ بطاقاتٍ بحارسِ كلٍّ منها. البطاقةُ المحجوبة تعود بـ`visible=false`
     * **بلا نداءِ محرّكها** — الحجبُ قبل الحساب لا بعده.
     */
    protected function cards(TimeRange $range, ?\Closure $health = null): array
    {
        $u = auth()->user();
        $owner = hub_is_owner($u);
        $monitor = hub_monitor($u);

        /*
         * «هل يجوز لهذا القارئ رقمُ المنشأة؟» يُسأل **بالحارس نفسِه** لا بنسخةٍ
         * ثانيةٍ من شرطه: يرمي ⇒ لا، يمرّ ⇒ نعم. (أرقامُ الجودة والتنفيذ تجمع
         * عبر الشركات كلِّها، فلا تُطعَم لحسابٍ معزول.)
         */
        $orgOk = (bool) rescue(function () { hub_org_analytics_guard(); return true; }, false, false);

        return [
            'security'   => $this->card('security', $owner || $monitor, fn () => $this->securityCard($owner)),
            'operations' => $this->card('operations', $owner, fn () => $this->operationsCard($health)),
            'errors'     => $this->card('errors', $owner, fn () => $this->errorsCard()),
            'audit'      => $this->card('audit', $owner || hub_flag($u, 'audit'), fn () => $this->auditCard($owner)),
            'quality'    => $this->card('quality', $owner || ($monitor && $orgOk), fn () => $this->qualityCard($owner)),
            'execution'  => $this->card('execution', $owner || ($monitor && $orgOk), fn () => $this->executionCard($range)),
        ];
    }

    /**
     * غلافُ بطاقةٍ واحد: الحجبُ أولاً، ثم الحساب داخل شبكة أمان — فمحرّكٌ يرمي
     * (جدولٌ قبل ترحيله، عمودٌ غائب) يُنتج بطاقةَ «تعذّر القياس» الصادقة ولا
     * يُطفئ الصفحةَ كلَّها.
     */
    protected function card(string $key, bool $visible, \Closure $fn): array
    {
        $base = ['key' => $key, 'visible' => $visible];
        if (! $visible) return $base;

        try {
            return $base + $fn();
        } catch (\Throwable $e) {
            report($e);

            return $base + ['label' => self::LABELS[$key], 'icon' => self::ICONS[$key],
                            'value' => '—', 'tone' => '', 'url' => null,
                            'sub' => 'تعذّر القياس الآن — افتح المركزَ مباشرةً',
                            'hint' => 'سقط قارئُ هذه البطاقة وسُجّل العطلُ في مركز الأخطاء'];
        }
    }

    /** التسمياتُ والأيقونات — مصدرٌ واحدٌ للبطاقة ولبطاقة العطل معاً */
    public const LABELS = [
        'security' => 'الأمن', 'operations' => 'التشغيل', 'errors' => 'الأخطاء',
        'audit' => 'التدقيق', 'quality' => 'الجودة', 'execution' => 'التنفيذ',
    ];

    public const ICONS = [
        'security' => '🛡️', 'operations' => '🖥️', 'errors' => '🐞',
        'audit' => '📜', 'quality' => '🧹', 'execution' => '🚀',
    ];

    /**
     * ① الأمن: الدرجةُ من `SecurityPosture::summary` (لقطةٌ حيّةٌ تُحسب دائماً)
     * والحرجُ من `SecurityFindings::openCounts`. وسجلُّ النتائج قد لا يكون
     * سُوّي بعد (لقطةُ الأمن يومية) — فيُقال ذلك صراحةً ولا يُكتب صفرٌ يُقرأ
     * «لا نتائجَ حرجة» وهو «لم يُبحث».
     */
    protected function securityCard(bool $owner): array
    {
        $sum = SecurityPosture::summary();
        $reconciled = Schema::hasTable('security_findings') && DB::table('security_findings')->exists();
        // العدُّ بنطاق القارئ نفسِه الذي يفرضه مركزُ النتائج — فالبطاقةُ لا تعدّ
        // ما لا يفتحه، ولا تقول «ثلاثٌ حرجة» ثم يفتح المركزُ على واحدة (§49)
        $open = $reconciled ? SecurityFindings::openCounts(hub_company_ids()) : [];

        $critical = $reconciled ? (int) ($open['critical'] ?? 0) : null;
        $high = $reconciled ? (int) ($open['high'] ?? 0) : null;

        return [
            'label' => self::LABELS['security'] . ' — درجةُ الوضعية', 'icon' => self::ICONS['security'],
            'value' => $sum['score'] . '٪',
            'score' => $sum['score'],
            'critical' => $critical,
            'high' => $high,
            'findings_value' => $critical === null ? '—' : (string) $critical,
            'tone' => $critical ? 'bad' : ($sum['bad'] ? 'wn' : 'ok'),
            'sub' => $critical === null
                ? 'لم تُسوَّ النتائجُ بعد — تُكتب مع لقطة الأمن اليومية'
                : $critical . ' نتيجةً حرجة · ' . $high . ' مرتفعة (غيرُ محلولة)',
            'url' => route('security.findings'),
            'hint' => 'الدرجةُ من SecurityPosture::summary والعددُ من SecurityFindings::openCounts',
        ];
    }

    /**
     * ② التشغيل: الحالةُ التي يقرؤها `/healthz` نفسُها، وعددُ المكوّنات الساقطة،
     * والحادثةُ النشطة من **سكّة «المفتوح» الواحدة** (`hub_open_scope` فوق
     * `hub_closed_states`) لا من قائمةِ حالاتٍ ثانيةٍ تُصان يدوياً.
     */
    protected function operationsCard(?\Closure $health = null): array
    {
        $h = $health ? ($health)() : Health::check();
        $bad = array_filter($h['components'] ?? [], fn ($c) => ($c['status'] ?? '') !== Health::HEALTHY);

        $incidents = Schema::hasTable('incidents')
            ? (int) hub_open_scope(hub_scope(DB::table('incidents')->whereNull('deleted_at'), 'incidents'))->count()
            : null;

        return [
            'label' => self::LABELS['operations'] . ' — حالةُ النظام', 'icon' => self::ICONS['operations'],
            'value' => Health::LABELS[$h['status']],
            'health' => $h['status'],
            'degraded' => count($bad),
            'incidents' => $incidents,
            'tone' => Health::TONE[$h['status']],
            'sub' => ($bad ? count($bad) . ' مكوّناً غير سليم: '
                        . implode('، ', array_slice(array_map(fn ($c) => $c['label'], $bad), 0, 3))
                    : 'كل المكوّنات سليمة')
                . ' · ' . ($incidents === null ? 'لا سجلَّ حوادث'
                    : ($incidents ? $incidents . ' حادثةً نشطة' : 'لا حادثة نشطة')),
            'url' => route('ops.index'),
            'hint' => 'Health::check — الحالةُ نفسُها التي يقرؤها /healthz',
        ];
    }

    /**
     * ③ الأخطاء: الحرجُ غيرُ المحلول من `ErrorStats::cards`. و`null` هناك تعني
     * «عمودُ الشدّة قبل ترحيله» — تُنقَل كما هي ولا تُحوَّل صفراً.
     */
    protected function errorsCard(): array
    {
        $c = ErrorStats::cards();
        $known = $c['critical'] !== null;
        $seen = ($c['open'] ?? 0) > 0 || ($c['new24'] ?? 0) > 0;

        return [
            'label' => self::LABELS['errors'] . ' — حرجٌ مفتوح', 'icon' => self::ICONS['errors'],
            'value' => $known ? (string) $c['critical'] : '—',
            'critical' => $c['critical'],
            'open' => (int) ($c['open'] ?? 0),
            'tone' => ! $known ? '' : ($c['critical'] ? 'bad' : 'ok'),
            'sub' => ! $known
                ? 'تصنيفُ الشدّة غيرُ مُرحَّل بعد — لا قياسَ لا صفر'
                : ($seen ? number_format((int) $c['open']) . ' عطلاً مفتوحاً' : 'لم يُسجَّل عطلٌ بعد'),
            'url' => route('errors.index'),
            'hint' => 'ErrorStats::cards — «المفتوح» يستثني المحلولَ والمتجاهَل معاً',
        ];
    }

    /**
     * ④ التدقيق: آخرُ صفٍّ في `audit_verifications` (ناتجُ الفحص الكامل)، وعند
     * غيابه `Audit::verifyTail` — **ولا فحصَ سلسلةٍ كاملاً في طلبٍ أبداً**.
     */
    protected function auditCard(bool $owner): array
    {
        $row = Schema::hasTable('audit_verifications')
            ? DB::table('audit_verifications')->orderByDesc('started_at')->orderByDesc('id')->first()
            : null;
        $url = $owner ? route('audit.coverage') : route('audit.index');

        if ($row) {
            $label = ['ok' => 'سليمة', 'warn' => 'بملاحظات', 'fail' => '⚠️ مكسورة'][$row->result] ?? '—';

            return [
                'label' => self::LABELS['audit'] . ' — نزاهةُ السلسلة', 'icon' => self::ICONS['audit'],
                'value' => $label,
                'result' => (string) $row->result,
                'tail_ok' => null,
                'tone' => ['ok' => 'ok', 'warn' => 'wn', 'fail' => 'bad'][$row->result] ?? '',
                'sub' => 'آخرُ فحصٍ كامل ' . substr((string) $row->started_at, 0, 16)
                    . ' · ' . number_format((int) $row->checked_rows) . ' قيداً',
                'url' => $url,
                'hint' => 'آخرُ صفٍّ في audit_verifications — لا فحصَ كاملٌ في الطلب',
            ];
        }

        $tail = Audit::verifyTail();

        return [
            'label' => self::LABELS['audit'] . ' — نزاهةُ السلسلة', 'icon' => self::ICONS['audit'],
            'value' => $tail['ok'] ? '—' : '⚠️ مكسورة',
            'result' => null,
            'tail_ok' => (bool) $tail['ok'],
            'tone' => $tail['ok'] ? '' : 'bad',
            'sub' => $tail['ok']
                ? 'لم يُشغَّل الفحصُ الكامل بعد — المعروضُ ذيلُ السلسلة: ' . $tail['label']
                : (string) $tail['why'],
            'url' => $url,
            'hint' => 'Audit::verifyTail — نافذةٌ من آخر القيود لا فحصٌ كامل',
        ];
    }

    /**
     * ⑤ الجودة: الدرجةُ والنواقصُ من `DataQuality::scan`. وبلا سجلاتٍ أصلاً
     * (`rows = 0`) الدرجةُ ١٠٠ بلا معنىً — فتُقال «—» و«لا سجلات بعد».
     */
    protected function qualityCard(bool $owner): array
    {
        $t = DataQuality::scan()['totals'];
        $measured = (int) $t['rows'] > 0;

        return [
            'label' => self::LABELS['quality'] . ' — درجةُ البيانات', 'icon' => self::ICONS['quality'],
            'value' => $measured ? $t['score'] . '٪' : '—',
            'score' => $t['score'],
            'rows' => (int) $t['rows'],
            'defects' => (int) $t['defects'],
            'tone' => ! $measured ? '' : ($t['score'] >= 95 ? 'ok' : ($t['score'] >= 80 ? 'wn' : 'bad')),
            'sub' => $measured
                ? number_format((int) $t['defects']) . ' نقصاً في ' . $t['checks'] . ' فحصاً'
                : 'لا سجلات بعد — يبدأ القياسُ مع أول سجل',
            'url' => route('quality.index', ['tab' => $owner ? 'data' : 'overview']),
            'hint' => 'DataQuality::scan — الدرجة = ١٠٠ − (النواقص ÷ السجلات)',
        ];
    }

    /**
     * ⑥ التنفيذ: المتأخّرُ الآن من `ExecutionStats::executionSummary` — لقطةُ
     * لحظةٍ لا نافذة. وبلا مهامَّ أصلاً لا يُكتب صفرٌ يُقرأ «كلُّ شيءٍ في موعده».
     */
    protected function executionCard(TimeRange $range): array
    {
        $x = ExecutionStats::executionSummary($range);
        $measured = ($x['open'] ?? 0) > 0 || ($x['completed'] ?? 0) > 0 || (($x['planned']['n'] ?? 0) > 0);

        return [
            'label' => self::LABELS['execution'] . ' — متأخّرٌ الآن', 'icon' => self::ICONS['execution'],
            'value' => $measured ? number_format((int) $x['overdue']) : '—',
            'overdue' => (int) $x['overdue'],
            'open' => (int) $x['open'],
            'ontime' => $x['on_time']['pct'],
            'tone' => ! $measured ? '' : ($x['overdue'] ? 'bad' : 'ok'),
            'sub' => $measured
                ? number_format((int) $x['open']) . ' مهمّةً مفتوحة · الالتزام '
                    . ($x['on_time']['pct'] === null ? '—' : $x['on_time']['pct'] . '٪')
                : 'لا مهامَّ بعد — يبدأ القياسُ مع أول مهمّة',
            'url' => route('quality.index', ['tab' => 'execution']),
            'hint' => 'ExecutionStats::executionSummary — المتأخّرُ لقطةُ اليوم لا نافذةُ الكبسولة',
        ];
    }
}
