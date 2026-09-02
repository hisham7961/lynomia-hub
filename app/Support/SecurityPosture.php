<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * وضعية الأمان — **ما المكسور الآن ومن يصلحه**، لا كم مستخدماً وكم مقفلاً.
 *
 * كان مركز الأمان ستة أرقامٍ عارية: يُحصي المقفولين ولا يقول إن حارس SSRF
 * مُبطَل، ولا إن كلمة سرّ QuoteFlow ما زالت تلك المكتوبة في شيفرةٍ عامة، ولا
 * إن المالك نفسه بلا تحقّقٍ بخطوتين. كل فحصٍ هنا يردّ: درجته، ولماذا يهمّ،
 * وكم عنصراً يمسّ، و**كيف يُصلَح** — وأين يُصلَح بالضبط.
 *
 * القاعدة: لا فحص إلا إن كان لعدم رؤيته ثمنٌ أمنيّ حقيقي.
 */
class SecurityPosture
{
    /** ترتيب الفحوص — الأسماء وحدها كي تُنفَّذ كلٌّ منها معزولةً عن أخواتها */
    protected const ORDER = ['lockdown', 'maintenance', 'ssrf', 'quoteflowPass', 'twofaPrivileged',
                             'twofaCoverage', 'passwordPolicy', 'stalePasswords', 'idleUsers', 'lockedNow',
                             'vaultRotation', 'apiStale', 'shareOpen', 'auditChain', 'demoMode', 'ownerCount',
                             'defaultPassword', 'debugMode', 'backupFresh'];   // v2.399

    /**
     * كل فحص: key · label · tone (ok|wn|bad) · why · n · fix · url
     *
     * **كلٌّ معزول**: ستة عشر فحصاً تقرأ سبعة جداول وثمانية إعدادات، وسقوط
     * واحدٍ منها كان يُسقط اللوحة كلها — ولوحةُ أمانٍ لا تُفتح وقت العطل هي
     * أسوأ ما يمكن أن تكون. الساقط يظهر بطاقةَ تحذيرٍ تحيل إلى مركز الأخطاء،
     * والخمسة عشر الباقية تعمل.
     */
    public static function checks(): array
    {
        $out = [];
        foreach (self::ORDER as $check) {
            try {
                $row = self::$check();
                if ($row) $out[] = $row;
            } catch (\Throwable $e) {
                \App\Support\ErrorLog::capture('php',
                    "security-posture: سقط فحص {$check} — " . $e->getMessage(), $e->getFile(), $e->getLine());
                $out[] = self::row($check, 'فحصٌ تعذّر تنفيذه: ' . $check, 'wn',
                    'لم يُنفَّذ هذا الفحص على هذا التنصيب، فحالتُه مجهولة لا سليمة.', 0,
                    'التفصيل مسجَّلٌ في مركز الأخطاء باسم security-posture.', route('errors.index'));
            }
        }

        return $out;
    }

    /** ملخّصٌ للشريط العلوي: كم مكسور وكم تحذير */
    public static function summary(?array $checks = null): array
    {
        $c = collect($checks ?? self::checks());

        return [
            'bad'   => $c->where('tone', 'bad')->count(),
            'wn'    => $c->where('tone', 'wn')->count(),
            'ok'    => $c->where('tone', 'ok')->count(),
            'score' => $c->count() ? (int) round($c->where('tone', 'ok')->count() / $c->count() * 100) : 100,
        ];
    }

    // ────────────────────────── الفحوص ──────────────────────────

    protected static function lockdown(): array
    {
        $on = (bool) setting('security.lockdown', false);

        return self::row('lockdown', 'قفل الطوارئ', $on ? 'wn' : 'ok',
            'حين يكون مرفوعاً يُمنع كل من ليس مالكاً من الدخول وتُصدّ الجلسات القائمة.',
            $on ? 1 : 0,
            $on ? 'النظام مقفلٌ الآن — ارفع القفل متى انتهى السبب، فلا مؤقّت ينهيه ذاتياً.' : '',
            route('security.index'));
    }

    protected static function maintenance(): array
    {
        $on = (bool) setting('maintenance.on', false);

        return self::row('maintenance', 'وضع الصيانة', $on ? 'wn' : 'ok',
            'صفحة الصيانة تُردّ لكل من ليس مالكاً — بما فيهم عملاءُ فُتحت لهم روابط توقيعٍ أو مشاركة، ونبضةُ الصحة الخارجية.',
            $on ? 1 : 0,
            $on ? 'مفعَّلٌ الآن ولا شيء ينهيه تلقائياً — أطفئه من لوحة التشغيل حالما ينتهي العمل.' : '',
            route('ops.index'));
    }

    protected static function ssrf(): array
    {
        $on = (bool) setting('monitor.allow_private');

        return self::row('ssrf', 'حارس العناوين الداخلية (SSRF)', $on ? 'bad' : 'ok',
            'مع «السماح بمراقبة عناوين داخلية» يسقط رفضُ الأسماء المحلية والعناوين الخاصة وعنوان بيانات السحابة — ويصير النظام مِجَسّاً على شبكتك لمن يملك تعديل السيرفرات أو المواقع.',
            $on ? 1 : 0,
            $on ? 'أطفئ «السماح بمراقبة عناوين داخلية» من الإعدادات ما لم تكن على شبكةٍ معزولة ولوقتٍ محدود.' : '',
            route('settings.edit'));
    }

    /** قيمة إعدادٍ نصّاً دائماً — عمود القيم مصبوبٌ array فقد تعود مركّبة */
    protected static function str(string $key, string $def = ''): string
    {
        $v = setting($key, $def);

        return is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE);
    }

    protected static function quoteflowPass(): array
    {
        $v = self::str('quoteflow.pass');
        $weak = $v === '' || $v === '1998';

        return self::row('qf_pass', 'كلمة سرّ QuoteFlow', $weak ? 'bad' : 'ok',
            'البوابة الثانية لتطبيق العروض والفواتير والمخزون. افتراضيها مكتوبٌ صراحةً في شيفرةٍ عامة، فما لم تُبدَّل فهي بلا قيمةٍ أمنية.',
            $weak ? 1 : 0,
            $weak ? 'اضبط «كلمة سرّ QuoteFlow» في الإعدادات بقيمةٍ حقيقية — الفراغ يعيدها للافتراضي المكشوف.' : '',
            route('settings.edit'));
    }

    /** رايات الصلاحيات الخطرة: من يديرها بلا تحقّقٍ بخطوتين ثغرةٌ بحسابٍ واحد */
    protected static function twofaPrivileged(): array
    {
        $roles = DB::table('roles')->get(['id', 'is_owner', 'flags']);
        $risky = $roles->filter(function ($r) {
            if ($r->is_owner) return true;
            $f = json_decode($r->flags ?? '[]', true) ?: [];

            return (bool) array_intersect(array_keys(array_filter($f)), ['users', 'secrets', 'exp', 'audit']);
        })->pluck('id')->all();

        $n = $risky ? DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->whereIn('role_id', $risky)->where('totp_enabled', 0)->count() : 0;

        return self::row('twofa_priv', 'التحقق بخطوتين لأصحاب الصلاحيات الخطرة', $n ? 'bad' : 'ok',
            'من يملك إدارة المستخدمين أو الأسرار أو التصدير أو التدقيق — وكل مالك — يفتح النظام كله بحسابٍ واحد. كلمةُ مرورٍ وحدها لا تكفي هنا.',
            $n, $n ? "{$n} حساباً بصلاحيةٍ خطرة بلا تحقّقٍ بخطوتين — فعّله لهم من ملفاتهم قبل أي شيءٍ آخر." : '',
            route('users.index'));
    }

    protected static function twofaCoverage(): array
    {
        $all = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')->count();
        $on  = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')->where('totp_enabled', 1)->count();
        $pct = $all ? (int) round($on / $all * 100) : 100;

        return self::row('twofa_all', 'تغطية التحقق بخطوتين', $pct >= 80 ? 'ok' : ($pct >= 40 ? 'wn' : 'bad'),
            'النسبة الكلية بين الحسابات النشطة.', $all - $on,
            $pct >= 80 ? '' : "التغطية {$pct}٪ — " . ($all - $on) . ' حساباً بلا تحقّقٍ بخطوتين.',
            route('users.index'), $pct . '٪');
    }

    protected static function passwordPolicy(): array
    {
        $min = (int) self::str('auth.pw_min', '10');
        $tone = $min >= 12 ? 'ok' : ($min >= 10 ? 'wn' : 'bad');

        return self::row('pw_min', 'سياسة كلمة المرور', $tone,
            'الحدّ الأدنى للطول. لا أثر رجعي: من لم يغيّر كلمته لا تمسّه القاعدة الجديدة، ولا آلية إجبارٍ على التجديد في النظام.',
            $min, $tone === 'ok' ? '' : "الحدّ {$min} خانة — ارفعه إلى ١٢ فأكثر من الإعدادات، ثم اطلب من الفريق التجديد يدوياً.",
            route('settings.edit'), $min . ' خانة');
    }

    protected static function stalePasswords(): array
    {
        $n = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->where(fn ($w) => $w->whereNull('password_changed_at')
                ->orWhere('password_changed_at', '<', now()->subDays(365)))->count();

        return self::row('pw_stale', 'كلمات مرور لم تُجدَّد منذ سنة', $n ? ($n > 3 ? 'bad' : 'wn') : 'ok',
            'كلمةٌ عمرها سنةٌ كاملة كانت في متناول كل جهازٍ استُعملت عليه وكل تسريبٍ وقع في تلك المدة.',
            $n, $n ? "{$n} حساباً — اطلب تجديدها من ملف كل مستخدم." : '', route('users.index'));
    }

    protected static function idleUsers(): array
    {
        $n = DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->where(fn ($w) => $w->whereNull('last_login_at')
                ->orWhere('last_login_at', '<', now()->subDays(60)))->count();

        return self::row('idle', 'حسابات نشطة بلا دخولٍ ٦٠ يوماً', $n ? ($n > 2 ? 'bad' : 'wn') : 'ok',
            'حسابٌ لا يستعمله صاحبه يبقى صلاحيةً حيّةً بلا رقيب — وأول ما يُجرَّب في أي تسريبٍ لكلمات المرور.',
            $n, $n ? "{$n} حساباً — أوقفها أو احذفها، وأعِدها عند الحاجة." : '', route('users.index'));
    }

    protected static function lockedNow(): array
    {
        $n = DB::table('users')->whereNull('deleted_at')
            ->whereNotNull('locked_until')->where('locked_until', '>', now())->count();

        return self::row('locked', 'حسابات مقفولة الآن', $n ? 'wn' : 'ok',
            'القفل مربوطٌ بالبريد لا بعنوان الشبكة، فقفلٌ متكرّر قد يكون تخميناً على الحساب — أو إقفالاً متعمَّداً لصاحبه.',
            $n, $n ? 'راجع المحاولات الفاشلة أدناه: عنوانٌ واحد يطرق حساباتٍ عدة يعني هجوماً لا نسياناً.' : '',
            route('security.index'));
    }

    protected static function vaultRotation(): array
    {
        if (! Schema::hasTable('vault_secrets')) return [];
        $n = DB::table('vault_secrets')->whereNull('deleted_at')
            ->where('updated_at', '<', now()->subDays(180))->count();

        return self::row('vault_stale', 'أسرار لم تُدوَّر منذ ٦ أشهر', $n ? ($n > 5 ? 'bad' : 'wn') : 'ok',
            'كل سرٍّ يمرّ عليه ستة أشهر رآه كل من دخل الخزنة في تلك المدة، ومن غادر الفريق فيها.',
            $n, $n ? "{$n} سرّاً — دوّرها في مصدرها ثم حدّثها في الخزنة." : '', route('m.index', 'vault'));
    }

    protected static function apiStale(): array
    {
        if (! Schema::hasTable('api_tokens')) return [];
        $q = DB::table('api_tokens')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
        $n = (clone $q)->where(fn ($w) => $w->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subDays(90)))->count();
        $noExp = (clone $q)->whereNull('expires_at')->count();

        return self::row('api_stale', 'مفاتيح API خاملة أو بلا انتهاء', ($n || $noExp) ? ($n > 2 ? 'bad' : 'wn') : 'ok',
            'مفتاحٌ لم يُستعمل تسعين يوماً غالباً منسيّ في سكربتٍ أو جهازٍ قديم، ومفتاحٌ بلا انتهاء يبقى صالحاً للأبد.',
            $n + $noExp,
            ($n || $noExp) ? "{$n} خاملاً و{$noExp} بلا تاريخ انتهاء — ألغِ ما لا تعرف صاحبه." : '',
            route('profile.edit'));
    }

    protected static function shareOpen(): array
    {
        if (! Schema::hasTable('share_links')) return [];
        $n = DB::table('share_links')->where('revoked', false)->whereNull('expires_at')->count();
        $noPw = DB::table('share_links')->where('revoked', false)->whereNull('password_hash')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();

        return self::row('share_open', 'روابط مشاركة عامة بلا انتهاء', $n ? ($n > 3 ? 'bad' : 'wn') : 'ok',
            'رابط المشاركة يفتح الملف لمن يملك عنوانه بلا تسجيل دخول — فبلا تاريخ انتهاء يبقى مفتوحاً بعد انتهاء سببه بسنوات.',
            $n, $n ? "{$n} رابطاً بلا انتهاء ({$noPw} بلا كلمة مرور) — ألغِ ما انتهى غرضه من غرفة البيانات." : '',
            route('dataroom.index'));
    }

    protected static function auditChain(): array
    {
        if (! Schema::hasTable('audits')) return [];
        $chain = Audit::verifyTail();

        return self::row('audit_chain', 'سلسلة بصمات التدقيق', $chain['ok'] ? 'ok' : 'bad',
            'كل قيدٍ مختومٌ ببصمةٍ تعتمد على ما قبله، فأي تعديلٍ مباشر على القاعدة يكسر السلسلة.',
            $chain['broken'], $chain['ok'] ? '' : $chain['why'] . ' — شغّل `php artisan hub:audit-verify` لتحديد مدى الضرر.',
            route('audit.index'), $chain['label']);
    }

    protected static function demoMode(): array
    {
        $on = (bool) setting('demo.on');

        return self::row('demo', 'الوضع التجريبي', $on ? 'wn' : 'ok',
            'بياناتٌ مولَّدة تختلط بالحقيقية في كل لوحةٍ وتقرير.',
            $on ? 1 : 0, $on ? 'مفعَّل — صفّره أو أنهه من الشريط العلوي قبل الاعتماد على أي رقم.' : '',
            route('ops.index'));
    }

    protected static function ownerCount(): array
    {
        $ids = DB::table('roles')->where('is_owner', 1)->pluck('id')->all();
        $n = $ids ? DB::table('users')->whereNull('deleted_at')->where('status', '!=', 'موقوف')
            ->whereIn('role_id', $ids)->count() : 0;

        return self::row('owners', 'عدد المالكين', $n === 0 ? 'bad' : ($n > 3 ? 'wn' : 'ok'),
            'المالك يتجاوز كل مصفوفة صلاحيات ويرى كل شيء. واحدٌ فقط خطرُ فقدانِ وصول، وكثرتهم توسيعٌ لسطح الهجوم.',
            $n,
            $n === 0 ? 'لا مالك نشط — لا أحد يفتح مركز الأمان ولا الإعدادات.'
                     : ($n > 3 ? "{$n} مالكين — أنزِل من لا يحتاج الملكية إلى دورٍ برايات محدَّدة." : ''),
            route('users.index'));
    }

    /** كلمةُ المرور المنشورة في README ما زالت حيّة على حساب مالك (CFG-05) */
    protected static function defaultPassword(): array
    {
        $n = 0;
        $owners = \App\Models\User::with('role')->whereNull('deleted_at')->where('status', '!=', 'موقوف')->get()
            ->filter(fn ($u) => $u->role?->is_owner);
        foreach ($owners as $u) {
            if ($u->password && \Illuminate\Support\Facades\Hash::check('ChangeMe!2026', $u->password)) $n++;
        }

        return self::row('default_pw', 'كلمة المرور الافتراضية للمالك', $n ? 'bad' : 'ok',
            'كلمةُ المرور التي يبذرها المنصِّب منشورةٌ في التوثيق — بقاؤها حيّةً يعني أن أيَّ قارئٍ للتوثيق مالكٌ.',
            $n, $n ? "{$n} حساب مالكٍ ما زال على ChangeMe!2026 — غيّرها الآن من ملف المستخدم." : '', route('users.index'));
    }

    /** APP_DEBUG في الإنتاج يكشف المكدّس والاستعلامات، والبيئةُ غيرُ الإنتاجية تعني إعداداتٍ متساهلة (CFG-12) */
    protected static function debugMode(): array
    {
        $debug = (bool) config('app.debug');
        $env = (string) config('app.env');
        if ($debug && $env === 'production') $tone = 'bad';
        elseif ($debug || $env !== 'production') $tone = 'wn';
        else $tone = 'ok';
        $why = 'APP_DEBUG يطبع المكدّسَ والاستعلامَ وقيمَ البيئة لكل زائرٍ يقع على خطأ؛ وبيئةٌ غير production تُطفئ حرّاساً وتُبقي بياناتِ عرض.';
        $fix = $tone === 'ok' ? '' : "الحالي: APP_ENV={$env}، APP_DEBUG=" . ($debug ? 'true' : 'false') . " — اضبطهما في .env ثم optimize:clear.";

        return self::row('debug_mode', 'وضعُ التصحيح والبيئة', $tone, $why, $tone === 'ok' ? 0 : 1, $fix, route('ops.index'));
    }

    /** آخرُ نسخةٍ احتياطية ناجحة: أقدمُ من يومين = لا تعافٍ (CFG-02/12) */
    protected static function backupFresh(): array
    {
        $at = setting('heartbeat.backup');
        $age = $at ? (int) \Illuminate\Support\Carbon::parse($at)->diffInHours(now()) : null;
        $tone = $age === null ? 'wn' : ($age > 72 ? 'bad' : ($age > 30 ? 'wn' : 'ok'));
        $fix = $age === null ? 'لم تُؤخذ نسخةٌ قطّ — فعّل سطر cron أو اضغط «نسخة الآن» في مركز التشغيل.'
            : ($tone === 'ok' ? '' : "آخر نسخة منذ {$age} ساعة — راجع نبضة hub:backup في مركز التشغيل.");

        return self::row('backup_fresh', 'حداثةُ النسخة الاحتياطية', $tone,
            'نسخةٌ يوميةٌ حيّة هي كلُّ ما يفصل بين عطلٍ في القرص وفقدِ المنشأة — وتحتفظ محلياً فقط؛ انسخها خارج الخادم (كتيّب التشغيل).',
            $tone === 'ok' ? 0 : 1, $fix, route('ops.index'));
    }

    protected static function row(string $key, string $label, string $tone, string $why,
                                  int $n, string $fix, string $url, ?string $value = null): array
    {
        return compact('key', 'label', 'tone', 'why', 'n', 'fix', 'url') + ['value' => $value];
    }
}
