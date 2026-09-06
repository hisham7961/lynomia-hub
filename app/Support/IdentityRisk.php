<?php

namespace App\Support;

use App\Http\Controllers\Web\RoleController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **محرّكُ خطر الهويّة** (WP-4.3 · spec §2.4/§2.5/§42.3–42.5) — **مفسَّرٌ لا صندوقٌ أسود**.
 *
 * `Risk::session` يجيب «ما خطرُ هذه الجلسة الآن؟»؛ وهنا السؤالُ الأوسع:
 * **«ما خطرُ هذا الحساب بوصفه هويّةً قائمة؟»** — امتيازُه وخمولُه وتاريخُ فشله
 * وأجهزتُه وعناوينُه، لكل المستخدمين دفعةً واحدة. كلُّ عاملٍ ببندٍ عربيّ ونقاطٍ
 * ظاهرة، والدرجةُ إشارةُ **مراجعةٍ** بشرية لا حكمٌ يقطع — لا امتيازَ يُسحب آلياً.
 *
 * **ميزانيةٌ ثابتة:** ٦ استعلاماتٍ تجميعية (`GROUP BY user_id`) على
 * `sessions_log` / `user_devices` / `user_ips` / `access_denials` / `audits` /
 * `webauthn_credentials` — كلُّها مفهرسةٌ على `user_id` — ثم تركيبٌ في الذاكرة.
 * لا حلقةَ استعلامٍ لكل مستخدم مهما كثروا (يحرسه IdentityRiskTest).
 *
 * **مفردةٌ واحدة لفشل الدخول:** `SecurityEvents::actions('AUTH_FAILURE'|'MFA_FAILURE')`
 * لا قوائمَ حرفيةً متباعدة — فصيغةُ QuoteFlow التاريخية تُحتسب كغيرها.
 *
 * والعواملُ تُركَّب من القائم: `Risk::privileged` و`RoleController::RISKY_FLAGS`
 * و`Risk::bands` (منخفض/متوسط/عالٍ/حرج من `risk.band_*`) وعتباتِ الخمول
 * `SecurityPosture::idleTiers()` (مفاتيحُ `security.idle_days_1/2/3`).
 */
class IdentityRisk
{
    /** بعد هذه المدة تُعدّ الجلسة منتهيةً لا حيّة (كمركز الأمان وخريطة الانكشاف) */
    public const LIVE_MIN = 30;

    /** نافذةُ قراءة الفشل والمنع بالأيام */
    public const WINDOW_DAYS = 30;

    /** فئاتُ مراجعة الامتيازات الثماني (§42.4): مفتاح ⇒ [أيقونة، تسمية] */
    public const CATEGORIES = [
        'owners'  => ['👑', 'المالكون'],
        'risky'   => ['🚩', 'أدوارٌ برايةٍ خطرة'],
        'scope'   => ['🌐', 'نطاقٌ شامل'],
        'wide'    => ['🏢', 'وصولُ شركاتٍ واسع'],
        'idle'    => ['😴', 'خاملون'],
        'no_mfa'  => ['🔓', 'مميّزون بلا تحقّقٍ بخطوتين'],
        'changed' => ['🔁', 'صلاحياتٌ تغيّرت مؤخّراً'],
        'unused'  => ['🗝️', 'امتيازاتٌ غيرُ مستعملة'],
    ];

    /**
     * خريطةُ الهويّة: صفٌّ لكل مستخدمٍ نشط بدرجته وعواملِه المفسَّرة — الأعلى أولاً.
     *
     * @param array|null $companyIds تنطيقُ القارئ (hub_company_ids): غيرُ المالك يرى
     *                               مستخدمي شركاتِه ومستخدمي المنظّمة (بلا حصر) فقط
     * @return array<int, array<string, mixed>>
     */
    public static function map(?array $companyIds = null): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('roles')) return [];

        // استعلامان: المستخدمون النشطون + أدوارُهم دفعةً واحدة (لا حلقةَ أدوار)
        $users = User::with('role')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->orderBy('name')->orderBy('id')->get();

        // العزلُ بالشركة (critic #9): مستخدمٌ بقائمةٍ لا تقاطع شركاتِ القارئ لا يظهر له.
        // من بلا قائمة (وصولٌ واسع) عضوٌ في كل شركةٍ ومنها شركةُ القارئ — فيظهر.
        if ($companyIds !== null) {
            $users = $users->filter(function ($u) use ($companyIds) {
                $cos = array_values(array_filter(array_map('strval', is_array($u->companies) ? $u->companies : [])));

                return ! $cos || array_intersect($cos, $companyIds);
            })->values();
        }

        $tiers = SecurityPosture::idleTiers();
        $agg = self::aggregates();

        $rows = [];
        foreach ($users as $u) {
            $rows[] = self::compose($u, $tiers, $agg);
        }
        usort($rows, fn ($a, $b) => [$b['score'], $a['id']] <=> [$a['score'], $b['id']]);

        return $rows;
    }

    /**
     * الاستعلاماتُ التجميعية الستة — كلُّها `GROUP BY user_id` وتُعاد خرائطَ مفتاحُها
     * المستخدم. غيابُ جدولٍ يُسقط عاملَه لا الخريطةَ كلَّها.
     *
     * @return array{live:\Illuminate\Support\Collection, dev:\Illuminate\Support\Collection,
     *               ips:\Illuminate\Support\Collection, denials:\Illuminate\Support\Collection,
     *               failed:\Illuminate\Support\Collection, keys:\Illuminate\Support\Collection}
     */
    protected static function aggregates(): array
    {
        $out = ['live' => collect(), 'dev' => collect(), 'ips' => collect(),
                'denials' => collect(), 'failed' => collect(), 'keys' => collect()];
        $since = now()->subDays(self::WINDOW_DAYS);

        // ١) الجلساتُ الحيّة الآن
        if (Schema::hasTable('sessions_log')) {
            $out['live'] = DB::table('sessions_log')->where('revoked', false)
                ->where('last_seen_at', '>=', now()->subMinutes(self::LIVE_MIN))
                ->groupBy('user_id')->select('user_id', DB::raw('COUNT(*) as c'))
                ->pluck('c', 'user_id');
        }

        // ٢) الأجهزة: الكلُّ والموثوقُ والمُبطَل بمجاميعَ شرطية (CASE يعمل على المحرّكين)
        if (Schema::hasTable('user_devices')) {
            $out['dev'] = DB::table('user_devices')->whereNull('deleted_at')
                ->groupBy('user_id')
                ->select('user_id', DB::raw('COUNT(*) as total'),
                    DB::raw("SUM(CASE WHEN trust = 'موثوق' THEN 1 ELSE 0 END) as trusted"),
                    DB::raw("SUM(CASE WHEN trust = 'مبطَل' THEN 1 ELSE 0 END) as revoked"))
                ->get()->keyBy('user_id');
        }

        // ٣) عناوينُ الشبكة المعروفة (الجدولُ فريدٌ على user_id+ip فالعدُّ عدُّ عناوين)
        if (Schema::hasTable('user_ips')) {
            $out['ips'] = DB::table('user_ips')->groupBy('user_id')
                ->select('user_id', DB::raw('COUNT(*) as c'))->pluck('c', 'user_id');
        }

        // ٤) المنعُ المسجَّل في النافذة
        if (Schema::hasTable('access_denials')) {
            $out['denials'] = DB::table('access_denials')->whereNotNull('user_id')
                ->where('created_at', '>=', $since)->groupBy('user_id')
                ->select('user_id', DB::raw('COUNT(*) as c'))->pluck('c', 'user_id');
        }

        // ٥) فشلُ الدخول والتحقق — بمفردات SecurityEvents الواحدة لا بقوائمَ حرفية
        if (Schema::hasTable('audits')) {
            $acts = array_merge(SecurityEvents::actions('AUTH_FAILURE'), SecurityEvents::actions('MFA_FAILURE'));
            $out['failed'] = DB::table('audits')->whereNotNull('user_id')
                ->whereIn('action', $acts)->where('created_at', '>=', $since)
                ->groupBy('user_id')->select('user_id', DB::raw('COUNT(*) as c'))
                ->pluck('c', 'user_id');
        }

        // ٦) مفاتيحُ المرور (WebAuthn) — عاملٌ مُخفِّف
        if (Schema::hasTable('webauthn_credentials')) {
            $out['keys'] = DB::table('webauthn_credentials')->whereNull('deleted_at')
                ->groupBy('user_id')->select('user_id', DB::raw('COUNT(*) as c'))
                ->pluck('c', 'user_id');
        }

        return $out;
    }

    /** تركيبُ صفِّ مستخدمٍ واحد من المجاميع — عواملُ مسمّاةٌ بنقاطها، لا رقمٌ أسود */
    protected static function compose(User $u, array $tiers, array $agg): array
    {
        [$t1, $t2, $t3] = $tiers;
        $factors = [];
        $add = function (string $label, int $points) use (&$factors) {
            if ($points !== 0) $factors[] = ['label' => $label, 'points' => $points];
        };

        $role = $u->role;
        $isOwner = (bool) ($role?->is_owner);
        $scope = (string) ($role->scope ?? 'proj');
        $flags = is_array($role?->flags) ? $role->flags : (json_decode((string) ($role->flags ?? '[]'), true) ?: []);
        $flagKeys = array_keys(array_filter($flags));
        // ترتيبُ الرايات بترتيب RISKY_FLAGS القانونيّ — فالتسميةُ حتميّةٌ قابلةٌ للاختبار
        $risky = array_values(array_intersect(RoleController::RISKY_FLAGS, $flagKeys));
        $privileged = Risk::privileged($u);
        $companies = array_values(array_filter(array_map('strval', is_array($u->companies) ? $u->companies : [])));

        // ── الامتياز: سطحُ الأثر لو اختُرق ──
        if ($isOwner) {
            $add('مالكُ النظام — وصولٌ كامل', 25);
        } elseif ($privileged) {
            $add('صلاحياتٌ حسّاسة: ' . implode('، ', array_map(
                fn ($f) => RoleController::FLAGS[$f] ?? $f, $risky)), 20);
        }
        if (! $isOwner && $scope === 'all') $add('نطاقٌ شامل — كل الشركات', 10);
        if (! $isOwner && ! $companies) $add('وصولٌ واسع — بلا حصرِ شركات', 8);

        // ── الحماية: MFA غائبٌ أشدُّ على المميَّز ──
        if (! $u->totp_enabled) {
            $add($privileged ? 'مميّزٌ بلا تحقّقٍ بخطوتين' : 'بلا تحقّقٍ بخطوتين', $privileged ? 30 : 15);
        }

        // ── الخمول: العتباتُ الثلاث من الإعدادات (security.idle_days_1/2/3) لا ثوابتَ ──
        $idleTier = 0;
        if (! $u->last_login_at) {
            $add('لم يدخل قط', 15);
            $idleTier = $t3;
        } else {
            foreach ([[$t3, 20], [$t2, 12], [$t1, 6]] as [$tier, $pts]) {
                if ($u->last_login_at->lt(now()->subDays($tier))) {
                    $add("خمولٌ يتجاوز {$tier} يوماً", $pts);
                    $idleTier = $tier;
                    break;
                }
            }
        }

        // ── كلمةُ المرور البائتة ──
        if (! $u->password_changed_at || $u->password_changed_at->lt(now()->subDays(365))) {
            $add('كلمةُ مرورٍ لم تُجدَّد منذ سنة', 10);
        }

        // ── السلوكُ المرصود في النافذة ──
        $failed = (int) ($agg['failed'][$u->id] ?? 0);
        if ($failed > 0) $add("محاولاتُ دخولٍ أو تحقّقٍ فاشلة (٣٠ يوماً): {$failed}", min(20, $failed * 4));

        $denials = (int) ($agg['denials'][$u->id] ?? 0);
        if ($denials > 0) $add("وصولٌ مرفوضٌ مسجَّل (٣٠ يوماً): {$denials}", min(10, $denials * 2));

        $dev = $agg['dev'][$u->id] ?? null;
        $revoked = (int) ($dev->revoked ?? 0);
        if ($revoked > 0) $add("أجهزةٌ سبق إبطالُها: {$revoked}", 8);
        if ($dev && (int) $dev->total > 0 && (int) $dev->trusted === 0) {
            $add('لا جهازَ موثَّقاً بين أجهزته', 6);
        }

        $ips = (int) ($agg['ips'][$u->id] ?? 0);
        if ($ips > 5) $add("عناوينُ شبكةٍ متعدّدة: {$ips}", min(10, $ips));

        $live = (int) ($agg['live'][$u->id] ?? 0);
        if ($live > 2) $add("جلساتٌ حيّةٌ متزامنة: {$live}", 5);

        // ── المُخفِّفات: تُعرض بنوداً سالبةً لا تُطمس — التفسيرُ في الاتجاهين ──
        $keys = (int) ($agg['keys'][$u->id] ?? 0);
        if ($u->totp_enabled) $add('مخفِّف: تحقّقٌ بخطوتين مفعَّل', -10);
        if ($keys > 0) $add('مخفِّف: مفتاحُ مرورٍ مسجَّل', -8);

        $score = max(0, min(100, array_sum(array_column($factors, 'points'))));
        usort($factors, fn ($a, $b) => $b['points'] <=> $a['points']);

        return [
            'id' => (string) $u->id,
            'name' => (string) $u->name,
            'email' => (string) $u->email,
            'role' => (string) ($role->name ?? '—'),
            'role_id' => $role?->id ? (string) $role->id : null,
            'is_owner' => $isOwner,
            'privileged' => $privileged,
            'risky_flags' => array_map(fn ($f) => RoleController::FLAGS[$f] ?? $f, $risky),
            'scope' => $scope,
            'wide' => ! $companies,
            'twofa' => (bool) $u->totp_enabled,
            'passkeys' => $keys,
            'last_login_at' => $u->last_login_at,
            'idle_tier' => $idleTier,
            'live' => $live,
            'ips' => $ips,
            'failed30' => $failed,
            'denials30' => $denials,
            'score' => $score,
            'band' => Risk::band($score),
            'tone' => Risk::tone($score),
            'factors' => $factors,
        ];
    }

    /**
     * مراجعةُ الامتيازات (§42.4): الفئاتُ الثماني من خريطةٍ واحدة + استعلامَي
     * تدقيقٍ (التغييراتُ الأخيرة، والاستعمالُ الفعليّ في ٩٠ يوماً — فهرس WP-1.4).
     *
     * @return array{rows: array, cats: array<string, array>}
     */
    public static function review(?array $companyIds = null): array
    {
        $rows = self::map($companyIds);
        [$t1] = SecurityPosture::idleTiers();

        $cats = array_fill_keys(array_keys(self::CATEGORIES), []);
        foreach ($rows as $r) {
            if ($r['is_owner']) $cats['owners'][] = $r;
            if (! $r['is_owner'] && $r['risky_flags']) $cats['risky'][] = $r;
            if (! $r['is_owner'] && $r['scope'] === 'all') $cats['scope'][] = $r;
            if (! $r['is_owner'] && $r['wide']) $cats['wide'][] = $r;
            if ($r['idle_tier'] >= $t1) $cats['idle'][] = $r;
        }

        // المميّزون بلا MFA — من القارئ القائم نفسِه (SecurityPosture)، منطَّقين كالبقية
        $noMfa = array_fill_keys(array_map('strval', SecurityPosture::privilegedNoMfaIds()), true);
        $cats['no_mfa'] = array_values(array_filter($rows, fn ($r) => isset($noMfa[$r['id']])));

        // صلاحياتٌ تغيّرت مؤخّراً: التدقيقُ على وحدتَي roles/users في ٣٠ يوماً
        if (Schema::hasTable('audits')) {
            $q = DB::table('audits')->leftJoin('users', 'users.id', '=', 'audits.user_id')
                ->whereIn('audits.module', ['roles', 'users'])
                ->where('audits.created_at', '>=', now()->subDays(30));
            if ($companyIds !== null) {
                // القارئُ المنطَّق يرى قيودَ شركاتِه **فقط** — القيدُ بلا شركةٍ (company_id
                // فارغ) قيدُ منظّمةٍ قد يحمل اسمَ مستخدمِ شركةٍ أخرى في حقل name، فحجبُه
                // عن المنطَّق نقصٌ صادق لا تسريبٌ صامت (أثبته اختبارُ التسريب)
                $q->whereIn('audits.company_id', $companyIds);
            }
            $cats['changed'] = $q->orderByDesc('audits.created_at')->orderByDesc('audits.id')->limit(50)
                ->get(['audits.action', 'audits.module', 'audits.name', 'audits.record_id',
                       'audits.created_at', 'users.name as actor'])->all();
        }

        // امتيازاتٌ غيرُ مستعملة: مصفوفةُ الدور مقابل الاستعمال الفعليّ في ٩٠ يوماً
        $used = [];
        if (Schema::hasTable('audits')) {
            $pairs = DB::table('audits')->whereNotNull('user_id')->whereNotNull('module')
                ->where('created_at', '>=', now()->subDays(90))
                ->groupBy('user_id', 'module')->get(['user_id', 'module']);
            foreach ($pairs as $p) $used[(string) $p->user_id][(string) $p->module] = true;
        }
        $mods = hub_modules();
        $matrices = [];      // مصفوفةُ كل دورٍ تُفكّ مرةً واحدة لا لكل مستخدم
        foreach ($rows as $r) {
            if (! ($r['is_owner'] || $r['risky_flags'] || $r['scope'] === 'all')) continue;
            $rid = $r['role_id'] ?? '';
            if (! array_key_exists($rid, $matrices)) {
                $m = $rid ? DB::table('roles')->where('id', $rid)->value('matrix') : null;
                $m = is_array($m) ? $m : (json_decode((string) $m, true) ?: []);
                $matrices[$rid] = array_keys(array_filter($m, fn ($ops) => array_filter((array) $ops)));
            }
            $unused = array_values(array_filter($matrices[$rid],
                fn ($mk) => isset($mods[$mk]) && ! isset($used[$r['id']][$mk])));
            if ($unused) {
                $r['unused_mods'] = array_map(fn ($mk) => $mods[$mk]['label'] ?? $mk, $unused);
                $cats['unused'][] = $r;
            }
        }

        return ['rows' => $rows, 'cats' => $cats];
    }

    /**
     * **ذيلُ WP-4.3** (critic #24): نتائجُ الكيان (مستخدم) تُكتب هنا حيث تُولد
     * مُعدّاتُها — صفُّ `twofa_priv` لكل مميّزٍ بلا MFA في `security_findings`،
     * على سكّة الإقرار الواحدة (ق٤). يُحدِّث `last_seen_at` ويحفظ `first_seen_at`
     * والقرارَ البشريّ (acknowledged/ignored)، ويُغلق تلقائياً من فعّل MFA أو فقد
     * الامتياز — **إغلاقُ رصدٍ فقط: لا دورَ يُغيَّر ولا جلسةَ تُنهى ولا حساباً يوقَف**.
     * لا يمسّ صفوفَ المنظّمة ولا كياناتِ غيرِه (token/secret) أبداً.
     *
     * @return array{created:int, updated:int, reopened:int, resolved:int}
     */
    public static function reconcileUserFindings(): array
    {
        $out = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];
        if (! Schema::hasTable('security_findings') || ! Schema::hasTable('users')) return $out;

        $ids = array_map('strval', SecurityPosture::privilegedNoMfaIds());
        $users = $ids ? User::whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'name', 'companies'])->keyBy('id') : collect();
        $now = now();

        $existing = DB::table('security_findings')
            ->where('code', 'twofa_priv')->where('entity_type', 'user')
            ->orderBy('entity_id')->orderBy('id')
            ->get(['id', 'entity_id', 'status'])->keyBy('entity_id');

        foreach ($ids as $uid) {
            $u = $users[$uid] ?? null;
            if (! $u) continue;
            $companies = array_values(array_filter(array_map('strval', is_array($u->companies) ? $u->companies : [])));
            $fields = [
                'severity'     => SecurityFindings::SEVERITY_BY_CODE['twofa_priv'],
                'title'        => mb_substr('حسابٌ مميّز بلا تحقّقٍ بخطوتين — ' . $u->name, 0, 200),
                'description'  => "الحسابُ «{$u->name}» يحمل صلاحياتٍ حسّاسة ولا يحمي دخولَه إلا كلمةُ مرور.",
                'remediation'  => 'فعِّل التحقّق بخطوتين من ملف المستخدم — أو أوقف الحساب من هناك حتى يُفعَّل.',
                'evidence'     => json_encode(Redactor::arr([
                    'user_id' => (string) $u->id, 'url' => route('users.edit', $u->id, false),
                ]), JSON_UNESCAPED_UNICODE),
                // تنطيقُ الشركة: مستخدمُ شركةٍ واحدة تُنسب نتيجتُه إليها فيراها مدقّقُها
                'company_id'   => count($companies) === 1 ? $companies[0] : null,
                'last_seen_at' => $now,
                'updated_at'   => $now,
            ];

            $row = $existing[$uid] ?? null;
            if (! $row) {
                DB::table('security_findings')->insert($fields + [
                    'id' => (string) Str::uuid(), 'code' => 'twofa_priv',
                    'entity_type' => 'user', 'entity_id' => $uid,
                    'status' => 'open', 'first_seen_at' => $now, 'created_at' => $now,
                ]);
                $out['created']++;
            } elseif ($row->status === 'resolved') {
                DB::table('security_findings')->where('id', $row->id)
                    ->update($fields + ['status' => 'open', 'resolved_at' => null]);
                $out['reopened']++;
            } else {
                // مفتوحةٌ أو مُقَرٌّ بها أو متجاهَلة: تحديثُ الرصد — القرارُ البشريّ يبقى
                DB::table('security_findings')->where('id', $row->id)->update($fields);
                $out['updated']++;
            }
        }

        // الإغلاقُ التلقائي لمن زال شرطُه — بلا حذفٍ وبلا أيّ مساسٍ بامتيازٍ أو جلسة
        $gone = $existing->keys()->diff($ids)->values()->all();
        if ($gone) {
            $out['resolved'] = DB::table('security_findings')
                ->where('code', 'twofa_priv')->where('entity_type', 'user')
                ->whereIn('entity_id', $gone)->where('status', '!=', 'resolved')
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);
        }

        return $out;
    }
}
