<?php

namespace App\Support\Ops;

use App\Models\OdooConnection;
use Illuminate\Support\Facades\Http;
use App\Support\Platform\Redactor;

/**
 * **فاحصُ الاتصال الواحد** (WP-9.4 · spec §7.10) — «هل التكاملُ يعمل الآن؟».
 *
 * كان لأودو فاحصان بشيفرتين لاتصالٍ واحد: `SettingController::odooTest` للاتصال
 * الافتراضي و`OdooConnectionController::test` لصفوف الخوادم — كلٌّ برسالته
 * وحرّاسه، وكلاهما يعيد **`$e->getMessage()` خاماً**. ورسالةُ خادمٍ خارجيّ ليست
 * نصّاً بريئاً: ردُّ فشلِ مصادقةٍ قد يحمل سلسلةَ اتصالٍ فيها `password=…`، فتُطبع
 * في الشاشة وتُخزَّن في الجلسة وتصل سجلَّ الأخطاء. ولا فاحصَ لـn8n أصلاً رغم أنّ
 * رابطَه يُحفظ منذ إصدارات.
 *
 * فهنا واحدٌ لهم جميعاً:
 *   · **شكلٌ واحد** — شكلُ `Uptime::check` نفسُه (`up · code · ms · error`) مضافاً
 *     إليه `info` لسطر النجاح، فلا شكلَ لكل شاشة.
 *   · **زمنُ استجابةٍ مقيس** لا انطباع — الفحصُ الذي لا يقول «كم استغرق» لا يكشف
 *     خادماً يجيب في تسع ثوانٍ حتى يسقط بمهلةٍ في الإنتاج.
 *   · **الرسالةُ تمرّ بالمُطهِّر الواحد** (`Redactor::text`) ثم تُقصّ — لا سرَّ يخرج.
 *   · **حارسُ الطلبات الصادرة** على كل هدف (`hub_outbound_ok` + تثبيتُ العنوان
 *     `hub_resolve_pin` + لا اتّباعَ لإعادة التوجيه) — زرُّ اختبارٍ لا يصير
 *     مِجَسّاً على الشبكة الداخلية.
 *
 * و`up` ثلاثيّةٌ لا ثنائية: `true` نجح، `false` جُرّب وفشل، و**`null` لم يُجرَّب**
 * (لا رابطَ مضبوط، أو ردّه الحارسُ قبل الشبكة) — و«لم يُجرَّب» ليس «فشل».
 */
class ConnectionProbe
{
    /** مفاتيحُ النتيجة بترتيبها — يثبّتها الاختبار كي لا تفترق الشاشات */
    public const SHAPE = ['up', 'code', 'ms', 'error', 'info', 'detail'];

    /** سقفُ رسالة الفشل المعروضة — بالحروف لا بالبايتات (العربيةُ تُقطع نصفين) */
    public const ERROR_MAX = 180;

    /** مهلتا الفحص: اتصالٌ قصير (جدارٌ ناريّ يُسقط الحزم) ثم قراءةٌ محدودة */
    public const CONNECT_TIMEOUT = 3;
    public const READ_TIMEOUT = 8;

    /**
     * فاحصُ أودو — للاتصال الافتراضي (`null`) أو لصفِّ خادمٍ بعينه.
     *
     * النداءُ حقيقيّ ومزدوج: `version` يقول إن الخادم يجيب، و`authenticate`
     * يقول إن الاعتماد يعمل. الأولُ وحدَه كان يقول «سليم» لخادمٍ يرفض مفتاحَنا.
     */
    public static function odoo(OdooConnection|string|null $conn = null): array
    {
        $cli = Odoo::for($conn);
        if (! $cli->ready()) {
            return self::row(null, null, null,
                $cli->error() ?? 'بيانات الاتصال بأودو ناقصة — أكمل الحقول الأربعة (الرابط والقاعدة والمستخدم والمفتاح) ثم احفظ');
        }

        $t0 = microtime(true);
        try {
            $ver = $cli->serverVersion();
            $uid = $cli->login();
        } catch (\Throwable $e) {
            return self::row(false, self::statusIn($e->getMessage()), self::since($t0), $e->getMessage());
        }

        return self::row(true, 200, self::since($t0), null,
            'أودو ' . $ver . ' · معرف المستخدم ' . $uid,
            ['version' => $ver, 'uid' => $uid]);
    }

    /**
     * فاحصُ n8n — لم يكن له فاحصٌ قطّ: يُحفظ الرابطُ ثم يُفتح في لسانٍ جديد
     * ليُعرف إن كان حيّاً.
     *
     * حين يكون المفتاحُ مضبوطاً يُفحَص **مسارُ الواجهة البرمجية** لا الجذر — فردُّ
     * ٤٠١ يقول «المثيلُ حيّ ومفتاحُك مرفوض»، وهو جوابٌ لا يعطيه فحصُ الجذر. ولا
     * يخرج المفتاحُ من هنا إلى أيّ نصّ: ترويسةٌ فقط.
     */
    public static function n8n(): array
    {
        $url = trim((string) setting('n8n.url', ''));
        if ($url === '') {
            return self::row(null, null, null, 'لا رابطَ مضبوطاً لمثيل n8n — احفظ رابطَه أولاً');
        }

        $key = (string) setting('n8n.key', '');
        $target = $key !== '' ? rtrim($url, '/') . '/api/v1/workflows?limit=1' : $url;

        $gate = hub_outbound_ok($target);
        if (! $gate['ok']) return self::row(null, null, null, $gate['why']);

        $t0 = microtime(true);
        try {
            $res = Http::withOptions([
                'allow_redirects' => false,
                'curl'            => hub_resolve_pin($target, $gate['ip']),
            ])
                ->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::READ_TIMEOUT)
                ->withHeaders(['User-Agent' => 'LynomiaHub-Probe/1.0']
                    + ($key !== '' ? ['X-N8N-API-KEY' => $key] : []))
                ->get($target);
        } catch (\Throwable $e) {
            return self::row(false, null, self::since($t0), $e->getMessage());
        }

        $code = $res->status();
        $up = $code >= 200 && $code < 400;
        $why = $up ? null : ('ردَّ المثيلُ HTTP ' . $code
            . ($code === 401 || $code === 403 ? ' — المثيلُ حيّ ومفتاحُ n8n مرفوض' : ''));

        return self::row($up, $code, self::since($t0), $why,
            $up ? ('المثيلُ يجيب' . ($key !== '' ? ' ومفتاحُ الواجهة البرمجية مقبول' : '')) : null,
            ['checked' => $key !== '' ? 'api' : 'root']);
    }

    /**
     * **فاحصُ بوّابةِ LiteLLM** (المرحلة ١) — على خادمِ Hub نفسِه، لا تُكشَف.
     *
     * والنداءُ على `/v1/models` عمداً لا على `/health/liveliness`: الأوّلُ
     * **مزدوجُ الإفادة** كفاحصِ أودو حرفاً بحرف — يقول إنّ الخدمةَ حيّة
     * **وإنّ مفتاحَ الإدارةِ مقبول**. أمّا فحصُ الحياةِ فيمرّ بلا مصادقة،
     * فيقول «سليم» لبوّابةٍ ترفض مفتاحَنا — وهو أسوأُ من لا فحص.
     *
     * و`401/403` تُفرَّق عن غيرِها في الرسالة: البوّابةُ حيّةٌ والمفتاحُ خطأ —
     * وهذا تشخيصٌ مختلفٌ تماماً عن «الخدمةُ لا تجيب»، وعلاجُه مختلف.
     */
    public static function litellm(): array
    {
        if (! \App\Support\Ai\Gateway\AiGateway::configured()) {
            return self::row(null, null, null,
                \App\Support\Ai\Gateway\AiGateway::whyNotReady() ?? 'البوّابةُ غيرُ مهيّأة');
        }

        $target = \App\Support\Ai\Gateway\AiGateway::url('/v1/models');
        // بوّابةُ الخروجِ الضيّقة — تسمح بـloopback الحرفيِّ لهذا الهدفِ وحدَه،
        // وتردُّ ما سواه إلى `hub_outbound_ok` كاملاً (انظر AiGateway::outboundGate)
        $gate = \App\Support\Ai\Gateway\AiGateway::outboundGate($target);
        if (! $gate['ok']) return self::row(null, null, null, $gate['why']);

        $to = \App\Support\Ai\Gateway\AiGateway::timeouts();

        $t0 = microtime(true);
        try {
            $res = Http::withOptions([
                'allow_redirects' => false,
                'curl'            => hub_resolve_pin($target, $gate['ip']),
            ])
                ->connectTimeout($to['connect'])->timeout(min($to['read'], self::READ_TIMEOUT))
                ->withHeaders([
                    'User-Agent'    => 'LynomiaHub-Probe/1.0',
                    'Authorization' => 'Bearer ' . \App\Support\Ai\Gateway\AiGateway::key(),
                ])
                ->get($target);
        } catch (\Throwable $e) {
            return self::row(false, null, self::since($t0), $e->getMessage());
        }

        $code = $res->status();
        $up = $code >= 200 && $code < 300;

        // عددُ النماذجِ المُعلَنةِ في البوّابة — خبرٌ صادقٌ لا تقدير؛ و«صفر»
        // حالةٌ مشروعةٌ تُقال: بوّابةٌ تعمل ولم يُربَط بها مزوّدٌ بعد.
        $count = null;
        if ($up) {
            $data = $res->json('data');
            if (is_array($data)) $count = count($data);
        }

        $why = $up ? null : ('ردَّت البوّابةُ HTTP ' . $code
            . (in_array($code, [401, 403], true)
                ? ' — البوّابةُ حيّةٌ ومفتاحُ الإدارةِ مرفوض'
                : ''));

        return self::row($up, $code, self::since($t0), $why,
            $up ? ('البوّابةُ تجيب والمفتاحُ مقبول'
                 . ($count === null ? '' : ' · نماذجُ مُعلَنة: ' . $count)) : null,
            ['models' => $count]);
    }

    /**
     * سطرٌ واحدٌ للمشغّل من النتيجة — تُستهلكه الشاشاتُ الثلاث بحرفه فلا تفترق
     * رسائلُها. والزمنُ فيه دائماً: فحصٌ ينجح في تسع ثوانٍ خبرٌ لا يقلّ عن الفشل.
     */
    public static function line(array $res): string
    {
        $ms = ($res['ms'] ?? null) === null ? '' : ' · ' . $res['ms'] . ' مللي ثانية';

        if (($res['up'] ?? null) === true) {
            return '✅ الاتصال ناجح — ' . ($res['info'] ?? 'الهدفُ يجيب') . $ms;
        }
        if (($res['up'] ?? null) === null) {
            /*
             * **وحالتان تحت `null` لا حالةٌ واحدة** (قبولُ إنتاجٍ · D/E).
             *
             *  · **بلا رمزِ HTTP** ⇒ لم يُجرَّب أصلاً: لا هدفَ مضبوطٌ، أو ردَّه
             *    الحارسُ قبل الشبكة. **ولا كلفةَ أُنفقت.**
             *  · **برمزِ HTTP** ⇒ **جرى الاتصالُ فعلاً** وأجاب الهدف، ولم يحمل
             *    ردُّه ما يُثبِت المطلوب. **وقد أُنفقت كلفتُه.**
             *
             * وخلطُهما كان يقول «لم يُجرَّب» عن نداءٍ **جرى ودُفع ثمنُه** —
             * فيُطمئن المشغّلَ أنّ شيئاً لم يُنفَق، ويُخفي عنه أنّ الدليلَ
             * وحدَه هو الناقص.
             */
            if (($res['code'] ?? null) !== null) {
                return '⚠️ جرى الاتصالُ ولم يُثبَت المطلوب (HTTP ' . $res['code'] . ')'
                    . $ms . ' — ' . ($res['error'] ?? 'لا دليلَ في الردّ');
            }

            return '⚠️ لم يُجرَّب الاتصال — ' . ($res['error'] ?? 'لا هدفَ مضبوط');
        }

        return '❌ فشل الاتصال' . (($res['code'] ?? null) ? ' (HTTP ' . $res['code'] . ')' : '')
            . $ms . ' — ' . ($res['error'] ?? 'سببٌ غير معروف');
    }

    /* ───────────────────────── أدوات ───────────────────────── */

    /** مللي ثانيةً منذ اللحظة — عددٌ صحيحٌ دائماً كي لا يفترق نوعُ الحقل */
    protected static function since(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    /**
     * رمزُ HTTP إن ذكرته الرسالةُ العربية — صيغتان قائمتان في `Odoo::rpc`:
     * «تعذر الوصول لخادم أودو (503)» و«خادم أودو متعثّر (HTTP 500)». استخراجٌ
     * لا اختراع: بلا ذكرٍ صريح يبقى `null` («لا نعرف» أصدقُ من صفر).
     */
    protected static function statusIn(string $msg): ?int
    {
        return preg_match('/\((?:HTTP\s*)?([1-5]\d{2})\)/', $msg, $m) ? (int) $m[1] : null;
    }

    /**
     * النتيجةُ بشكلها الواحد — والرسالةُ **مطموسةٌ مقصوصةٌ قبل أن تخرج**: هذه
     * النقطةُ الوحيدة التي تصنع `error`، فلا مسارَ يلتفّ حول المُطهِّر.
     */
    protected static function row(?bool $up, ?int $code, ?int $ms, ?string $error,
                                  ?string $info = null, array $detail = []): array
    {
        return [
            'up'     => $up,
            'code'   => $code,
            'ms'     => $ms,
            'error'  => ($error === null || $error === '') ? null
                : mb_substr(Redactor::text($error), 0, self::ERROR_MAX),
            'info'   => ($info === null || $info === '') ? null : mb_substr($info, 0, self::ERROR_MAX),
            // تفاصيلُ الهدف (إصدارُ أودو ومعرّفُ مستخدمه مثلاً) — تُطمَس بالمفتاح
            // كسائر المصفوفات التشخيصية، فلا يتسرّب شيءٌ من ردٍّ خارجيّ
            'detail' => Redactor::arr($detail),
        ];
    }
}
