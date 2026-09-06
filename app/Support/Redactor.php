<?php

namespace App\Support;

/**
 * المُطهِّرُ الواحد — كلُّ نصٍّ أو مصفوفةٍ في طريقها إلى تخزينٍ تشخيصيّ
 * (أخطاء، صحّة، تكاملات، صادر/وارد webhooks، رادار الأمن) تمرّ من هنا.
 *
 * كان الطمسُ مبعثراً في سبعة مواضع بقواعدَ متفاوتة: `ErrorLog::redact` يطمس رموزَ
 * المسارات العامة، و`Health::safe` كلمةَ مرور DSN، و`Integrations::pulse` قاعدةً
 * ثالثة، و`HubOutbox` رمزَ البوت وحدَه — وما لم يعرفه موضعٌ تسرّب منه. هذا الصنف
 * يجمع القواعدَ كلَّها، والسبعةُ **تفوَّض إليه ولا تُستبدل** واجهاتُها.
 *
 * الطمسُ **ثابت** (idempotent): تشغيلُه على ناتجه يعيد الناتجَ نفسَه حرفياً —
 * فالتفويضُ المتسلسل (redact ثم safeMessage ثم capture) لا يشوّه شيئاً.
 * ولا علمَ `u` في الأنماط عمداً: الأصنافُ ASCII تعمل بالبايت بأمان على UTF-8،
 * و`u` يُعيد null على حمولةٍ واردةٍ فاسدة الترميز فيمسح النصَّ كلَّه.
 */
class Redactor
{
    /** قائمةُ spec: مفاتيحُ المصفوفات التي تُطمَس قيمُها مهما كان العمق */
    public const KEYS = ['password', 'passwd', 'pass', 'secret', 'token', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'session', 'api_key', 'apikey',
        'client_secret', 'private_key', 'smtp_password', 'database_password'];

    /** بديلُ القيمة المطموسة — ثابتٌ فالطمسُ الثاني لا يغيّر شيئاً */
    public const MASK = '***';

    /**
     * أنماطُ النصّ بترتيب تطبيقها. PEM أولاً (متعدّد الأسطر قبل أن تمضغه القواعد
     * الأدقّ)، ثم Bearer (يلتهم JWT الملحقَ به)، ثم JWT الحرّ، ثم رمزُ بوت تلجرام
     * (قاعدةُ HubOutbox بحرفها)، ثم `lyn_` (رموزُ API — بذيلٍ طويلٍ كي لا تُمَسّ
     * `lyn_did`/`lyn_recent`)، ثم `مفتاح=قيمة` في سلاسل الاستعلام وDSN (يضمّ قواعدَ
     * Health وIntegrations القائمتين)، ثم رموزُ المسارات العامة (قاعدةُ ErrorLog بحرفها).
     */
    protected const PATTERNS = [
        '~-----BEGIN [A-Z0-9 ]{1,48}-----[A-Za-z0-9+/=\r\n\s]{8,}(?:-----END [A-Z0-9 ]{1,48}-----)?~' => '{مفتاح مطموس}',
        '~(Bearer\s+)[A-Za-z0-9._\-+/=]{8,}~i' => '$1' . self::MASK,
        '~eyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{4,}\.[A-Za-z0-9_\-]*~' => '{jwt}',
        '~/bot[0-9]+:[A-Za-z0-9_\-]+~' => '/bot' . self::MASK,
        '~lyn_[A-Za-z0-9]{16,}~' => 'lyn_' . self::MASK,
        '~(password|passwd|pwd|pass|access_token|refresh_token|client_secret|api_key|apikey|secret|token|key|signature|authorization)=([^&;\s"\']+)~i' => '$1=' . self::MASK,
        '~/(hook|sign|verify|s|w)/[^/?\s&]{8,}~i' => '/$1/{رمز}',
    ];

    /** المفاتيحُ السرّية مجموعةً (spec + Audit::MASKED + AUDIT_SECRET من النماذج) — تُبنى مرة */
    protected static ?array $keys = null;

    /** طمسُ الأنماط في نصٍّ حرّ — نصٌّ نظيف يعود كما دخل حرفاً بحرف */
    public static function text(?string $s): string
    {
        if ($s === null || $s === '') return '';
        foreach (self::PATTERNS as $re => $to) $s = (string) preg_replace($re, $to, $s);

        return $s;
    }

    /**
     * طمسُ مصفوفةٍ بالمفتاح حتى عمق `$depth`: قيمةُ مفتاحٍ سرّي تُستبدل بالقناع
     * كاملةً (ولو كانت شجرة)، والقيمُ النصّية البريئة تمرّ بـ`text` (رمزٌ داخل
     * قيمةِ `url` مثلاً)، وما جاوز العمقَ يُطمَس كلُّه — ثقبٌ في العمق السابع
     * ليس «خارج النطاق» بل تسريب.
     */
    public static function arr(array $a, int $depth = 6): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_string($k) && in_array(strtolower(str_replace('-', '_', $k)), self::keys(), true)) {
                $out[$k] = ($v === null || $v === '') ? $v : self::MASK;
            } elseif (is_array($v)) {
                $out[$k] = $depth > 1 ? self::arr($v, $depth - 1) : self::MASK;
            } else {
                $out[$k] = is_string($v) ? self::text($v) : $v;
            }
        }

        return $out;
    }

    /** بصمةٌ لا تُعكس — صيغةُ `Auditable::auditRedact` نفسُها فلا صيغةَ ثانية */
    public static function fingerprint(string $v): string
    {
        return 'sha256:' . substr(hash('sha256', $v), 0, 16);
    }

    /**
     * قاعدةُ `ErrorLog::safeMessage` بحرفها — لرسائل `QueryException` وحدها:
     * حذفُ مقطع SQL بقيمه المربوطة، وطمسُ القيم المقتبسة (Duplicate entry '…').
     * لا تُطبَّق على النصّ الحرّ: الاقتباسُ في رسالةٍ عاديةٍ ليس قيمةَ SQL.
     */
    public static function sql(string $msg): string
    {
        $msg = preg_replace('/\s*\(Connection:.*$/s', '', $msg);          // احذف SQL والقيمَ المربوطة
        $msg = preg_replace("/'(?:[^'\\\\]|\\\\.){0,300}'/", "'…'", (string) $msg);  // اطمس القيمَ المقتبسة

        return (string) $msg;
    }

    /**
     * طمسُ حمولةٍ خام (الويبهوك الوارد): JSON صالحٌ يُفكّ فيُطمَس بالمفتاح
     * والعمق ثم يُعاد ترميزُه كما يُخزَّن (بلا هروب العربية)، وما ليس JSON
     * يمرّ نصّاً بأنماط `text` وحدها.
     */
    public static function json(string $raw): string
    {
        $d = json_decode($raw, true);
        if (is_array($d)) {
            $enc = json_encode(self::arr($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($enc !== false) return $enc;
        }

        return self::text($raw);
    }

    /** بناءُ مجموعة المفاتيح مرةً واحدة — بانعكاسٍ على `Audit::MASKED` (محميّة) كي لا تفترق القائمتان */
    protected static function keys(): array
    {
        if (self::$keys !== null) return self::$keys;
        $k = self::KEYS;
        try { $k = array_merge($k, (array) (new \ReflectionClassConstant(Audit::class, 'MASKED'))->getValue()); }
        catch (\Throwable $e) { /* غيابُ الثابت لا يُعطّل الطمسَ الأساسي */ }
        foreach ([\App\Models\User::class, \App\Models\VaultSecret::class] as $model) {
            if (defined($model . '::AUDIT_SECRET')) $k = array_merge($k, (array) constant($model . '::AUDIT_SECRET'));
        }

        return self::$keys = array_values(array_unique(array_map(fn ($s) => strtolower((string) $s), $k)));
    }
}
