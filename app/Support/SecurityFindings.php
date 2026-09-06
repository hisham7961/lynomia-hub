<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **محرّكُ النتائج الأمنية** (WP-4.1 · spec §2.3/§31/§34/§42.2 · قرار ق٤).
 *
 * فحوصُ `SecurityPosture` لقطةٌ حيّة بلا ذاكرة: تقول «مكسورٌ الآن» ولا تقول
 * «منذ متى» ولا «هل أقرّ به أحد». هنا تُسوّى اللقطةُ مع جدول `security_findings`:
 * صفٌّ واحد لكل (رمز × كيان) يُفتح عند الرصد، ويتحدّث `last_seen_at` مع كل
 * تشغيل، ويُغلق تلقائياً حين يُشاهَد الشرطُ زائلاً — **بلا حذف**، فالتاريخُ
 * هو الجواب على «كم بقينا مكشوفين؟».
 *
 * **منظّمةٌ فقط في هذه الحزمة** (critic #24): الفحوصُ التسعةَ عشر بكيانِ
 * الحارستين ('org',''). نتائجُ الكيانات (رمز/سرّ/مستخدم) تكتبها ذيولُ
 * WP-4.3/4.5 حيث تُولد مُعدّاتُها — وهذا المُسوّي لا يمسّ صفوفَها أبداً.
 *
 * **الإقرارُ يعيش هنا** (ق٤): أعمدةُ acknowledged_* في الجدول نفسِه، ولا
 * تُسجَّل النتيجةُ إشارةً ثانية في `signal_states` — فلا إقرارَين لشرطٍ واحد.
 */
class SecurityFindings
{
    /** قيمتا الكيان الحارستان لنتائج المنظّمة (critic #6 — لا NULL في الفريد) */
    public const ORG_TYPE = 'org';
    public const ORG_ID = '';

    /** حالاتُ النتيجة */
    public const STATUSES = ['open' => 'مفتوحة', 'acknowledged' => 'مُقَرٌّ بها', 'resolved' => 'محلولة', 'ignored' => 'متجاهَلة'];

    /**
     * الشدّةُ **بالرمز** لا بالنبرة (critic #10): `SecurityPosture::row` لا يحمل
     * شدّةً أصلاً، والنبرةُ (ok|wn|bad) صفةُ لونٍ تتقلّب بالعدد — فالتعيينُ جدولٌ
     * صريح لرموز الفحوص التسعةَ عشر (مفاتيحُ row لا أسماءُ الدوال).
     */
    public const SEVERITY_BY_CODE = [
        'lockdown'     => 'medium',     // قفلُ طوارئ مرفوع — حالةٌ مقصودة تستحق الانتباه لا الذعر
        'maintenance'  => 'medium',     // وضعُ الصيانة معلّق بلا مؤقّت
        'ssrf'         => 'high',       // حارسُ العناوين الداخلية مُبطَل — النظامُ مِجسٌّ على الشبكة
        'qf_pass'      => 'high',       // كلمةُ سرٍّ افتراضية منشورة في شيفرةٍ عامة
        'twofa_priv'   => 'critical',   // مميّزون بلا تحقّقٍ بخطوتين — حسابٌ واحد يفتح كلَّ شيء
        'twofa_all'    => 'medium',     // تغطيةٌ عامة منخفضة
        'pw_min'       => 'medium',     // سياسةُ طولٍ دون الموصى
        'pw_stale'     => 'medium',     // كلماتٌ لم تُجدَّد منذ سنة
        'idle'         => 'medium',     // حساباتٌ خاملة حيّة
        'locked'       => 'medium',     // أقفالٌ نشطة — قد تكون تخميناً جارياً
        'vault_stale'  => 'high',       // أسرارٌ لم تُدوَّر — رآها كلُّ من مرّ
        'api_stale'    => 'high',       // مفاتيحُ API منسية أو أبدية
        'share_open'   => 'high',       // روابطُ مشاركةٍ عامة بلا انتهاء
        'audit_chain'  => 'critical',   // سلسلةُ التدقيق مكسورة — عبثٌ محتمل
        'demo'         => 'medium',     // بياناتٌ مولَّدة تختلط بالحقيقية
        'owners'       => 'high',       // لا مالكَ أو كثرةُ مالكين
        'default_pw'   => 'critical',   // كلمةُ مرور المنصِّب المنشورة ما زالت حيّة على مالك
        'debug_mode'   => 'high',       // APP_DEBUG/بيئةٌ متساهلة تكشف المكدّسَ والقيم
        'backup_fresh' => 'high',       // لا نسخةَ حديثة — لا تعافيَ من عطل قرص
    ];

    /**
     * تسويةُ نتائج **المنظّمة**: تمشي على `SecurityPosture::checks()` وحدَها.
     * upsert يحفظ `first_seen_at` ويحدّث `last_seen_at`، ويُعيد فتحَ المحلولة إن
     * عاد شرطُها، ويُغلق تلقائياً ما **شُوهد سليماً** (tone=ok) — لا ما غاب فحصُه:
     * فحصٌ سقط بعطلٍ حالتُه مجهولةٌ لا سليمة، والإغلاقُ عليها ادّعاء.
     *
     * @param array|null $checks فحوصٌ محسوبة سلفاً (اللقطةُ تحسبها مرةً وتمرّرها)
     * @return array{created:int, updated:int, reopened:int, resolved:int}
     */
    public static function reconcile(?array $checks = null): array
    {
        if (! Schema::hasTable('security_findings')) return ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];

        $checks = $checks ?? SecurityPosture::checks();
        $now = now();
        $out = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];

        $existing = DB::table('security_findings')
            ->where('entity_type', self::ORG_TYPE)->where('entity_id', self::ORG_ID)
            ->orderBy('code')->orderBy('id')
            ->get(['id', 'code', 'status'])->keyBy('code');

        $okCodes = [];
        foreach ($checks as $c) {
            $code = mb_substr((string) $c['key'], 0, 60);
            if (($c['tone'] ?? 'ok') === 'ok') {
                $okCodes[] = $code;
                continue;
            }

            // الشرطُ قائم: الشدّةُ من الخريطة الصريحة، والنصوصُ من الفحص نفسِه — لا اختراع
            $severity = self::SEVERITY_BY_CODE[$code] ?? (($c['tone'] ?? '') === 'bad' ? 'high' : 'medium');
            $fields = [
                'severity'     => $severity,
                'title'        => mb_substr((string) $c['label'], 0, 200),
                'description'  => (string) $c['why'],
                'remediation'  => (string) $c['fix'],
                // الدليلُ يمرّ بالمُطهِّر الواحد — لا سرَّ في عمود evidence أبداً
                'evidence'     => json_encode(Redactor::arr([
                    'n' => (int) ($c['n'] ?? 0), 'value' => $c['value'] ?? null,
                    'tone' => (string) ($c['tone'] ?? ''), 'url' => (string) ($c['url'] ?? ''),
                ]), JSON_UNESCAPED_UNICODE),
                'last_seen_at' => $now,
                'updated_at'   => $now,
            ];

            $row = $existing[$code] ?? null;
            if (! $row) {
                DB::table('security_findings')->insert($fields + [
                    'id' => (string) Str::uuid(), 'code' => $code,
                    'entity_type' => self::ORG_TYPE, 'entity_id' => self::ORG_ID,
                    'status' => 'open', 'first_seen_at' => $now, 'created_at' => $now,
                ]);
                $out['created']++;
            } elseif ($row->status === 'resolved') {
                // عاد الشرطُ بعد الحلّ: فتحٌ من جديد — والعمرُ (first_seen_at) محفوظ
                DB::table('security_findings')->where('id', $row->id)
                    ->update($fields + ['status' => 'open', 'resolved_at' => null]);
                $out['reopened']++;
            } else {
                // مفتوحةٌ أو مُقَرٌّ بها أو متجاهَلة: تحديثُ الرصد فقط — القرارُ البشريّ يبقى
                DB::table('security_findings')->where('id', $row->id)->update($fields);
                $out['updated']++;
            }
        }

        // الإغلاقُ التلقائي: ما شُوهد سليماً في هذا التشغيل — بلا حذف (التاريخُ يبقى)
        if ($okCodes) {
            $out['resolved'] = DB::table('security_findings')
                ->where('entity_type', self::ORG_TYPE)->where('entity_id', self::ORG_ID)
                ->whereIn('code', $okCodes)->where('status', '!=', 'resolved')
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);
        }

        // (WP-4.5 · critic #24) ذيلُ الكيانات حيث وُلدت مُعدّاتُه: رمزٌ وسرٌّ ومستخدم
        foreach (self::reconcileEntityFindings() as $k => $v) $out[$k] += $v;

        return $out;
    }

    /**
     * **نتائجُ الكيانات** (WP-4.5 · critic #24/#25): صفٌّ لكل رمزِ API خطر
     * (`api_stale` × `api_token`) ولكل سرٍّ بائت (`vault_stale` × `secret`) —
     * بمعرّفاتٍ حقيقية من المصادر الواحدة (`SecurityPosture::apiStaleIds/
     * vaultStaleIds`)، وبدلالة reconcile نفسِها: first_seen يُحفظ، والزائلُ
     * يُغلق آلياً بلا حذف. ونتائجُ المستخدمين (مميّزٌ بلا MFA) عبر سكّة
     * `IdentityRisk::reconcileUserFindings` **القائمة** — لا تنفيذَ ثانياً لها.
     *
     * لا مادةَ اعتمادٍ في الدليل أبداً: معرّفٌ واسمٌ وتصنيفٌ — لا بصمةَ ولا قيمة.
     *
     * @return array{created:int, updated:int, reopened:int, resolved:int}
     */
    public static function reconcileEntityFindings(): array
    {
        $out = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];
        if (! Schema::hasTable('security_findings')) return $out;

        // رموزُ API الخطرة — التصنيفُ الواحد يشرح «لماذا» لكل رمز
        $rows = [];
        $ids = array_map('strval', SecurityPosture::apiStaleIds());
        if ($ids) {
            $tokens = DB::table('api_tokens')
                ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
                ->whereIn('api_tokens.id', $ids)->orderBy('api_tokens.id')
                ->get(['api_tokens.id', 'api_tokens.name', 'api_tokens.expires_at',
                       'api_tokens.last_used_at', 'api_tokens.created_at', 'api_tokens.scopes',
                       'users.name as uname']);
            foreach ($tokens as $t) {
                $status = ApiTokens::classify($t);
                [$label] = ApiTokens::STATUSES[$status] ?? [$status];
                $rows[(string) $t->id] = [
                    'severity'    => self::SEVERITY_BY_CODE['api_stale'],
                    'title'       => mb_substr("مفتاح API خطر ({$label}) — {$t->name}", 0, 200),
                    'description' => "المفتاح «{$t->name}» لصاحبه «" . ($t->uname ?: 'مستخدم محذوف') . "» {$label} — "
                        . 'مفتاحٌ منسيّ أو أبديّ اعتمادٌ حيّ لا رقيبَ عليه.',
                    'remediation' => 'ألغِه أو دوّره من ملف صاحبه — أو أبطله إدارياً من مركز رموز API.',
                    'evidence'    => json_encode(Redactor::arr([
                        'token_id' => (string) $t->id, 'status' => $status,
                        'url' => route('security.tokens', absolute: false),
                    ]), JSON_UNESCAPED_UNICODE),
                    'company_id'  => null,
                ];
            }
        }
        foreach (self::syncEntitySet('api_stale', 'api_token', $rows) as $k => $v) $out[$k] += $v;

        // الأسرارُ البائتة — تنطيقُ الشركة من السرّ نفسِه فيراها مدقّقُها
        $rows = [];
        $ids = array_map('strval', SecurityPosture::vaultStaleIds());
        if ($ids) {
            $hasRot = hub_has_col('vault_secrets', 'rotated_at');
            $secrets = DB::table('vault_secrets')->whereIn('id', $ids)->orderBy('id')
                ->get(['id', 'title', 'type', 'company_id', 'created_at',
                       DB::raw($hasRot ? 'rotated_at' : 'NULL as rotated_at')]);
            foreach ($secrets as $s) {
                $last = $s->rotated_at ?: $s->created_at;
                $rows[(string) $s->id] = [
                    'severity'    => self::SEVERITY_BY_CODE['vault_stale'],
                    'title'       => mb_substr("سرٌّ لم يُدوَّر — {$s->title}", 0, 200),
                    'description' => "السرُّ «{$s->title}» (" . ($s->type ?: 'بلا نوع') . ') '
                        . ($s->rotated_at ? 'آخرُ تدويرٍ له ' : 'لم يُدوَّر منذ إنشائه ')
                        . \Illuminate\Support\Carbon::parse($last)->diffForHumans()
                        . ' — رآه كلُّ من دخل الخزنة ومن غادر الفريق في تلك المدة.',
                    'remediation' => 'دوِّره في مصدره ثم حدِّث قيمتَه في الخزنة — الختمُ يتحرّك مع تغيّر القيمة وحدَه.',
                    'evidence'    => json_encode(Redactor::arr([
                        'secret_id' => (string) $s->id, 'type' => (string) ($s->type ?? ''),
                        'url' => route('m.show', ['vault', $s->id], false),
                    ]), JSON_UNESCAPED_UNICODE),
                    'company_id'  => $s->company_id ?: null,
                ];
            }
        }
        foreach (self::syncEntitySet('vault_stale', 'secret', $rows) as $k => $v) $out[$k] += $v;

        // المميّزون بلا MFA — السكّةُ القائمة نفسُها (WP-4.3)، لا تنفيذَ ثانياً
        foreach (IdentityRisk::reconcileUserFindings() as $k => $v) $out[$k] += $v;

        return $out;
    }

    /**
     * محرّكُ مزامنة مجموعةِ كيانٍ واحدة بدلالة reconcile نفسِها: upsert يحفظ
     * `first_seen_at`، والقرارُ البشريّ (إقرار/تجاهل) يبقى، والزائلُ من
     * القائمة يُغلق آلياً — **بلا حذف**. يمسّ صفوفَ (code, type) وحدَها.
     *
     * @param array<string, array<string, mixed>> $rowsById entity_id ← حقولُ النتيجة
     * @return array{created:int, updated:int, reopened:int, resolved:int}
     */
    protected static function syncEntitySet(string $code, string $type, array $rowsById): array
    {
        $out = ['created' => 0, 'updated' => 0, 'reopened' => 0, 'resolved' => 0];
        $now = now();

        $existing = DB::table('security_findings')
            ->where('code', $code)->where('entity_type', $type)
            ->orderBy('entity_id')->orderBy('id')
            ->get(['id', 'entity_id', 'status'])->keyBy('entity_id');

        foreach ($rowsById as $eid => $fields) {
            $fields += ['last_seen_at' => $now, 'updated_at' => $now];
            $row = $existing[$eid] ?? null;
            if (! $row) {
                DB::table('security_findings')->insert($fields + [
                    'id' => (string) Str::uuid(), 'code' => $code,
                    'entity_type' => $type, 'entity_id' => $eid,
                    'status' => 'open', 'first_seen_at' => $now, 'created_at' => $now,
                ]);
                $out['created']++;
            } elseif ($row->status === 'resolved') {
                DB::table('security_findings')->where('id', $row->id)
                    ->update($fields + ['status' => 'open', 'resolved_at' => null]);
                $out['reopened']++;
            } else {
                DB::table('security_findings')->where('id', $row->id)->update($fields);
                $out['updated']++;
            }
        }

        $gone = $existing->keys()->map(fn ($k) => (string) $k)
            ->diff(array_map('strval', array_keys($rowsById)))->values()->all();
        if ($gone) {
            $out['resolved'] = DB::table('security_findings')
                ->where('code', $code)->where('entity_type', $type)
                ->whereIn('entity_id', $gone)->where('status', '!=', 'resolved')
                ->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);
        }

        return $out;
    }

    /** عددُ النتائج غير المحلولة (open/acknowledged) لكل شدّة — تقرؤه اللقطةُ اليومية */
    public static function openCountBySeverity(string $severity): int
    {
        if (! Schema::hasTable('security_findings')) return 0;

        return (int) DB::table('security_findings')->where('severity', $severity)
            ->whereIn('status', ['open', 'acknowledged'])->count();
    }

    /**
     * العدّةُ نفسُها لكل الشدّات دفعةً واحدة (بوّابة الطور ٤ — ميزانيّة): لوحةُ
     * الأمان كانت تعدّ الحرجَ والمرتفعَ باستعلامين والفرقُ تجميعةٌ واحدة.
     *
     * @return array<string, int> شدّة ← عددُ المفتوح/المُقَرّ
     */
    public static function openCounts(): array
    {
        if (! Schema::hasTable('security_findings')) return [];

        return DB::table('security_findings')->whereIn('status', ['open', 'acknowledged'])
            ->groupBy('severity')->orderBy('severity')
            ->selectRaw('severity, COUNT(*) as n')->pluck('n', 'severity')
            ->map(fn ($n) => (int) $n)->all();
    }

    /**
     * طمسُ البيانات الشخصية لقارئ المراقبة (critic #9 · §17.1): البريدُ والعنوانُ
     * الشبكيّ (IPv4/IPv6) يُستبدلان بقناعٍ صريح — قراءةُ `monitor` تُجيب «ما
     * المشكلة وكم عمرُها» لا «بريدُ مَن وعنوانُ مَن». المالكُ وحدَه يقرأ كاملاً.
     */
    public static function maskPII(?string $s): string
    {
        if ($s === null || $s === '') return '';
        $s = (string) preg_replace('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+~u', '‹بريد محجوب›', $s);
        $s = (string) preg_replace('~\b\d{1,3}(?:\.\d{1,3}){3}\b~', '‹عنوان محجوب›', $s);
        $s = (string) preg_replace('~\b(?:[0-9A-Fa-f]{1,4}:){2,}[0-9A-Fa-f:]+\b~', '‹عنوان محجوب›', $s);

        return $s;
    }
}
