<?php

namespace App\Support\Ai\Gateway;

/**
 * **بوّابةُ النماذج — طبقةُ الاتّصالِ الواحدة** (المرحلة ١ · الأساس).
 *
 * LiteLLM تعمل على **خادمِ Hub نفسِه** مربوطةً بـ`127.0.0.1` ولا تُكشَف
 * للإنترنت. وهذا الصنفُ هو **الموضعُ الوحيد** الذي يقرأ إعدادَها ويبني
 * عنوانَها وترويستَها — فلا يتفرّق الإعدادُ على الشاشات.
 *
 * **وثلاثةُ ثوابتَ يحرسها هذا الصنف:**
 *
 *  ١) **لا مفتاحَ يبلغ المتصفّح.** `key()` تُقرأ على الخادمِ وحدَه، و`mask()`
 *     هي **وحدَها** ما يصلح للعرض: آخرُ أربعِ خاناتٍ لا غير. ومن أراد العرضَ
 *     فليس له إلّاها.
 *
 *  ٢) **لا اتّصالَ قبل حارسِ الصادر.** كلُّ هدفٍ يمرّ بـ`hub_outbound_ok()`
 *     ثمّ يُثبَّت عنوانُه بـ`hub_resolve_pin()` بلا اتّباعِ تحويل — فعنوانُ
 *     بوّابةٍ يُضبَط خطأً (أو عمداً) لا يصير مِجَسّاً على الشبكة الداخليّة.
 *
 *  ٣) **«غيرُ مهيّأة» ليست «معطّلة» وليست «فاشلة».** ثلاثُ حالاتٍ مفترقةٌ
 *     صراحةً، لأنّ خلطَها يجعل الشاشةَ تكذب: بوّابةٌ لم تُضبَط بعدُ تُقرأ
 *     عطلاً، فيُطارَد عطلٌ لا وجودَ له.
 *
 * **ولماذا مفتاحٌ مع الربطِ الداخليّ؟** لأنّ `127.0.0.1` يمنع الإنترنت ولا
 * يمنع جيرانَ الخادم: على خادمٍ متعدّدِ الحسابات يستطيع أيُّ سكربتٍ على
 * المضيفِ طلبَ المنفذ. فالحاجزان معاً — الربطُ والمفتاح — لا أحدُهما.
 */
class AiGateway
{
    /**
     * **SSRF-1: منافذُ البنيةِ الحسّاسة على loopback لا تُقبَل بوّابةً.** استثناءُ
     * `127.0.0.1` ضروريٌّ لبوّابةِ LiteLLM (المنفذُ ~4000)، لكنّه كان يشمل أيَّ منفذٍ —
     * فيُوجَّه إلى قاعدةِ بياناتٍ أو مخزنٍ داخليٍّ ويصير «افحص الاتصال» عرّافَ خدمات.
     * قواعدُ البيانات والمخازنُ والوكلاءُ الإداريّون مرفوضون؛ منافذُ التطبيقِ/الوكيلِ تُقبَل.
     */
    public const SENSITIVE_LOOPBACK_PORTS = [
        22, 23, 25, 111, 135, 139, 445,          // إدارة/نقلُ ملفّات
        1433, 1521, 3306, 5432, 27017, 6379,     // قواعدُ بيانات + Redis
        9200, 9300, 11211, 5672, 15672, 2379,    // بحثٌ · تخزينٌ مؤقّت · طوابير · etcd
        2375, 2376, 8500, 8200,                  // Docker · Consul · Vault
    ];

    /** عنوانُ البوّابة كما ضُبط — بلا شرطةٍ أخيرة، أو نصٌّ فارغٌ إن لم يُضبط */
    public static function baseUrl(): string
    {
        return rtrim(trim((string) setting('ai.gateway_url', '')), '/');
    }

    /** مفتاحُ الإدارة — **للخادمِ وحدَه**؛ لا يُمرَّر إلى قالبٍ ولا إلى JSON */
    public static function key(): string
    {
        return trim((string) setting('ai.gateway_key', ''));
    }

    /**
     * القناعُ المعروض — **هذا وحدَه ما يصلح للشاشة**.
     *
     * لا يكشف القناعُ طولَ المفتاحِ الحقيقيّ: النقاطُ ثابتةُ العددِ مهما طال،
     * فطولُ السرِّ نفسُه ليس خبراً يُهدى. والقصيرُ جدّاً (≤ ٤) يُقنَّع كلُّه.
     */
    public static function mask(): string
    {
        $k = self::key();
        if ($k === '') return '';

        return mb_strlen($k) <= 4 ? '••••' : '••••••••' . mb_substr($k, -4);
    }

    /** أُعلنت البوّابةُ مهيّأةً؟ — عنوانٌ **ومفتاحٌ** معاً، لا أحدُهما */
    public static function configured(): bool
    {
        return self::baseUrl() !== '' && self::key() !== '';
    }

    /** والتكاملُ مُشغَّل؟ — مهيّأٌ **و**مفتاحُ التشغيلِ العامُّ مرفوع */
    public static function enabled(): bool
    {
        return self::configured() && (bool) setting('ai.enabled', false);
    }

    /** المهلتان — من الإعدادات بحدودٍ عاقلةٍ لا تُترك لخطأ إدخال */
    public static function timeouts(): array
    {
        return [
            'connect' => max(1, min(30, (int) setting('ai.timeout_connect', 3))),
            'read'    => max(1, min(300, (int) setting('ai.timeout_read', 30))),
        ];
    }

    /**
     * سببُ عدمِ الجاهزيّة بالعربيّة — أو `null` إن كانت جاهزة.
     *
     * رسالةٌ واحدةٌ تقرؤها كلُّ شاشة، فلا تقول شاشةٌ «غيرُ مهيّأة» وأخرى
     * «معطّلة» عن الحالةِ نفسِها.
     */
    public static function whyNotReady(): ?string
    {
        if (self::baseUrl() === '') return 'عنوانُ البوّابة غيرُ مضبوط';
        if (self::key() === '')     return 'مفتاحُ إدارةِ البوّابة غيرُ محفوظ';
        if (! (bool) setting('ai.enabled', false)) return 'التكاملُ مطفأٌ من الإعدادات';

        return null;
    }

    /**
     * عنوانٌ مطلقٌ داخلَ البوّابة لمسارٍ نسبيّ.
     *
     * المسارُ يُنظَّف من الشرطاتِ البادئة فلا يبني `//` ولا يقفز إلى جذرٍ آخر.
     */
    public static function url(string $path): string
    {
        return self::baseUrl() . '/' . ltrim($path, '/');
    }

    /**
     * **بصمةُ الإعدادِ الحاليّ** — بها يُبطَل الفحصُ السابقُ عند أيِّ تغيير.
     *
     * تُحسَب من العنوانِ والمفتاحِ معاً. فنتيجةُ فحصٍ نجح على عنوانٍ ثمّ غُيّر
     * العنوانُ **ليست دليلاً على شيء** — وعرضُها «ناجح» كذبٌ صريح. والبصمةُ
     * مُلخَّصٌ لا سرّ: `hash_hmac` بمفتاحِ التطبيق فلا تُعكَس إلى المفتاح.
     */
    public static function fingerprint(): string
    {
        return substr(hash_hmac('sha256', self::baseUrl() . '|' . self::key(),
            (string) config('app.key')), 0, 32);
    }

    /**
     * **هل نجح فحصُ الاتصالِ على الإعدادِ الحاليِّ بعينِه؟**
     *
     * ثلاثُ حقائقَ مفترقةٌ لا تُخلَط (تصحيحُ المالك · ١):
     *   · `configured()` — العنوانُ والمفتاحُ محفوظان. **لا يعني أنّها تعمل.**
     *   · `probePassed()` — فُحص الاتصالُ **على هذه البصمةِ** ونجح.
     *   · `generationVerified()` — وُلِّدت إجابةٌ فعليّةٌ من نموذج (المرحلة ٢).
     *
     * وخلطُ الأولى بالثانية هو بعينِه ما يجعل الشاشةَ تقول «يعمل» لبوّابةٍ لم
     * تُجرَّب قطّ.
     */
    public static function probePassed(): bool
    {
        return self::configured()
            && (bool) setting('ai.probe_ok', false)
            && (string) setting('ai.probe_fp', '') === self::fingerprint();
    }

    /** متى نجح آخرُ فحصٍ **على هذه البصمة** — أو `null` إن لا فحصَ ساري */
    public static function probedAt(): ?string
    {
        $at = (string) setting('ai.probe_at', '');

        return self::probePassed() && $at !== '' ? $at : null;
    }

    /**
     * **وُلِّدت إجابةٌ فعليّةٌ من نموذج؟** — المرحلةُ الثانية.
     *
     * تُقرأ من الإعدادِ لا تُفترَض، وتبقى `false` حتّى تُبنى المرحلةُ الثانية —
     * فلا تُعلَن قدرةٌ «مُختبَرة» ولم يُولَّد بها حرفٌ واحد.
     */
    public static function generationVerified(): bool
    {
        return self::probePassed()
            && (bool) setting('ai.generation_ok', false)
            && (string) setting('ai.generation_fp', '') === self::fingerprint();
    }

    /**
     * **المنفذُ المعتمَدُ للبوّابة** — يُشتقّ من العنوانِ المحفوظ لا يُخمَّن.
     * `null` إن لم يُضبَط عنوانٌ بعد.
     */
    public static function port(): ?int
    {
        $u = self::baseUrl();
        if ($u === '') return null;
        $p = parse_url($u, PHP_URL_PORT);
        if (is_int($p)) return $p;

        return mb_strtolower((string) parse_url($u, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
    }

    /**
     * **بوّابةُ الخروجِ لهذا الهدف** — بشكلِ `hub_outbound_ok` نفسِه.
     *
     * وهنا تعارضٌ حقيقيٌّ يُحسَم صراحةً لا يُلتَفّ عليه: `hub_outbound_ok`
     * **يرفض `127.0.0.1`** («عنوان داخلي أو محجوز») وهو محقٌّ — الحارسُ بُني
     * ليمنع طلباً يُوجَّه إلى داخلِ الشبكة. لكنّ بوّابةَ النماذج **محلّيّةٌ
     * بالتصميم**.
     *
     * **والحلُّ ليس رفعَ `monitor.allow_private`** — ذلك يفتح **كلَّ** وجهةٍ
     * صادرةٍ في النظام على الشبكةِ الداخليّة لأجلِ هدفٍ واحد. فالاستثناءُ
     * **محصورٌ بخمسةِ شروطٍ مجتمعة** (تصحيحُ المالك · ٢):
     *
     *  ١) **وجهةٌ معتمَدة**: المضيفُ والمنفذُ والمخطَّطُ تطابق **العنوانَ
     *     المحفوظَ بعينِه** — مطابقةَ مكوّناتٍ لا بادئةَ نصّ. فمنفذٌ آخرُ على
     *     المضيفِ نفسِه (`127.0.0.1:9999`) **يُرَدّ**.
     *  ٢) **loopback حرفيٌّ** (`127.0.0.1` أو `::1`) — لا `10.x` ولا
     *     `192.168.x` ولا `169.254.x`.
     *  ٣) **ولا اسمَ مضيفٍ البتّة** — حتّى `localhost` يُرفَض: الاسمُ يُحَلّ،
     *     وما يُحَلُّ يُعاد تحليلُه وقتَ الاتصال، فينفتح بابُ **إعادةِ ربطِ
     *     DNS**. والعنوانُ الحرفيُّ لا يُحَلُّ أصلاً فلا نافذةَ بين الفحصِ
     *     والاتصال.
     *  ٤) `http`/`https` وحدَهما.
     *  ٥) **ولا اتّباعَ لأيِّ تحويلِ HTTP** — يُفرَض عند كلِّ نداءٍ عبر
     *     `requestOptions()`، فردُّ `302` من البوّابةِ لا يقود الطلبَ إلى هدفٍ
     *     لم يمرّ بهذا الحارس.
     *
     * وما عدا ذلك **يعود إلى الحارسِ العامِّ كاملاً**.
     */
    public static function outboundGate(string $url): array
    {
        $base = self::baseUrl();
        if ($base === '') return hub_outbound_ok($url);

        $t = @parse_url($url);
        $b = @parse_url($base);
        if (! is_array($t) || ! is_array($b)) return hub_outbound_ok($url);

        $scheme = mb_strtolower((string) ($t['scheme'] ?? ''));
        $host   = (string) ($t['host'] ?? '');
        $port   = isset($t['port']) ? (int) $t['port'] : ($scheme === 'https' ? 443 : 80);

        $sameDestination = $scheme === mb_strtolower((string) ($b['scheme'] ?? ''))
            && $host === (string) ($b['host'] ?? '')
            && $port === self::port();

        $literalLoopback = in_array($host, ['127.0.0.1', '::1', '[::1]'], true);

        // **SSRF-1: استثناءُ الـloopback لا يشمل منافذَ البنيةِ الحسّاسة.** توجيهُ البوّابةِ
        // إلى 127.0.0.1:6379 (Redis) أو :5432 (Postgres) وأمثالِها كان يجعل «افحص الاتصال»
        // عرّافَ خدماتٍ داخليّة. فحتّى مع التطابقِ (sameDestination) يُرفَض المنفذُ الحسّاس.
        if ($sameDestination && $literalLoopback && in_array($scheme, ['http', 'https'], true)
            && ! in_array($port, self::SENSITIVE_LOOPBACK_PORTS, true)) {
            // `ip = null` عمداً: لا تثبيتَ عنوانٍ حيث لا تحليلَ اسمٍ أصلاً
            return ['ok' => true, 'why' => '', 'ip' => null];
        }

        return hub_outbound_ok($url);
    }

    /**
     * خياراتُ كلِّ نداءٍ صادرٍ إلى البوّابة — **لا اتّباعَ تحويلٍ** (الشرطُ ٥).
     */
    public static function requestOptions(?string $pinIp = null, string $url = ''): array
    {
        return [
            'allow_redirects' => false,
            'curl'            => $pinIp ? hub_resolve_pin($url, $pinIp) : [],
        ];
    }

    /**
     * **هل العنوانُ محلّيٌّ كما يجب؟** — تنبيهٌ لا منع.
     *
     * البوّابةُ صُمّمت للربطِ الداخليّ، وعنوانٌ عامٌّ يعني أنّ حاملةَ مفاتيحِ
     * المزوّدين صارت على الإنترنت. ولا يُمنَع المالكُ من ضبطِ ما يريد — لكنّ
     * الشاشةَ تقولها صراحةً بدل أن تسكت.
     */
    public static function isLoopback(): bool
    {
        $host = parse_url(self::baseUrl(), PHP_URL_HOST);
        if (! is_string($host) || $host === '') return false;

        return in_array(mb_strtolower($host), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
    }
}
