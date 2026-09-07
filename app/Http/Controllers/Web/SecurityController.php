<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Support\SecurityPosture;
use App\Support\SecurityRadar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** مركز الأمان — وضعيةٌ حيّة، وجلساتٌ تُنهى فعلاً، وقفل طوارئ */
class SecurityController extends Controller
{
    /**
     * بعد هذه المدة تُعدّ الجلسة منتهيةً لا نشطة — (WP-4.4) الثابتُ الواحد في
     * `Sessions::LIVE_MIN` بدل أربع نسخٍ متباعدة؛ الاسمُ المحليّ يبقى للتوافق.
     */
    protected const LIVE_MIN = \App\Support\Sessions::LIVE_MIN;

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'مركز الأمان للمالكين فقط');
    }

    public function index(\Illuminate\Http\Request $r)
    {
        $this->gate();

        // السجلُّ الأمنيّ الموحَّد (v2.399): تصنيفٌ قانونيّ فوق التدقيق ورادار المنع — يُصفّى بالكود
        $eventCode = hub_str($r->query('ev'));
        if ($eventCode !== '' && ! isset(\App\Support\SecurityEvents::CODES[$eventCode])) $eventCode = '';

        $users = DB::table('users')->whereNull('deleted_at');

        // (WP-4.3) صيغُ فشل الدخول من مفردات SecurityEvents الواحدة — لا قائمةً حرفيةً
        // مكرّرة: صيغةُ QuoteFlow التاريخية كانت تسقط من كل عدٍّ يكتب «دخول فاشل» بيده
        $authFail = \App\Support\SecurityEvents::actions('AUTH_FAILURE');

        // (WP-4.5) معرّفاتُ الأسرار البائتة من المصدر الواحد — البطاقةُ والجدولُ
        // المصغّر يقرآن القائمةَ نفسَها فلا ينحرف أحدُهما عن الآخر
        $staleIds = SecurityPosture::vaultStaleIds();

        // (بوّابة الطور ٤ — ميزانيّة) العدّاداتُ الثقيلة تُحسب **مرّةً واحدة** لكل
        // طلبٍ وتُمرَّر: كانت اللوحةُ تنادي counts/summary/intel/staleParts مرّتين
        // ومرّاتٍ فتتجاوز ميزانيّتَها — والرقمُ الواحد من مصدره الواحد لا يتبدّل
        // بين نداءين في الطلب نفسه.
        $eventCounts = \App\Support\SecurityEvents::counts(7);
        $apiParts = \App\Support\ApiTokens::staleParts();
        $radarSummary = SecurityRadar::summary();

        $kpi = [
            // «دخول فاشل ٧ أيام» = عدّاد AUTH_FAILURE نفسُه (صيغُه حرفيّةٌ كلُّها) —
            // كان استعلاماً ثانياً بالنافذة نفسِها على المفردات نفسِها
            'failed7' => (int) ($eventCounts['AUTH_FAILURE'] ?? 0),
            'stale'   => count($staleIds),
            'live'    => DB::table('sessions_log')->where('revoked', false)
                            ->where('last_seen_at', '>=', now()->subMinutes(self::LIVE_MIN))->count(),
            // حوادثُ أمنيّةٌ مفتوحة (مُولَّدةٌ آلياً بوسم kind=security) — تُحقَّق.
            // (WP-6.1) عمودُ `kind` المفهرسُ بدل مسح meta بـLIKE في كل فتحٍ للمركز؛
            // الصفوفُ القديمة عمودُها فارغٌ فتُلتقط بسقوطٍ إلى meta (تعبئةٌ كسولة)
            'secinc'  => Schema::hasTable('incidents')
                ? DB::table('incidents')->whereNull('deleted_at')
                    ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
                    ->when(hub_has_col('incidents', 'kind'),
                        fn ($q) => $q->where(fn ($w) => $w->where('kind', 'security')
                            ->orWhere(fn ($o) => $o->whereNull('kind')->where('meta', 'like', '%"kind"%security%'))),
                        fn ($q) => $q->where('meta', 'like', '%"kind"%security%'))
                    ->count() : 0,
        ];

        // الجلسات: الأحدثُ ظهوراً أولاً بفاصل تعادلٍ حاسم (critic #19) — «أول ٢٥»
        // معلَنةٌ في العنوان ومركزُ الجلسات الكامل مرقَّمٌ خلف رابطه
        $sessions = DB::table('sessions_log')
            ->leftJoin('users', 'users.id', '=', 'sessions_log.user_id')
            ->orderByDesc('sessions_log.last_seen_at')->orderByDesc('sessions_log.id')->limit(25)
            ->get(['sessions_log.id', 'sessions_log.user_id', 'users.name as uname', 'sessions_log.ip',
                   'sessions_log.device', 'sessions_log.started_at', 'sessions_log.last_seen_at', 'sessions_log.revoked'])
            ->map(function ($s) {
                $s->live = ! $s->revoked && $s->last_seen_at
                    && \Illuminate\Support\Carbon::parse($s->last_seen_at)->gt(now()->subMinutes(self::LIVE_MIN));
                $s->mine = (string) session('hub.sl', '') === (string) $s->id;

                return $s;
            });

        $failed = DB::table('audits')->whereIn('action', $authFail)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(['name', 'ip', 'device', 'created_at']);

        // عناوين تطرق أكثر من حساب: تخمينٌ لا نسيان. (WP-4.4) إسقاطٌ على القارئ
        // الواحد SecurityRadar::intel — كانت هنا نسخةُ تجميعٍ ثانيةٌ بيدها،
        // فتصحيحُ عدٍّ في واحدةٍ يترك الأخرى على الخطأ.
        $intel7 = null;
        try {
            // ذكاءُ العناوين (نافذة ٧ أيام حتى الآن) يُجمَّع مرّةً — «الطارقون» هنا
            // و«التهديدات» أدناه إسقاطان عليه لا تجميعان منفصلان
            $intel7 = SecurityRadar::intel(now()->subDays(7));
            $knocking = $intel7
                ->filter(fn ($t) => (int) $t->fail_targets > 1)
                ->sortBy([['fails', 'desc'], ['ip', 'asc']])->take(6)
                ->map(fn ($t) => (object) ['ip' => $t->ip, 'hits' => (int) $t->fails,
                    'targets' => (int) $t->fail_targets])
                ->values();
        } catch (\Throwable $e) {
            \App\Support\ErrorLog::capture('php', 'security: تعذّر تجميع العناوين الطارقة — ' . $e->getMessage(),
                $e->getFile(), $e->getLine());
            $knocking = collect();
        }

        $idleUsers = (clone $users)->where('status', '!=', 'موقوف')
            ->where(fn ($w) => $w->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(60)))
            ->orderBy('last_login_at')->orderBy('id')->limit(10)->get(['id', 'name', 'email', 'last_login_at', 'totp_enabled']);

        // (WP-4.5) القائمةُ من vaultStaleIds نفسِها (عمرُ التدوير لا آخرَ تعديل) —
        // الأقدمُ تدويراً أولاً بفاصل تعادلٍ حاسم، و«أول ١٠» معلَنة مع رابط المركز الكامل
        $hasRot = hub_has_col('vault_secrets', 'rotated_at');
        $staleSecrets = DB::table('vault_secrets')->whereIn('id', $staleIds)
            ->when($hasRot, fn ($q) => $q->orderByRaw('COALESCE(rotated_at, created_at) asc'),
                            fn ($q) => $q->orderBy('updated_at'))
            ->orderBy('id')->limit(10)
            ->get(['id', 'title', 'type', 'created_at',
                   DB::raw($hasRot ? 'rotated_at' : 'NULL as rotated_at')]);

        // عدّةُ المستخدمين لكل دور دفعةً واحدة — كانت استعلاماً لكل صفٍّ فتنمو
        // الكلفةُ بعدد الأدوار (بوّابة الطور ٤ — ميزانيّة)
        $roleCounts = DB::table('users')->whereNull('deleted_at')
            ->groupBy('role_id')->selectRaw('role_id, COUNT(*) as c')->pluck('c', 'role_id');
        $roles = DB::table('roles')->get()->map(function ($r) use ($roleCounts) {
            $matrix = json_decode($r->matrix ?? '[]', true) ?: [];
            $flags  = array_keys(array_filter(json_decode($r->flags ?? '[]', true) ?: []));

            return (object) [
                'name' => $r->name, 'is_owner' => (bool) $r->is_owner, 'scope' => $r->scope,
                'mods' => count(array_filter($matrix, fn ($m) => array_filter((array) $m))),
                'flags' => $flags,
                'users' => (int) ($roleCounts[$r->id] ?? 0),
            ];
        });

        $exports = DB::table('audits')->where('action', 'تصدير')
            ->leftJoin('users', 'users.id', '=', 'audits.user_id')
            ->orderByDesc('audits.created_at')->orderByDesc('audits.id')->limit(8)
            ->get(['users.name as uname', 'audits.module', 'audits.name', 'audits.ip', 'audits.created_at']);

        // الفحوص تُحسب مرةً واحدة: كانت تُنفَّذ مرتين (اللوحة ثم الملخّص) —
        // اثنان وثلاثون استعلاماً حيث يكفي ستة عشر. والقوائمُ المحسوبةُ أعلاه
        // (أسرارٌ بائتة، رموزٌ خطرة) تُمرَّر كي لا يُعيد فحصاها استعلامَها
        $posture = SecurityPosture::checks(['vault_stale_ids' => $staleIds, 'api_stale_parts' => $apiParts]);
        $summary = SecurityPosture::summary($posture);

        // **خريطةُ الانكشاف**: من يطاله اختراقُ حسابٍ واحد — بعلاقاتٍ فعلية
        $exposure = \App\Support\SecurityExposure::map();
        $exposureSummary = \App\Support\SecurityExposure::summary($exposure);

        return view('security.index', [
            'kpi' => $kpi, 'sessions' => $sessions, 'failed' => $failed, 'knocking' => $knocking,
            // (WP-4.5 · §2.1) لوحةُ القيادة: ١٥ بطاقةً كلُّها من المصادر الواحدة
            'cards' => $this->commandCards($posture, $summary, $exposureSummary, $kpi,
                count($staleIds), $apiParts, $radarSummary),
            'idleUsers' => $idleUsers, 'staleSecrets' => $staleSecrets,
            'roles' => $roles, 'exports' => $exports,
            'posture' => $posture, 'summary' => $summary,
            'lockdown' => (bool) setting('security.lockdown', false),
            // مفاتيحُ الطوارئ المفصولة — كلٌّ يُدار وحدَه بأثره الظاهر
            'freezeExports' => (string) setting('security.freeze_exports', '0') === '1',
            'freezeTokens' => (string) setting('security.freeze_tokens', '0') === '1',
            'exposure' => $exposure,
            'exposureSummary' => $exposureSummary,
            // رادارُ الكشف الحيّ: محاولاتُ الوصول المرفوضة وتخمينُ الروابط + العناوين الطارقة
            // — الملخّصُ والذكاءُ والعدّاداتُ هي المحسوبةُ أعلاه مرّةً واحدة
            'radar' => $radarSummary, 'denials' => SecurityRadar::recent(),
            'threats' => SecurityRadar::threats(intel: $intel7),
            'events' => \App\Support\SecurityEvents::recent(7, 40, $eventCode ?: null),
            'eventCounts' => $eventCounts,
            'eventCode' => $eventCode,
        ]);
    }

    /**
     * (WP-4.5 · §2.1) **الخمسَ عشرةَ بطاقةً** للوحة القيادة الأمنية — كلُّ بطاقةٍ
     * رقمُها من مصدره الواحد (الوضعية/الانكشاف/الرادار/النتائج/الرموز) وخلفها
     * رابطُ مركزها. `key` ثابتٌ آليّ تُطابق به الاختباراتُ كلَّ بطاقةٍ باستعلامها
     * المرجعيّ — فانحرافُ نسخةٍ محلية يسقط لا يمرّ.
     */
    protected function commandCards(array $posture, array $summary, array $exposureSummary,
        array $kpi, int $staleSecrets, array $apiParts, array $radar): array
    {
        // النتائجُ المفتوحة بعدّةٍ مجمَّعةٍ واحدة — لا عدَّ لكل شدّةٍ على حدة
        $openBySev = \App\Support\SecurityFindings::openCounts();
        $findCrit = (int) ($openBySev['critical'] ?? 0);
        $findHigh = (int) ($openBySev['high'] ?? 0);
        // «المميّزون بلا MFA» رقمُ فحص الوضعية المحسوب لتوّه — والنداءُ المباشر
        // احتياطٌ لصفّ العطل البديل وحدَه (فحصٌ ساقط لا يحمل مفتاحَه)
        $byKey = array_column($posture, null, 'key');
        $privNoMfa = (int) ($byKey['twofa_priv']['n'] ?? count(SecurityPosture::privilegedNoMfaIds()));
        $tokens = \App\Support\ApiTokens::summary($apiParts);

        return [
            ['key' => 'score', 'label' => 'درجة الوضعية', 'value' => $summary['score'] . '٪',
             'tone' => $summary['score'] >= 80 ? 'ok' : ($summary['score'] >= 50 ? 'wn' : 'bad'),
             'url' => route('security.findings'), 'hint' => 'نسبةُ الفحوص السليمة — مع الاتجاه في مركز النتائج'],
            ['key' => 'bad', 'label' => 'فحوص مكسورة', 'value' => $summary['bad'],
             'tone' => $summary['bad'] ? 'bad' : 'ok', 'hint' => 'فحوصُ الوضعية بنبرة «مكسور» أدناه'],
            ['key' => 'wn', 'label' => 'تحذيرات الوضعية', 'value' => $summary['wn'],
             'tone' => $summary['wn'] ? 'wn' : 'ok'],
            ['key' => 'findings_critical', 'label' => 'نتائج حرجة مفتوحة', 'value' => $findCrit,
             'tone' => $findCrit ? 'bad' : 'ok', 'url' => route('security.findings', ['sev' => 'critical'])],
            ['key' => 'findings_high', 'label' => 'نتائج مرتفعة مفتوحة', 'value' => $findHigh,
             'tone' => $findHigh ? 'wn' : 'ok', 'url' => route('security.findings', ['sev' => 'high'])],
            ['key' => 'priv_no_mfa', 'label' => 'مميّزون بلا تحقّق بخطوتين', 'value' => $privNoMfa,
             'tone' => $privNoMfa ? 'bad' : 'ok', 'url' => route('security.privileged')],
            ['key' => 'exposed', 'label' => 'حسابات عالية الانكشاف', 'value' => $exposureSummary['high'],
             'tone' => $exposureSummary['high'] ? 'wn' : 'ok', 'url' => route('security.identity'),
             'hint' => 'من خريطة الانكشاف — خارج نطاق «سليم»'],
            ['key' => 'live_sessions', 'label' => 'جلسات نشطة الآن', 'value' => $kpi['live'],
             'url' => route('security.sessions'),
             'hint' => 'آخر ظهورٍ خلال ' . \App\Support\Sessions::LIVE_MIN . ' دقيقة'],
            ['key' => 'failed7', 'label' => 'دخول فاشل (٧ أيام)', 'value' => $kpi['failed7'],
             'tone' => $kpi['failed7'] ? 'wn' : 'ok',
             'url' => route('security.index', ['ev' => 'AUTH_FAILURE']) . '#secevents'],
            ['key' => 'denied7', 'label' => 'وصول مرفوض (٧ أيام)', 'value' => $radar['total'],
             'tone' => $radar['total'] ? 'wn' : 'ok', 'url' => route('security.ips')],
            ['key' => 'denied_ips', 'label' => 'عناوين مصدر الرفض', 'value' => $radar['ips'],
             'url' => route('security.ips')],
            ['key' => 'stale_secrets', 'label' => 'أسرار لم تُدوَّر', 'value' => $staleSecrets,
             'tone' => $staleSecrets ? 'wn' : 'ok', 'url' => route('security.secrets')],
            ['key' => 'tokens_live', 'label' => 'مفاتيح API سارية', 'value' => $tokens['live'],
             'sub' => 'من ' . $tokens['total'] . ' مفتاحاً', 'url' => route('security.tokens')],
            ['key' => 'tokens_risky', 'label' => 'مفاتيح API خطرة', 'value' => $tokens['risky'],
             'tone' => $tokens['risky'] ? 'bad' : 'ok', 'url' => route('security.tokens'),
             'hint' => 'خاملةٌ أو بلا تاريخ انتهاء — التصنيفُ الواحد ApiTokens'],
            ['key' => 'incidents', 'label' => 'حوادث أمنية مفتوحة', 'value' => $kpi['secinc'],
             'tone' => $kpi['secinc'] ? 'bad' : 'ok', 'url' => route('m.index', 'incidents')],
        ];
    }

    /**
     * **مفاتيحُ الطوارئ المفصولة**: تجميدُ التصدير أو سكِّ الرموز — كلٌّ وحدَه،
     * لا زرٌّ واحدٌ خطر. التفعيلُ (شدُّ الفرامل) فوريٌّ بلا احتكاك؛ ورفعُه (إعادةُ
     * القدرة) يتطلب تأكيدَ الهوية — فالاتجاهُ الخطر وحدَه يُعاد التحقّق قبله.
     */
    public function freeze(string $key)
    {
        $this->gate();
        $map = [
            'exports' => ['security.freeze_exports', 'تصدير البيانات'],
            'tokens'  => ['security.freeze_tokens', 'سكّ مفاتيح API'],
        ];
        abort_unless(isset($map[$key]), 404);
        [$setKey, $label] = $map[$key];
        $on = ! setting($setKey, false);

        // رفعُ التجميد إعادةُ قدرةٍ حسّاسة — يتطلب تأكيدَ الهوية أولاً
        if (! $on && ($resp = hub_require_stepup(route('security.index', absolute: false)))) return $resp;

        // (WP-9.2) على الكاتب الواحد: الإبطالُ وقيدُ التدقيق وصفُّ التاريخ عنده.
        // **والقيدُ هو حدثُ الأمن نفسُه** (‏`SECURITY_POLICY_CHANGED`) لا قيدَ
        // إعداداتٍ ثانياً بجانبه — فالفعلُ يُمرَّر إلى الدفعة لا يُكتب مرّتين.
        // ورفعُ التجميد **حذفُ صفّ**: افتراضيُّ هذا المفتاح «مطفأ» فالحذفُ عودةٌ
        // إليه لا انقلابُ حالة — وهو غيرُ استعادةِ افتراضيٍّ مُعلَن (critic #7).
        \App\Support\Settings::batch('security', function () use ($on, $setKey, $label) {
            $on ? \App\Support\Settings::put($setKey, '1', 'security', "تجميد {$label} من مركز الأمان")
                : \App\Support\Settings::forget($setKey, 'security', "رفع تجميد {$label} من مركز الأمان");
        }, ['action' => $on ? "تجميد {$label} (طوارئ)" : "رفع تجميد {$label}",
            'module' => null, 'name' => auth()->user()->name]);

        return back()->with('ok', $on
            ? "🧊 جُمِّد «{$label}» — يُصَدّ فوراً حتى يُرفع من هنا"
            : "♻️ رُفع تجميد «{$label}»");
    }

    /**
     * إنهاء جلسةٍ بعينها — يسري عند أول طلبٍ لصاحبها، ويُبطل كعكة «تذكّرني».
     * (WP-4.4) عبر سكّة `Sessions` الواحدة — فيُختم أثرُ الإبطال (revoked_at/by/reason).
     */
    public function revokeSession(string $id)
    {
        $this->gate();

        $s = DB::table('sessions_log')->where('id', $id)->first(['id', 'user_id', 'ip']);
        abort_unless($s, 404);

        $u = \App\Models\User::withTrashed()->find($s->user_id);
        if ($u) {
            \App\Support\Sessions::revokeOne($u, $id, 'إنهاء إداري من مركز الأمان');
        } else {
            // صاحبُ الجلسة زال من الجدول — يُوسَم الصفُّ بالختم نفسِه بلا تدوير
            DB::table('sessions_log')->where('id', $id)->update(\App\Support\Sessions::revocationStamp('إنهاء إداري من مركز الأمان'));
        }

        $name = DB::table('users')->where('id', $s->user_id)->value('name');
        hub_audit('إنهاء جلسة', null, null, $name . ' — ' . ($s->ip ?: 'بلا عنوان'));

        return back()->with('ok', '🔌 أُنهيت الجلسة — يخرج الجهاز عند أول طلب، ورمز «تذكّرني» دُوِّر فلا يُبعث منه');
    }

    /**
     * إنهاء كل جلسات مستخدم — للجهاز المفقود وللمغادر.
     * (WP-4.4 · §18) فعلٌ حسّاس: تصعيدُ هويةٍ أولاً، ثم سكّةُ `Sessions` الواحدة
     * (ختمُ الأثر + تدويرُ «تذكّرني»)، وقيدُ تدقيقٍ كما كان.
     */
    public function revokeUser(string $userId)
    {
        $this->gate();
        if ($resp = hub_require_stepup(route('security.index', absolute: false))) return $resp;

        $u = \App\Models\User::withTrashed()->find($userId);
        abort_unless($u, 404);

        $n = \App\Support\Sessions::revokeAll($u, null, 'إنهاء إداري لكل الجلسات');

        hub_audit('إنهاء جلسات مستخدم', null, null, $u->name . " — {$n} جلسة");

        // **حزمةُ استجابة**: إنهاءُ جلسات مستخدمٍ حدثٌ أمنيّ دلاليّ — تعمل عليه
        // التدفقاتُ (تنبيهٌ للمالكين، تليجرام…) كأي حدث. فشلُ الإطلاق لا يُفشل الفعل.
        try { \App\Support\FlowRunner::fire('sessions_revoked', 'users', $u); } catch (\Throwable $e) { report($e); }

        return back()->with('ok', "🔌 أُنهيت {$n} جلسة لـ«{$u->name}» على كل الأجهزة");
    }

    /**
     * (WP-4.4 · §18) إنهاءُ جلسات مستخدمٍ **عدا جلسة المنفّذ الحالية** — لتنظيف
     * أجهزة المالك نفسِه من مركز الجلسات دون إخراج نفسه. لمستخدمٍ آخر لا تُطابق
     * جلسةُ المنفّذ شيئاً فيُنهى الكلُّ — الدلالةُ واحدة: «كلُّ ما ليس جلستي هذه».
     */
    public function revokeOthers(string $userId)
    {
        $this->gate();
        if ($resp = hub_require_stepup(route('security.sessions', absolute: false))) return $resp;

        $u = \App\Models\User::withTrashed()->find($userId);
        abort_unless($u, 404);

        $keep = (string) session('hub.sl', '');
        $n = \App\Support\Sessions::revokeAll($u, $keep !== '' ? $keep : null, 'إنهاء الجلسات الأخرى (إدارة)');

        hub_audit('إنهاء جلسات مستخدم', null, null, $u->name . " — {$n} جلسة (عدا جلسة المنفّذ)");

        return back()->with('ok', "🔌 أُنهيت {$n} جلسة لـ«{$u->name}» — وجلستُك الحالية باقية");
    }

    /* ────────── (WP-4.1/4.2) مركزُ النتائج الأمنية وتاريخُ الوضعية ────────── */

    /**
     * بوابةُ قراءة النتائج (ق١): مالكٌ أو حاملُ علم المراقبة — والفعلُ (إقرار/إغلاق)
     * يبقى للمالك وحدَه في بوابته. توسيعُ monitor هنا مشروطٌ (critic #9): القراءةُ
     * منطَّقةٌ بالشركة ومطموسةُ البريد والعنوان — يقرأ «ما المشكلة» لا «بريدُ مَن».
     */
    protected function findingsReadGate(): void
    {
        abort_unless(hub_is_owner() || hub_monitor(), 403,
            'مركز النتائج الأمنية للمالك أو حامل علم المراقبة');
    }

    /**
     * استعلامُ النتائج منطَّقاً: غيرُ المالك يرى نتائجَ المنظّمة وشركاتِه المسموحة
     * فقط — والشرطُ نفسُه مكتوبٌ **مرّةً واحدة** في `SecurityFindings::scopeCompanies`
     * ويقرؤه العدّادُ (`openCounts`) وصفُّ التدخّل معه، فلا يتباعد ثلاثةُ نُسَخ.
     */
    protected function findingsQuery()
    {
        return \App\Support\SecurityFindings::scopeCompanies(
            DB::table('security_findings'), hub_company_ids());
    }

    /**
     * طمسُ صفٍّ لقارئ المراقبة (critic #9): البريدُ والعنوانُ في العنوان والوصف
     * والتوصية والدليل تُحجب عن غير المالك — والدليلُ يمرّ بالمُطهِّر للجميع
     * (حتى المالك) فلا يُعرَض سرٌّ زُرع في evidence خطأً.
     */
    protected function maskFinding(object $f): object
    {
        $ev = json_decode((string) $f->evidence, true);
        $ev = is_array($ev) ? \App\Support\Redactor::arr($ev) : [];
        if (! hub_is_owner()) {
            foreach (['title', 'description', 'remediation'] as $c) {
                $f->{$c} = \App\Support\SecurityFindings::maskPII((string) ($f->{$c} ?? ''));
            }
            array_walk_recursive($ev, function (&$v) {
                if (is_string($v)) $v = \App\Support\SecurityFindings::maskPII($v);
            });
        }
        $f->evidence = json_encode($ev, JSON_UNESCAPED_UNICODE);

        return $f;
    }

    /** قائمةُ النتائج + اتجاهُ الوضعية (٧/٣٠/٩٠) — قراءةٌ للمالك والمراقب */
    public function findings(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        $st = hub_str($r->query('st'));
        if (! isset(\App\Support\SecurityFindings::STATUSES[$st])) $st = '';
        $sev = hub_str($r->query('sev'));
        if (! in_array($sev, \App\Support\Severity::LEVELS, true)) $sev = '';

        $q = $this->findingsQuery();
        if ($st !== '') $q->where('status', $st);
        if ($sev !== '') $q->where('severity', $sev);

        // الأحدثُ رصداً أولاً بفاصل تعادلٍ حاسم — الترتيبُ حتميٌّ على المحرّكين
        $rows = $q->orderByDesc('last_seen_at')->orderBy('id')
            ->paginate(25)->withQueryString()
            ->through(fn ($f) => $this->maskFinding($f));

        // العدّاداتُ منطَّقةٌ كالقائمة نفسِها — لا رقمَ عن نتائجَ لا يراها القارئ
        $openish = fn () => $this->findingsQuery()->whereIn('status', ['open', 'acknowledged']);
        $kpi = [
            'critical'   => (clone $openish())->where('severity', 'critical')->count(),
            'high'       => (clone $openish())->where('severity', 'high')->count(),
            'unresolved' => $openish()->count(),
            'resolved30' => $this->findingsQuery()->where('status', 'resolved')
                ->where('resolved_at', '>=', now()->subDays(30))->count(),
        ];

        // اتجاهُ الوضعية من اللقطة اليومية — null قبل أول لقطة يعني «لا قياس» لا صفراً
        $days = in_array((int) $r->query('d'), [7, 30, 90], true) ? (int) $r->query('d') : 30;

        return view('security.findings', [
            'rows' => $rows, 'kpi' => $kpi, 'st' => $st, 'sev' => $sev, 'days' => $days,
            'series' => hub_metric_series('security', 'org', 'score', $days),
            'score' => hub_metric_latest('security', 'org', 'score'),
            'isOwner' => hub_is_owner(),
        ]);
    }

    /** تفصيلُ نتيجةٍ واحدة — القراءةُ منطَّقةٌ ومطموسةٌ كالقائمة */
    public function finding(string $id)
    {
        $this->findingsReadGate();

        $f = $this->findingsQuery()->where('id', $id)->first();
        abort_unless($f, 404);

        $ackBy = $f->acknowledged_by
            ? DB::table('users')->where('id', $f->acknowledged_by)->value('name') : null;

        return view('security.finding', [
            'f' => $this->maskFinding($f), 'ackBy' => $ackBy, 'isOwner' => hub_is_owner(),
        ]);
    }

    /** الإقرارُ بنتيجة (ق٤): مالكٌ فقط + قيدُ تدقيق — يعيش في الجدول لا في signal_states */
    public function findingAck(string $id)
    {
        $this->gate();

        $f = DB::table('security_findings')->where('id', $id)->first(['id', 'code', 'title', 'status']);
        abort_unless($f, 404);
        if ($f->status !== 'open') {
            return back()->with('ok', 'النتيجةُ ليست مفتوحةً — لا إقرارَ يلزم');
        }

        DB::table('security_findings')->where('id', $id)->update([
            'status' => 'acknowledged', 'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(), 'updated_at' => now(),
        ]);
        hub_audit('إقرار نتيجة أمنية', null, null, $f->title . ' — ' . $f->code);

        return back()->with('ok', '👁️ أُقرّ بالنتيجة — تبقى مرصودةً حتى يزول شرطُها أو تُغلق');
    }

    /** إغلاقُ نتيجةٍ يدوياً: مالكٌ فقط + قيدُ تدقيق — والتسويةُ تُعيد فتحَها إن بقي الشرط */
    public function findingResolve(string $id)
    {
        $this->gate();

        $f = DB::table('security_findings')->where('id', $id)->first(['id', 'code', 'title', 'status']);
        abort_unless($f, 404);
        if ($f->status === 'resolved') {
            return back()->with('ok', 'النتيجةُ محلولةٌ أصلاً');
        }

        DB::table('security_findings')->where('id', $id)->update([
            'status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now(),
        ]);
        hub_audit('إغلاق نتيجة أمنية', null, null, $f->title . ' — ' . $f->code);

        return back()->with('ok', '✅ أُغلقت النتيجة — إن بقي شرطُها ستُعيد اللقطةُ اليومية فتحَها');
    }

    /* ────────── (WP-4.3) مركزُ خطر الهويّة + مراجعةُ الامتيازات ────────── */

    /**
     * وضعُ عرض البريد لهذا القارئ (critic #9): المالكُ كامل؛ وغيرُه يمرّ بقيد
     * `hub_field_mode` على حقل البريد في وحدة المستخدمين — `hide` يحجب الخانةَ
     * كلياً، وما دونه **طمسٌ إلزاميّ** (mask): قارئُ المراقبة يجيب «أيُّ حسابٍ
     * خطر ولماذا» لا «بريدُ مَن وعنوانُ مَن». والعناوينُ الشبكية لا تُعرض أصلاً
     * في هاتين الشاشتين — أعدادٌ لا قيم.
     */
    protected function emailMode(): string
    {
        if (hub_is_owner()) return '';

        return hub_field_mode(auth()->user(), 'users', 'email') === 'hide' ? 'hide' : 'mask';
    }

    /** ترقيمُ مصفوفةٍ محسوبة — القوائمُ هنا تُركَّب في الذاكرة لا من استعلامٍ واحد */
    protected function paginateArray(array $rows, \Illuminate\Http\Request $r, int $per = 25)
    {
        $page = max(1, (int) $r->query('page', 1));

        return new \Illuminate\Pagination\LengthAwarePaginator(
            array_slice($rows, ($page - 1) * $per, $per), count($rows), $per, $page,
            ['path' => $r->url(), 'query' => $r->query()]);
    }

    /** خريطةُ خطر الهويّة: كلُّ حسابٍ بدرجته وعواملِه المفسَّرة — قراءةٌ للمالك والمراقب */
    public function identity(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        $rows = \App\Support\IdentityRisk::map(hub_company_ids());

        // فرزٌ من رؤوس cc/th — والافتراضُ الأعلى خطراً أولاً بفاصل تعادلٍ حاسم (id)
        $sort = in_array($r->query('sort'), ['score', 'name', 'login', 'failed'], true)
            ? (string) $r->query('sort') : 'score';
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($sort, $dir) {
            $k = fn ($x) => match ($sort) {
                'name'   => (string) $x['name'],
                'login'  => (string) ($x['last_login_at'] ?? ''),
                'failed' => $x['failed30'],
                default  => $x['score'],
            };

            return ($dir * ($k($a) <=> $k($b))) ?: ($a['id'] <=> $b['id']);
        });

        $bands = \App\Support\Risk::bands();
        $kpi = [
            'total' => count($rows),
            'high'  => count(array_filter($rows, fn ($x) => $x['score'] >= $bands['high'])),
            'no2fa' => count(array_filter($rows, fn ($x) => ! $x['twofa'])),
            'priv'  => count(array_filter($rows, fn ($x) => $x['is_owner'] || $x['privileged'])),
        ];

        return view('security.identity', [
            'rows' => $this->paginateArray($rows, $r), 'kpi' => $kpi,
            'emailMode' => $this->emailMode(), 'isOwner' => hub_is_owner(),
        ]);
    }

    /** مراجعةُ الامتيازات: الفئاتُ الثماني — قراءةٌ للمالك والمراقب، والأفعالُ للمالك */
    public function privileged(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        // ذيلُ WP-4.3: نتائجُ الكيان (مستخدم) تُسوّى هنا حيث تُولد مُعدّاتُها —
        // كتابةُ رصدٍ فقط (المالكُ وحدَه يشغّلها)، ولا امتيازَ يُسحب تلقائياً أبداً
        if (hub_is_owner()) \App\Support\IdentityRisk::reconcileUserFindings();

        $review = \App\Support\IdentityRisk::review(hub_company_ids());

        $cat = (string) $r->query('cat', 'owners');
        if (! isset(\App\Support\IdentityRisk::CATEGORIES[$cat])) $cat = 'owners';

        // نتائجُ الإقرار الحيّة لهؤلاء المستخدمين — نفسُ سكّة security_findings (ق٤)
        $findings = collect();
        if (Schema::hasTable('security_findings')) {
            $findings = DB::table('security_findings')
                ->where('code', 'twofa_priv')->where('entity_type', 'user')
                ->orderBy('entity_id')->orderBy('id')
                ->get(['id', 'entity_id', 'status'])->keyBy('entity_id');
        }

        return view('security.privileged', [
            'cats' => $review['cats'], 'cat' => $cat,
            'rows' => $this->paginateArray($review['cats'][$cat], $r),
            'findings' => $findings,
            'emailMode' => $this->emailMode(), 'isOwner' => hub_is_owner(),
        ]);
    }

    /* ────────── (WP-4.4) الجلسات والأجهزة وذكاءُ العناوين ────────── */

    /**
     * المستخدمون المرئيّون لهذا القارئ (critic #9): المالكُ بلا حصر (null)،
     * والمنطَّقُ بشركاتٍ يرى مستخدمي شركاتِه ومن بلا قائمةٍ (وصولٌ واسع) —
     * نفسُ دلالة IdentityRisk::map حرفياً.
     *
     * @return array<int, string>|null
     */
    protected function visibleUserIds(): ?array
    {
        $cids = hub_company_ids();
        if ($cids === null) return null;

        return \App\Models\User::withTrashed()->get(['id', 'companies'])
            ->filter(function ($u) use ($cids) {
                $cos = array_values(array_filter(array_map('strval', is_array($u->companies) ? $u->companies : [])));

                return ! $cos || array_intersect($cos, $cids);
            })->pluck('id')->map(fn ($v) => (string) $v)->values()->all();
    }

    /** هل يُطمس عنوانُ الشبكة لهذا القارئ؟ — المالكُ وحدَه يقرأ العناوين صريحة */
    protected function ipMasked(): bool
    {
        return ! hub_is_owner();
    }

    /**
     * مركزُ الجلسات (security.sessions): مرشِّحات مستخدم/عنوان/حالة/مدى، ترتيبٌ
     * حتميّ (last_seen_at desc, id desc — القرعةُ على MySQL كانت تُخفي النقص)،
     * متصفّح/نظام عبر `Devices::describe` الواحد، وعمرُ الجلسة، ووسمُ «غير معتادة»
     * من ذاكرة `user_ips` القائمة. القراءةُ للمالك أو monitor مطموساً ومنطَّقاً.
     */
    public function sessions(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        $range = hub_range($r, '7d');
        $uid = hub_str($r->query('u'));
        $fip = hub_str($r->query('ip'));
        $state = in_array($r->query('state'), ['live', 'revoked'], true) ? (string) $r->query('state') : '';

        $liveSince = now()->subMinutes(\App\Support\Sessions::LIVE_MIN);
        $vis = $this->visibleUserIds();

        $base = function () use ($range, $uid, $fip, $vis) {
            $q = DB::table('sessions_log');
            $range->apply($q, 'sessions_log.last_seen_at');
            if ($uid !== '') $q->where('sessions_log.user_id', $uid);
            if ($fip !== '') $q->where('sessions_log.ip', $fip);
            if ($vis !== null) $q->whereIn('sessions_log.user_id', $vis);

            return $q;
        };

        $q = $base()->leftJoin('users', 'users.id', '=', 'sessions_log.user_id');
        if ($state === 'live') $q->where('sessions_log.revoked', false)->where('sessions_log.last_seen_at', '>=', $liveSince);
        if ($state === 'revoked') $q->where('sessions_log.revoked', true);

        $rows = $q->orderByDesc('sessions_log.last_seen_at')->orderByDesc('sessions_log.id')
            ->paginate(25, ['sessions_log.*', 'users.name as uname', 'users.email as uemail'])
            ->withQueryString();

        // الألفةُ دفعةً واحدة لعناوين الصفحة — لا حلقةَ استعلامٍ لكل صفّ
        $familiar = \App\Support\Devices::familiarMap($rows->getCollection()->pluck('user_id')->all());
        $mine = (string) session('hub.sl', '');
        $rows->getCollection()->transform(function ($s) use ($liveSince, $familiar, $mine) {
            $s->live = ! $s->revoked && $s->last_seen_at && (string) $s->last_seen_at >= $liveSince->toDateTimeString();
            $s->mine = $mine !== '' && (string) $s->id === $mine;
            [$s->browser] = \App\Support\Devices::describe((string) ($s->user_agent ?: $s->device));
            $s->age = \Illuminate\Support\Carbon::parse($s->started_at)
                ->diffForHumans($s->last_seen_at ? \Illuminate\Support\Carbon::parse($s->last_seen_at) : now(), true);
            $s->unusual = $s->ip && ! \App\Support\Devices::isFamiliar($familiar, (string) $s->user_id, (string) $s->ip);

            return $s;
        });

        $kpi = [
            'live'    => (int) $base()->where('revoked', false)->where('last_seen_at', '>=', $liveSince)->count(),
            'revoked' => (int) $base()->where('revoked', true)->count(),
            'users'   => (int) $base()->distinct()->count('user_id'),
            'ips'     => (int) $base()->whereNotNull('ip')->distinct()->count('ip'),
        ];

        // قائمةُ المستخدمين للمرشِّح — منطَّقةٌ كالصفوف نفسِها (أسماءٌ لا بريد)
        $users = \App\Models\User::whereNull('deleted_at')
            ->when($vis !== null, fn ($w) => $w->whereIn('id', $vis))
            ->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return view('security.sessions', [
            'rows' => $rows, 'kpi' => $kpi, 'range' => $range, 'users' => $users,
            'u' => $uid, 'fip' => $fip, 'state' => $state,
            'emailMode' => $this->emailMode(), 'ipMasked' => $this->ipMasked(), 'isOwner' => hub_is_owner(),
        ]);
    }

    /**
     * ثقةُ الأجهزة (security.devices): معلّق/موثوق/مبطَل من `user_devices` بما
     * فيها المحذوفةُ ناعماً (الإبطالُ يحذف ناعماً فلا يختفي من المراجعة)، و«مريب»
     * وسمٌ مشتقٌّ — معلّقٌ من عنوانٍ غير مألوفٍ لصاحبه. **لا بصمةَ جهازٍ جديدة.**
     */
    public function devices(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        $t = in_array($r->query('t'), ['known', 'new', 'suspicious', 'revoked'], true) ? (string) $r->query('t') : '';
        $vis = $this->visibleUserIds();

        if (! Schema::hasTable('user_devices')) {
            return view('security.devices', ['rows' => $this->paginateArray([], $r), 'kpi' => ['known' => 0, 'new' => 0, 'suspicious' => 0, 'revoked' => 0],
                't' => $t, 'emailMode' => $this->emailMode(), 'ipMasked' => $this->ipMasked(), 'isOwner' => hub_is_owner()]);
        }

        // كلُّ الأجهزة (بالمحذوف ناعماً) بترتيبٍ حتميّ — ثم يُشتق الوسم في الذاكرة
        $all = DB::table('user_devices')
            ->leftJoin('users', 'users.id', '=', 'user_devices.user_id')
            ->when($vis !== null, fn ($w) => $w->whereIn('user_devices.user_id', $vis))
            ->orderByDesc('user_devices.last_seen_at')->orderByDesc('user_devices.id')
            ->limit(1000)
            ->get(['user_devices.*', 'users.name as uname', 'users.email as uemail']);

        $familiar = \App\Support\Devices::familiarMap($all->pluck('user_id')->all());
        $all->transform(function ($d) use ($familiar) {
            $d->revokedState = $d->deleted_at !== null || $d->trust === 'مبطَل';
            // مريب: معلّقٌ (غيرُ مراجَع) آخرُ عنوانه غيرُ مألوفٍ لصاحبه — من الذاكرة القائمة
            $d->suspicious = ! $d->revokedState && $d->trust === 'معلّق'
                && $d->last_ip && ! \App\Support\Devices::isFamiliar($familiar, (string) $d->user_id, (string) $d->last_ip);

            return $d;
        });

        $kpi = [
            'known'      => $all->filter(fn ($d) => ! $d->revokedState && $d->trust === 'موثوق')->count(),
            'new'        => $all->filter(fn ($d) => ! $d->revokedState && $d->trust === 'معلّق')->count(),
            'suspicious' => $all->filter(fn ($d) => $d->suspicious)->count(),
            'revoked'    => $all->filter(fn ($d) => $d->revokedState)->count(),
        ];

        $rows = $all->filter(fn ($d) => match ($t) {
            'known'      => ! $d->revokedState && $d->trust === 'موثوق',
            'new'        => ! $d->revokedState && $d->trust === 'معلّق',
            'suspicious' => $d->suspicious,
            'revoked'    => $d->revokedState,
            default      => true,
        })->values()->all();

        return view('security.devices', [
            'rows' => $this->paginateArray($rows, $r), 'kpi' => $kpi, 't' => $t,
            'emailMode' => $this->emailMode(), 'ipMasked' => $this->ipMasked(), 'isOwner' => hub_is_owner(),
        ]);
    }

    /**
     * ذكاءُ العناوين (security.ips): القارئُ الواحد `SecurityRadar::intel` —
     * لا نسخةَ تجميعٍ محلية. الترتيبُ حتميّ (آخرُ ظهورٍ ثم العنوان الفريد).
     */
    public function ips(\Illuminate\Http\Request $r)
    {
        $this->findingsReadGate();

        $range = hub_range($r, '7d');
        $rows = SecurityRadar::intel($range->from, $range->to)->all();

        $kpi = [
            'total'   => count($rows),
            'flagged' => count(array_filter($rows, fn ($x) => in_array($x->label['key'], ['suspicious', 'multi'], true))),
            'fails'   => array_sum(array_map(fn ($x) => (int) $x->fails, $rows)),
            'denials' => array_sum(array_map(fn ($x) => (int) $x->denials, $rows)),
        ];

        // فرزٌ من رؤوس cc/th — وفاصلُ التعادل العنوانُ نفسُه (فريدٌ فالترتيب حتميّ)
        $sort = in_array($r->query('sort'), ['last', 'fails', 'denials', 'success'], true)
            ? (string) $r->query('sort') : 'last';
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 1 : -1;
        usort($rows, function ($a, $b) use ($sort, $dir) {
            $k = fn ($x) => match ($sort) {
                'fails'   => (int) $x->fails,
                'denials' => (int) $x->denials,
                'success' => (int) $x->success,
                default   => (string) $x->last_seen,
            };

            return ($dir * ($k($a) <=> $k($b))) ?: (strcmp((string) $a->ip, (string) $b->ip));
        });

        return view('security.ips', [
            'rows' => $this->paginateArray($rows, $r), 'kpi' => $kpi, 'range' => $range,
            'ipMasked' => $this->ipMasked(), 'isOwner' => hub_is_owner(),
        ]);
    }

    /** تفصيلُ عنوانٍ واحد: الأرقامُ والوسم وأثرُه الحديث ومستخدموه المعروفون */
    public function ip(\Illuminate\Http\Request $r, string $ip)
    {
        $this->findingsReadGate();
        abort_unless(preg_match('/^[0-9A-Fa-f:.]{3,45}$/', $ip) === 1, 404);

        $range = hub_range($r, '30d');
        $row = SecurityRadar::intel($range->from, $range->to, $ip)->firstWhere('ip', $ip);

        $vis = $this->visibleUserIds();
        $masked = $this->ipMasked();

        // مستخدمون معروفون من هذا العنوان (ذاكرةُ user_ips) — منطَّقين للقارئ المحدود.
        // أوّلُ الظهور من العمود الجديد حين مُلئ، وإلا «—» (لا اختلاقَ تاريخٍ رجعيّ)
        $known = Schema::hasTable('user_ips')
            ? DB::table('user_ips')->where('ip', $ip)
                ->when($vis !== null, fn ($w) => $w->whereIn('user_ips.user_id', $vis))
                ->leftJoin('users', 'users.id', '=', 'user_ips.user_id')
                ->orderByDesc('user_ips.hits')->orderBy('user_ips.id')->limit(20)
                ->get(['user_ips.hits', 'user_ips.last_seen_at',
                       DB::raw(hub_has_col('user_ips', 'first_seen_at') ? 'user_ips.first_seen_at' : 'NULL as first_seen_at'),
                       'users.name as uname', 'users.email as uemail'])
            : collect();

        // أحدثُ الأثر: قيودُ تدقيقٍ بهذا العنوان + منعٌ مسجَّل — بترتيبٍ حتميّ
        $trail = DB::table('audits')->where('ip', $ip)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(15)
            ->get(['action', 'name', 'created_at']);
        if ($masked) {
            // بريدُ محاولة الدخول يسكن عمودَ name — يُطمس لغير المالك (critic #9)
            $trail->transform(function ($a) {
                $a->name = \App\Support\SecurityFindings::maskPII((string) $a->name);

                return $a;
            });
        }
        $denials = Schema::hasTable('access_denials')
            ? DB::table('access_denials')->where('ip', $ip)
                ->orderByDesc('id')->limit(10)->get(['kind', 'method', 'path', 'created_at'])
            : collect();

        return view('security.ip', [
            'ip' => $ip, 'row' => $row, 'range' => $range, 'known' => $known,
            'trail' => $trail, 'denials' => $denials,
            'emailMode' => $this->emailMode(), 'ipMasked' => $masked, 'isOwner' => hub_is_owner(),
        ]);
    }

    /* ────────── (WP-4.6) تفصيلُ الحدث الأمنيّ الواحد ────────── */

    /**
     * شدّةُ الحدث ⇐ مستوى الحادثة (مفردات وحدة incidents) — لتعبئة «افتح حادثة».
     * (أرضيةُ PHP 8.2: ثابتٌ بلا تنميط)
     */
    protected const EVENT_INCIDENT_SEVERITY = [
        'high' => 'عالي', 'warning' => 'متوسط', 'notice' => 'منخفض', 'info' => 'منخفض',
    ];

    /**
     * تفصيلُ حدثٍ أمنيّ واحد بمفتاح **المصدر+المعرّف** (§42.9 · §42.10): من/ماذا/
     * متى/من أين/معرّفُ الطلب (⇐ `system.trace`) والحادثةُ المرتبطة. السجلُّ مشتقٌّ
     * فالمعرّفُ معرّفُ الجدول الأصليّ (`audits.id` أو `access_denials.id`).
     *
     * الحارسُ حارسُ المركز (مالكٌ أو monitor)؛ وغيرُ المالك **مطموسٌ ومنطَّق**
     * (critic #9): خارجُ نطاق شركاته ⇒ ٤٠٤ — فلا يُعلَم أصلاً أنّ الصفَّ موجود.
     */
    public function event(string $source, string $id)
    {
        $this->findingsReadGate();

        $e = \App\Support\SecurityEvents::find($source, $id);
        abort_unless($e !== null, 404);

        // التنطيق (critic #9): المحصورُ بشركاتٍ يرى أحداثَ مستخدمي شركاته فقط —
        // وحدثُ زائرٍ بلا مستخدمٍ خارجُ نطاقه أيضاً (المالكُ وحدَه يقرأ الطارقين)
        $vis = $this->visibleUserIds();
        if ($vis !== null && ! in_array((string) ($e['user_id'] ?? ''), $vis, true)) abort(404);

        $masked = $this->ipMasked();
        if ($masked) {
            // الطمسُ الإلزاميّ لغير المالك: البريدُ يسكن name/path/detail أحياناً
            // (محاولةُ الدخول تكتب بريدَها في name) — فيمرّ كلُّ نصٍّ حرٍّ بالقناع
            foreach (['name', 'path', 'detail', 'email'] as $k) {
                if (isset($e[$k]) && $e[$k] !== null && $e[$k] !== '') {
                    $e[$k] = \App\Support\SecurityFindings::maskPII((string) $e[$k]);
                }
            }
        }

        // الحادثةُ المرتبطة: بالمرجع الآليّ الذي يزرعه زرُّ «افتح حادثة» في الملاحظات،
        // أو بمعرّف الطلب نفسِه (حوادثُ hub_security_incident المولَّدة — WP-1.4)
        $marker = 'security-event:' . $source . ':' . $e['id'];
        $incident = null;
        if (Schema::hasTable('incidents')) {
            $incident = DB::table('incidents')->whereNull('deleted_at')
                ->where('notes', 'like', '%' . $marker . '%')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->first(['id', 'title', 'severity', 'status']);
            if (! $incident && ! empty($e['request_id']) && hub_has_col('incidents', 'request_id')) {
                $incident = DB::table('incidents')->whereNull('deleted_at')
                    ->where('request_id', $e['request_id'])
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->first(['id', 'title', 'severity', 'status']);
            }
        }

        // «افتح حادثة»: تعبئةٌ مسبقة عبر آليّة m.create القائمة (سلسلة الاستعلام —
        // ModuleController::create) فلا مسارَ كتابةٍ جديداً والحفظُ يمرّ ببوّابات
        // الوحدة نفسِها. القيمُ من النسخة المطموسة أعلاه — فرابطُ قارئ المراقبة
        // لا يحمل بريداً ولا عنواناً. ويُعرَض فقط ما دامت لا حادثةَ مرتبطةً بعد —
        // فالزرُّ يُنشئ واحدةً لا سلسلةَ نسخ.
        $createUrl = null;
        if (! $incident && hub_can(auth()->user(), 'incidents', 'a')) {
            $createUrl = route('m.create', ['module' => 'incidents',
                'title'     => 'حادثة أمنية: ' . $e['label'],
                'severity'  => self::EVENT_INCIDENT_SEVERITY[$e['severity']] ?? 'متوسط',
                'startedAt' => str_replace(' ', 'T', mb_substr((string) $e['at'], 0, 16)),
                'affected'  => 'حدث أمنيّ ' . $e['code'] . ' — للتحقيق البشريّ لا للعقاب الآليّ',
                'notes'     => implode("\n", array_filter([
                    'الدليل من السجلّ الأمنيّ الموحَّد:',
                    'الحدث: ' . $e['label'] . ' (' . $e['code'] . ')',
                    $e['name'] ? 'التفصيل: ' . $e['name'] : null,
                    $e['user'] ? 'الحساب: ' . $e['user'] : null,
                    (! $masked && $e['ip']) ? 'العنوان: ' . $e['ip'] : null,
                    ! empty($e['request_id']) ? 'معرّف الطلب: ' . $e['request_id'] : null,
                    'المرجع الآليّ (يربط الحادثةَ بالحدث — لا يُحذف): ' . $marker,
                ])),
            ]);
        }

        return view('security.event', [
            'e' => $e, 'incident' => $incident, 'createUrl' => $createUrl,
            'emailMode' => $this->emailMode(), 'ipMasked' => $masked, 'isOwner' => hub_is_owner(),
        ]);
    }

    /* ────────── (WP-4.5) مركزُ رموز API + صحّةُ الأسرار ────────── */

    /**
     * مركزُ رموز API (security.tokens): **للمالك وحدَه** — بياناتُ اعتماد لا تُطمس
     * بل تُحجب (critic #9). لكل رمز: اسمٌ ومالكٌ ونطاقاتٌ وإنشاءٌ وانتهاءٌ وآخرُ
     * استعمالٍ وآخرُ عنوانٍ وعمرٌ وامتيازٌ وحالةٌ من التصنيف الواحد
     * `ApiTokens::classify`. **لا قيمةَ رمزٍ ولا بصمتَه في الصفحة أبداً.**
     */
    public function tokens(\Illuminate\Http\Request $r)
    {
        $this->gate();

        // ذيلُ WP-4.5 (critic #24): نتائجُ الكيانات تُسوّى حيث تُولد مُعدّاتُها —
        // نمطُ security.privileged نفسُه (رصدٌ وإغلاقٌ آليّ، لا فعلٌ على الرموز)
        \App\Support\SecurityFindings::reconcileEntityFindings();

        // فرزٌ من رؤوس cc/th بترتيبٍ حتميّ (فاصلُ التعادل id) — الافتراضُ الأحدثُ سكّاً
        $sorts = ['created' => 'api_tokens.created_at', 'used' => 'api_tokens.last_used_at',
                  'expires' => 'api_tokens.expires_at', 'name' => 'api_tokens.name'];
        $sort = isset($sorts[(string) $r->query('sort')]) ? (string) $r->query('sort') : 'created';
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 'asc' : 'desc';

        $hasCols = hub_has_col('api_tokens', 'revoked_at');
        $rows = DB::table('api_tokens')
            ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
            ->orderBy($sorts[$sort], $dir)->orderBy('api_tokens.id', $dir)
            ->paginate(25, ['api_tokens.id', 'api_tokens.name', 'api_tokens.user_id',
                'api_tokens.scopes', 'api_tokens.allowed_ips', 'api_tokens.created_at',
                'api_tokens.expires_at', 'api_tokens.last_used_at',
                DB::raw(hub_has_col('api_tokens', 'last_ip') ? 'api_tokens.last_ip' : 'NULL as last_ip'),
                DB::raw($hasCols ? 'api_tokens.revoked_at' : 'NULL as revoked_at'),
                DB::raw($hasCols ? 'api_tokens.revoked_by' : 'NULL as revoked_by'),
                'users.name as uname', 'users.role_id as urole'])
            ->withQueryString();

        // امتيازُ صاحب الرمز دفعةً واحدة — لا استعلامَ لكل صفّ
        $privRoles = array_map('strval', \App\Support\ApiTokens::privilegedRoleIds());
        $rows->getCollection()->transform(function ($t) use ($privRoles) {
            $t->privileged = in_array((string) $t->urole, $privRoles, true);
            $t->full = \App\Support\ApiTokens::fullScope($t->scopes);
            $t->status = \App\Support\ApiTokens::classify($t);

            return $t;
        });

        return view('security.tokens', [
            'rows' => $rows, 'sort' => $sort, 'dir' => $dir,
            'summary' => \App\Support\ApiTokens::summary(),
            'unusedDays' => \App\Support\ApiTokens::unusedDays(),
            'frozen' => (string) setting('security.freeze_tokens', '0') === '1',
        ]);
    }

    /**
     * إبطالُ رمزٍ إدارياً: مالكٌ + تصعيدُ اعتماد (§18) + قيدُ تدقيقٍ بصيغة
     * `إبطال مفتاح API` (رمزُ SecurityEvents القائم API_CREDENTIAL_REVOKED).
     * إبطالٌ **ناعم** (revoked_at/by) فيبقى الصفُّ شاهداً — و`ApiAuth` يرفضه فوراً.
     * **لا تدويرَ لرمز مستخدمٍ آخر** (ق٧): التدويرُ يسكّ نصّاً صريحاً كان سيصل المدير.
     */
    public function revokeToken(string $id)
    {
        $this->gate();
        if ($resp = hub_require_credential_stepup(route('security.tokens', absolute: false))) return $resp;
        abort_unless(hub_has_col('api_tokens', 'revoked_at'), 423,
            'أعمدةُ الإبطال لم تُهاجَر بعد — شغّل php artisan migrate');

        $t = DB::table('api_tokens')->where('id', $id)->first(['id', 'name', 'user_id', 'revoked_at']);
        abort_unless($t, 404);
        if ($t->revoked_at) {
            return back()->with('ok', 'المفتاحُ مُبطَلٌ أصلاً');
        }

        DB::table('api_tokens')->where('id', $id)
            ->update(['revoked_at' => now(), 'revoked_by' => auth()->id()]);

        $uname = DB::table('users')->where('id', $t->user_id)->value('name');
        hub_audit('إبطال مفتاح API', null, $id, $t->name . ' — لصاحبه ' . ($uname ?: 'مستخدم محذوف'));

        return back()->with('ok', "🔌 أُبطل المفتاح «{$t->name}» — يُرفض عند أول طلبٍ به");
    }

    /**
     * صحّةُ الأسرار (security.secrets): **للمالك وحدَه**. لكل سرٍّ: عنوانٌ ونوعٌ
     * ومالكٌ وآخرُ تدويرٍ وعمرٌ واستعمالٌ (قيودُ «عرض حساس» عبر `audits(module,
     * record_id)`) وخطرٌ — **ولا قيمةَ ولا بصمةَ** (حتى النصُّ المشفَّر لا يُقرأ أصلاً).
     */
    public function secrets(\Illuminate\Http\Request $r)
    {
        $this->gate();

        // ذيلُ WP-4.5: نتائجُ الأسرار البائتة تُسوّى حيث تُولد مُعدّاتُها
        \App\Support\SecurityFindings::reconcileEntityFindings();

        $hasRot = hub_has_col('vault_secrets', 'rotated_at');
        $staleIds = array_map('strval', SecurityPosture::vaultStaleIds());

        $sorts = ['title' => 'vault_secrets.title', 'type' => 'vault_secrets.type',
                  'created' => 'vault_secrets.created_at'];
        $sort = isset($sorts[(string) $r->query('sort')]) ? (string) $r->query('sort') : '';
        $dir = strtolower((string) $r->query('dir')) === 'asc' ? 'asc' : 'desc';

        // الافتراض: الأقدمُ تدويراً أولاً (الأخطر) — والفرزُ الصريح يغلبه، وبفاصل id دائماً
        $q = DB::table('vault_secrets')->whereNull('vault_secrets.deleted_at')
            ->leftJoin('users', 'users.id', '=', 'vault_secrets.created_by');
        if ($sort !== '') $q->orderBy($sorts[$sort], $dir)->orderBy('vault_secrets.id', $dir);
        elseif ($hasRot) $q->orderByRaw('COALESCE(vault_secrets.rotated_at, vault_secrets.created_at) asc')->orderBy('vault_secrets.id');
        else $q->orderBy('vault_secrets.updated_at')->orderBy('vault_secrets.id');

        // **قائمةُ الأعمدة صريحةٌ عمداً**: secret_cipher لا يُقرأ من القاعدة أصلاً
        $rows = $q->paginate(25, ['vault_secrets.id', 'vault_secrets.title', 'vault_secrets.type',
                'vault_secrets.company_id', 'vault_secrets.created_at', 'vault_secrets.archived',
                DB::raw($hasRot ? 'vault_secrets.rotated_at' : 'NULL as rotated_at'),
                'users.name as uname'])
            ->withQueryString();

        // الاستعمالُ دفعةً واحدة لصفوف الصفحة: قيودُ «عرض حساس» من مفردات
        // SECRET_REVEALED الواحدة — فهرسُ audits.record_id يقود الاستعلام
        $ids = $rows->getCollection()->pluck('id')->map(fn ($v) => (string) $v)->all();
        $usage = $ids ? DB::table('audits')->where('module', 'vault')
            ->whereIn('action', \App\Support\SecurityEvents::actions('SECRET_REVEALED'))
            ->whereIn('record_id', $ids)
            ->groupBy('record_id')->orderBy('record_id')
            ->selectRaw('record_id, COUNT(*) as n')->pluck('n', 'record_id') : collect();

        $staleDays = max(1, (int) setting('security.secret_stale_days', 180));
        $rows->getCollection()->transform(function ($s) use ($usage, $staleIds) {
            $s->usage = (int) ($usage[(string) $s->id] ?? 0);
            $s->rotatedRef = $s->rotated_at ?: $s->created_at;   // «لم يُدوَّر قط» = من الإنشاء
            $s->stale = in_array((string) $s->id, $staleIds, true);

            return $s;
        });

        return view('security.secrets', [
            'rows' => $rows, 'sort' => $sort, 'dir' => $dir, 'staleDays' => $staleDays,
            'staleCount' => count($staleIds),
        ]);
    }

    /** قفل الطوارئ: تعليق كل الوصول عدا المالكين — ويُسجَّل في التدقيق */
    public function lockdown()
    {
        $this->gate();
        // فعلٌ حرج (تعليقُ الوصول للجميع) — يتطلب تأكيدَ الهوية أولاً
        if ($resp = hub_require_stepup(route('security.index', absolute: false))) return $resp;
        $on = ! setting('security.lockdown', false);

        // (WP-9.2) على الكاتب الواحد — والفعلُ الأمنيّ يُمرَّر للدفعة فيبقى قيداً واحداً
        \App\Support\Settings::batch('security', function () use ($on) {
            $on ? \App\Support\Settings::put('security.lockdown', '1', 'security', 'قفل الطوارئ من مركز الأمان')
                : \App\Support\Settings::forget('security.lockdown', 'security', 'رفع قفل الطوارئ من مركز الأمان');
        }, ['action' => $on ? 'تفعيل قفل الطوارئ' : 'رفع قفل الطوارئ',
            'module' => null, 'name' => auth()->user()->name]);

        return back()->with('ok', $on ? '🔒 فُعّل قفل الطوارئ — الجلسات غير المالكة عُلّقت فوراً' : '🔓 رُفع قفل الطوارئ');
    }

    /* ────────── (Work OS · الطور I · WP-I.3 · §39/§42) قواعدُ الحظر والسماح ────────── */

    /**
     * بوابةُ شاشة القواعد: **مالكٌ وحدَه، وغيرُه ٤٠٤ لا ٤٠٣** — قواعدُ الدفاع
     * سطحٌ أمنيٌّ لا يُثبَت وجودُه لغير صاحبه (دلالةُ findScoped/PortalGuard،
     * لا دلالةُ gate() العامة): حسابُ عميلٍ يصدّه PortalGuard قبلنا أصلاً،
     * والموظفُ الداخليُّ غيرُ المالك يلقى الجوابَ نفسَه هنا.
     */
    protected function blocksGate(): void
    {
        abort_unless(hub_is_owner(), 404);
    }

    /**
     * شاشةُ قواعد IP (security.blocks — تمتدّ عائلةَ security.ips/ip): القائمةُ
     * بترتيبٍ حتميّ، وبطاقةُ الحالة **الصادقة**: حظرُ التطبيق فعّالٌ دائماً
     * (السلطةُ القاطعة — IpDefense بلا تبعية)، وحظرُ حافّة الشبكة «غير مُهيّأ»
     * ما لم يكتمل اعتمادٌ حقيقيّ (EdgeDefense::status — عائلة C15).
     */
    public function blocks(\Illuminate\Http\Request $r)
    {
        $this->blocksGate();

        $now = now();
        $live = fn ($q) => $q->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', $now));

        $kpi = [
            'blocks' => $live(\App\Models\IpRule::query()->where('mode', 'block'))->count(),
            'allows' => $live(\App\Models\IpRule::query()->where('mode', 'allow'))->count(),
            'auto'   => $live(\App\Models\IpRule::query()->where('origin', 'auto'))->count(),
            'hits'   => (int) \App\Models\IpRule::query()->sum('hits'),
        ];

        // الأحدثُ قراراً أولاً بفاصل تعادلٍ حاسم (درسُ CLAUDE.md) — والصفوفُ كلُّها
        // تُعرض بما فيها المنتهي والملغى: الشاشةُ ذاكرةُ القرار لا المجموعةَ الحيّة وحدها
        $rows = \App\Models\IpRule::query()->with(['author', 'revoker'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return view('security.blocks', [
            'rows' => $rows, 'kpi' => $kpi, 'edge' => \App\Support\EdgeDefense::status(),
            'prefill' => preg_match('/^[0-9A-Fa-f:.\/]{3,64}$/', (string) $r->query('ip')) === 1
                ? (string) $r->query('ip') : '',
        ]);
    }

    /** صيغةُ القاعدة المقبولة إدارياً: عنوانٌ دقيق أو CIDR (رابعة/سادسة) — لا بدل */
    protected function validIpRule(string $rule): bool
    {
        if (str_contains($rule, '/')) {
            [$net, $bits] = array_pad(explode('/', $rule, 2), 2, '');
            if ($bits === '' || ! ctype_digit($bits)) return false;
            $bin = @inet_pton($net);
            if ($bin === false) return false;

            return (int) $bits <= (strlen($bin) === 4 ? 32 : 128);
        }

        return filter_var($rule, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * **حمايةُ حبس المالك — خادميّةٌ وإلزامية** (§39): تُرفض قاعدةُ حظرٍ (إنشاءً
     * أو تمديداً) تحجب قنواتِ وصول آخرِ مالك، مهما أرسل النموذج. قنواتُ المالك:
     * عنوانُه الحاليّ (إن كان هو المنفّذ) + عناوينُه المعروفة (`user_ips` —
     * ذاكرةُ LoginSentry القائمة، لا حاسبَ ألفةٍ ثانياً). والعنوانُ المحميُّ
     * بقاعدةِ سماحٍ حيّة أو بـ`security.trusted_ips` ليس محجوباً (allow يفوز).
     *
     * مالكٌ وحيد: أيُّ قناةٍ من قنواته تُحجب ⇒ رفض. عدّةُ مالكين: يُرفض ما
     * يحبسهم جميعاً (لكلٍّ قنواتُه كلُّها محجوبة). يعيد رسالةَ الرفض أو null.
     */
    protected function blockLockoutRefusal(string $ruleIp): ?string
    {
        $owners = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNull('users.deleted_at')->where('users.status', 'نشط')->where('roles.is_owner', true)
            ->orderBy('users.created_at')->orderBy('users.id')->get(['users.id', 'users.name']);
        if ($owners->isEmpty()) return null;

        $trusted = (string) setting('security.trusted_ips', '');
        $allows = \App\Models\IpRule::query()->active()->where('mode', 'allow')->orderBy('id')->get();
        $blocked = function (string $ip) use ($ruleIp, $trusted, $allows): bool {
            if (! ip_allowed($ip, $ruleIp)) return false;
            if ($trusted !== '' && ip_allowed($ip, $trusted)) return false;

            return ! $allows->contains(fn ($a) => $a->matches($ip));
        };

        $ipsRows = Schema::hasTable('user_ips')
            ? DB::table('user_ips')->whereIn('user_id', $owners->pluck('id'))
                ->orderBy('id')->get(['user_id', 'ip'])
            : collect();
        $channels = function ($owner) use ($ipsRows): array {
            $set = $ipsRows->where('user_id', $owner->id)->pluck('ip')->all();
            if ((string) auth()->id() === (string) $owner->id) $set[] = (string) request()->ip();

            return array_values(array_unique(array_filter(array_map('trim', $set))));
        };

        if ($owners->count() === 1) {
            // آخرُ مالك: حجبُ **أيّ** قناةٍ من قنواته رفضٌ — لا هامشَ مقامرةٍ هنا
            foreach ($channels($owners->first()) as $ip) {
                if ($blocked($ip)) {
                    return "مرفوض: هذه القاعدة تحجب العنوان {$ip} وهو قناةُ وصول آخرِ مالكٍ للنظام — "
                        . 'أضف سماحاً صريحاً أو عنواناً موثوقاً (security.trusted_ips) أولاً إن كنت متأكداً';
                }
            }

            return null;
        }

        // عدّةُ مالكين: يكفي مالكٌ واحدٌ ناجٍ (له قناةٌ غيرُ محجوبة أو لا قنواتَ معلومة)
        foreach ($owners as $o) {
            $set = $channels($o);
            if ($set === [] || collect($set)->contains(fn ($ip) => ! $blocked($ip))) return null;
        }

        return 'مرفوض: هذه القاعدة تحجب كلَّ قنوات الوصول المعروفة لكل المالكين — حبسٌ تامٌّ للنظام';
    }

    /** وصفُ أجل القاعدة للتدقيق والرسائل */
    protected function ruleTermLabel(?\Illuminate\Support\Carbon $expires): string
    {
        return $expires === null ? 'دائمة' : 'حتى ' . $expires->format('Y-m-d H:i');
    }

    /**
     * إضافةُ قاعدة (حظر/سماح): مالكٌ + step-up + حمايةُ الحبس الخادمية + قيدُ
     * تدقيقٍ بدلالة SECURITY_POLICY_CHANGED. الحظرُ يُدفَع للحافّة **بأفضل جهدٍ**
     * إن كانت مُهيّأةً فعلاً — وإخفاقُها لا يُذكر نجاحاً (الحظرُ التطبيقيّ السلطة).
     */
    public function blockStore(\Illuminate\Http\Request $r)
    {
        $this->blocksGate();
        if ($resp = hub_require_stepup(route('security.blocks', absolute: false))) return $resp;

        $data = $r->validate([
            'ip'      => 'required|string|max:64',
            'mode'    => 'required|in:block,allow',
            'minutes' => 'nullable|integer|min:1|max:527040',   // حتى سنة — والدائمُ فراغُ الحقل
            'reason'  => 'nullable|string|max:400',
        ]);

        $ip = trim((string) $data['ip']);
        if (! $this->validIpRule($ip)) {
            return back()->with('err', 'صيغةُ القاعدة غير صالحة — عنوانٌ دقيق أو شبكةُ CIDR (IPv4/IPv6)')->withInput();
        }

        $expires = isset($data['minutes']) && $data['minutes'] !== null
            ? now()->addMinutes((int) $data['minutes']) : null;

        if ($data['mode'] === 'block' && ($why = $this->blockLockoutRefusal($ip)) !== null) {
            hub_audit('رفض قاعدة حظر IP — حماية حبس المالك', null, null, $ip . ' — ' . $why,
                ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);

            return back()->with('err', $why)->withInput();
        }

        $rule = \App\Models\IpRule::create([
            'ip' => $ip, 'mode' => $data['mode'], 'origin' => 'manual',
            'reason' => $data['reason'] ?? null, 'expires_at' => $expires,
            'by_id' => auth()->id(),
            'request_id' => mb_substr((string) \App\Support\Api::requestId(), 0, 64) ?: null,
        ]);

        $verb = $data['mode'] === 'block' ? 'حظر' : 'سماح';
        hub_audit("إضافة قاعدة {$verb} IP", null, (string) $rule->id,
            $ip . ' — ' . $this->ruleTermLabel($expires),
            ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);

        // الحافّةُ أفضلُ جهدٍ صادق: غيرُ المُهيّأة لا تُنادى، والإخفاقُ لا يُدّعى نجاحاً
        $edge = $data['mode'] === 'block' && \App\Support\EdgeDefense::push($rule);

        return back()->with('ok', "⛔ أُضيفت قاعدةُ {$verb} للعنوان {$ip} ("
            . $this->ruleTermLabel($expires) . ') — حظرُ التطبيق ساري المفعول فوراً'
            . ($edge ? '، ودُفعت للحافّة (أفضل جهد)' : ''));
    }

    /** تمديدُ أجل قاعدة: الحارسُ نفسُه + حمايةُ الحبس (التمديدُ «تعديلٌ» بالمواصفة) */
    public function blockExtend(\Illuminate\Http\Request $r, string $id)
    {
        $this->blocksGate();
        if ($resp = hub_require_stepup(route('security.blocks', absolute: false))) return $resp;

        $rule = \App\Models\IpRule::find($id);
        abort_unless($rule !== null, 404);
        if ($rule->revoked_at !== null) {
            return back()->with('err', 'قاعدةٌ ملغاة لا تُمدَّد — أنشئ قاعدةً جديدة إن لزم');
        }

        $data = $r->validate(['minutes' => 'required|integer|min:1|max:527040']);
        $expires = now()->addMinutes((int) $data['minutes']);

        if ($rule->mode === 'block' && ($why = $this->blockLockoutRefusal((string) $rule->ip)) !== null) {
            hub_audit('رفض تمديد قاعدة حظر IP — حماية حبس المالك', null, (string) $rule->id,
                $rule->ip . ' — ' . $why, ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);

            return back()->with('err', $why);
        }

        $rule->update(['expires_at' => $expires]);
        hub_audit('تمديد قاعدة IP', null, (string) $rule->id,
            $rule->ip . ' — ' . $this->ruleTermLabel($expires),
            ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);

        return back()->with('ok', "⏳ مُدّدت قاعدةُ {$rule->ip} " . $this->ruleTermLabel($expires));
    }

    /** إلغاءُ قاعدة: إلغاءٌ صريحٌ بأثرِه (الصفُّ يبقى تاريخاً) — يرفع الصدَّ فوراً */
    public function blockRevoke(\Illuminate\Http\Request $r, string $id)
    {
        $this->blocksGate();
        if ($resp = hub_require_stepup(route('security.blocks', absolute: false))) return $resp;

        $rule = \App\Models\IpRule::find($id);
        abort_unless($rule !== null, 404);
        if ($rule->revoked_at !== null) {
            return back()->with('warn', 'القاعدةُ ملغاةٌ أصلاً');
        }

        $rule->update(['revoked_at' => now(), 'revoked_by' => auth()->id()]);
        hub_audit('إلغاء قاعدة IP', null, (string) $rule->id,
            $rule->ip . ' — ' . ($rule->mode === 'block' ? 'حظر' : 'سماح') . ' (' . $rule->origin . ')',
            ['category' => 'SECURITY_POLICY_CHANGED', 'severity' => 'high']);

        return back()->with('ok', "♻️ أُلغيت قاعدةُ {$rule->ip} — سرى الأثرُ فوراً");
    }
}
