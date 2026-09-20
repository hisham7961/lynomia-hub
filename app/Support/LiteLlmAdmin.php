<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * **عميلُ واجهةِ إدارةِ LiteLLM** (المرحلة ٢ · W3).
 *
 * **وهذا ليس طبقةَ نقلٍ لمزوّد.** لا يعرف اسمَ مزوّدٍ واحد — يعرف **عقدَ
 * البوّابةِ وحدَه**، وهو عقدٌ عامٌّ قُرئ من الصورةِ المثبّتةِ في W0:
 * لا حقلَ في `POST /credentials` ولا في `POST /model/new` يخصّ مزوّداً بعينِه.
 *
 * **وثلاثةُ ثوابتَ يحرسها كلُّ نداء:**
 *
 *  ① **السرُّ يمرّ ولا يُخزَّن.** قيمُ اعتمادِ المزوّدِ تُمرَّر إلى
 *     `POST /credentials` في الذاكرةِ ولا تُكتَب في قاعدةِ Hub ولا في سجلّ.
 *     و`Redactor` يمرّ على كلِّ رسالةِ خطأٍ قبل أن تُعاد.
 *
 *  ② **لا نداءَ خارجَ الحارس.** كلُّ هدفٍ يمرّ بـ`AiGateway::outboundGate()`
 *     ذي الشروطِ الخمسة، **ولا يُتَّبع أيُّ تحويل** — فردُّ `302` من البوّابةِ
 *     لا يقود الطلبَ إلى هدفٍ لم يمرّ بالحارس.
 *
 *  ③ **الكلفةُ تُعلَن ولا تُخفى.** `testConnection()` وحدَها تُنفق — أثبت W0
 *     أنّها تستدعي `acompletion` بـ`max_tokens` افتراضُها ١٦، وتولّد صورةً
 *     كاملةً في وضعِ التوليدِ المرئيّ. فلا تُستدعى إلّا بإقرارٍ صريح.
 *
 * **والشكلُ واحدٌ لكلِّ الردود** — `['ok','code','data','error']` — فلا يخترع
 * كلُّ نداءٍ شكلَه.
 */
final class LiteLlmAdmin
{
    /** مهلةُ قراءةٍ قصوى لنداءِ إدارة — أقصرُ من مهلةِ التوليدِ عمداً */
    public const ADMIN_READ_TIMEOUT = 15;

    /** الشكلُ الموحَّدُ للردّ */
    public const SHAPE = ['ok', 'code', 'data', 'error'];

    // ── القراءة ────────────────────────────────────────────────────────

    /** النماذجُ المُعلَنةُ للمفتاح — يُثبِت الحياةَ وقبولَ المفتاح (كلفةٌ صفر) */
    public static function models(): array
    {
        return self::call('GET', '/v1/models');
    }

    /** بياناتُ النماذجِ المُسجَّلة — **مصدرُ القدراتِ والحدودِ والأسعار** (كلفةٌ صفر) */
    public static function modelInfo(): array
    {
        return self::call('GET', '/model/info');
    }

    /** النماذجُ المهمَلةُ لدى المزوّدين — يخدم كشفَ ما سقط (كلفةٌ صفر) */
    public static function deprecations(): array
    {
        return self::call('GET', '/model/deprecations');
    }

    /*
     * ── **الإنفاق — قراءةٌ من مصدرِ الحقيقةِ الواحد** (§١٣ · W8) ─────────
     *
     * البوّابةُ تملك `LiteLLM_SpendLogs` و`DailyUserSpend` وأخواتِها. **فلا
     * جدولَ استهلاكٍ في Hub**: جدولٌ ثانٍ يفترق عن الأصلِ خلال أسابيعَ ثمّ
     * يُصدَّق أحدُهما عشوائيّاً.
     *
     * والمسارانِ أدناه من الإحدى والثلاثين التي أثبتها W0 [C] — **لا يُخترَع
     * مسارٌ لم يُرَ**. وكلُّها `GET` قراءةٌ محضة؛ و`POST /global/spend/reset`
     * تدميريّةٌ فلا تُلمَس.
     */

    /** الإنفاقُ مُجمَّعاً بالنموذج — **تكلفةٌ تقديريّةٌ من خريطةِ الأسعار** */
    public static function spendByModel(): array
    {
        return self::call('GET', '/global/spend/models');
    }

    /** نشاطُ الطلباتِ بالنموذج — عددٌ ورموزٌ عبر الزمن */
    public static function activityByModel(): array
    {
        return self::call('GET', '/global/activity/model');
    }

    /** سردُ الدُّفعاتِ الأخيرة — للتشخيصِ لا للفوترة */
    public static function spendLogs(int $limit = 25): array
    {
        return self::call('GET', '/spend/logs?limit=' . max(1, min(100, $limit)));
    }

    /**
     * **هويّةُ المزوّدين المدعومين في هذا الإصدار** — قراءةٌ محضةٌ بكلفةِ صفر.
     *
     * وصفُ المسارِ في شيفرةِ البوّابةِ حرفيّاً: *«Returns provider name,
     * description, and required parameters for each provider»*، وجسمُه يدور
     * على `litellm.provider_list` — أي على تعدادِ المزوّدين كلِّه.
     *
     * **فقائمةُ المزوّدين تُقرأ من البوّابةِ ولا تُنسَخ إلى Hub.** واللقطةُ في
     * `config/ai_providers.php` احتياطٌ حين تكون البوّابةُ غيرَ مُعدَّةٍ أو
     * ساقطة، لا مصدرُ حقيقةٍ ينافسها.
     *
     * **وحقولُ كلِّ مزوّدٍ في هذا الردِّ فارغةٌ لأكثرِ المزوّدين** — أثبته
     * قياسُ المصدر (`docs/ai-hub/20-provider-discovery.md` §٤٫٢). فيُؤخَذ منه
     * **الاسمُ** ويُترَك ما عداه.
     */
    public static function providerSettings(): array
    {
        return self::call('GET', '/model/settings');
    }

    /** سردُ الاعتمادات — **البوّابةُ تُقنِّع القيمَ بنفسِها** (W0 · C2) */
    public static function credentials(): array
    {
        return self::call('GET', '/credentials');
    }

    /** اعتمادٌ باسمِه — مُقنَّعٌ أيضاً بأربعِ خاناتٍ ظاهرة */
    public static function credential(string $name): array
    {
        return self::call('GET', '/credentials/by_name/' . rawurlencode($name));
    }

    // ── الكتابة ────────────────────────────────────────────────────────

    /**
     * **إنشاءُ اعتماد** — هنا يمرّ السرُّ، ومرّةً واحدةً في حياتِه.
     *
     * ‏`$values` كائنٌ حرٌّ كما أثبت W0 (`credential_values` حقلُ Json حرّ) —
     * فلا يحتاج هذا الصنفُ معرفةَ شكلِ مصادقةِ أيِّ مزوّد.
     *
     * **و`$info` غيرُ مشفَّرٍ عند البوّابة** — فلا يُكتَب فيه سرٌّ أبداً.
     */
    public static function createCredential(string $name, array $values, array $info = []): array
    {
        if ($values === []) {
            return self::fail('لا قيمَ اعتمادٍ — الإنشاءُ بلا قيمٍ يُرَدّ');
        }

        return self::call('POST', '/credentials', [
            'credential_name'   => $name,
            'credential_values' => $values,
            'credential_info'   => $info,
        ]);
    }

    /** تدويرُ اعتماد — الاسمُ نفسُه وقيمٌ جديدة */
    public static function updateCredential(string $name, array $values, array $info = []): array
    {
        if ($values === []) {
            return self::fail('لا قيمَ اعتمادٍ — التدويرُ بلا قيمٍ يُرَدّ');
        }

        return self::call('PATCH', '/credentials/' . rawurlencode($name), [
            'credential_name'   => $name,
            'credential_values' => $values,
            'credential_info'   => $info,
        ]);
    }

    /** **إبطالٌ قاطع** — السرُّ يزول فعلاً عند البوّابة، لا حذفاً ناعماً */
    public static function deleteCredential(string $name): array
    {
        return self::call('DELETE', '/credentials/' . rawurlencode($name));
    }

    /**
     * **تسجيلُ نموذج** — بمرجعِ اعتمادٍ لا بمفتاح.
     *
     * `litellm_credential_name` (W0 · `types/router.py:320`) هو ما يجعل السرَّ
     * يُرسَل مرّةً واحدةً: كلُّ تسجيلٍ بعدها يحمل **اسماً**.
     */
    public static function createModel(string $modelName, string $upstream, string $credentialName, array $extraParams = [], array $modelInfo = []): array
    {
        return self::call('POST', '/model/new', [
            'model_name'     => $modelName,
            'litellm_params' => array_merge($extraParams, [
                'model'                   => $upstream,
                'litellm_credential_name' => $credentialName,
            ]),
            'model_info'     => $modelInfo,
        ]);
    }

    /** حذفُ نموذجٍ من البوّابة */
    public static function deleteModel(string $modelId): array
    {
        return self::call('POST', '/model/delete', ['id' => $modelId]);
    }

    /** **تعطيلٌ غيرُ مُتلِف** — يُفضَّل على الحذفِ حيث أمكن */
    public static function blockModel(string $modelId): array
    {
        return self::call('POST', '/model/block', ['model_id' => $modelId]);
    }

    public static function unblockModel(string $modelId): array
    {
        return self::call('POST', '/model/unblock', ['model_id' => $modelId]);
    }

    // ── النداءُ المُكلِف ───────────────────────────────────────────────

    /**
     * ⚠️ **يُنفق رصيداً — وهذه ليست تحذيراً احترازيّاً بل حقيقةً مقروءة.**
     *
     * ‏W0 تتبّع السلسلةَ إلى `litellm.ahealth_check` ثمّ إلى مُشغّلاتِ الأوضاع:
     * `chat` ⇒ `acompletion` بـ`max_tokens` افتراضُها ١٦ · `embedding` ⇒
     * `aembedding` · `image_generation` ⇒ **صورةٌ حقيقيّة**. وقبلها تُضبَط
     * `cache = {"no-cache": True}` **عمداً** كي لا يُجاب من مخزَّن.
     *
     * **فلا تُستدعى إلّا بإقرارٍ صريحٍ من المُستدعي** (`$costAcknowledged`)،
     * و`$mode` يُمرَّر دائماً ولا يُترَك للاستنتاجِ الآليّ — لأنّ الاستنتاجَ قد
     * يقع على وضعٍ أغلى بكثير.
     */
    public static function testConnection(array $litellmParams, string $mode, bool $costAcknowledged = false): array
    {
        if (! $costAcknowledged) {
            return self::fail('اختبارُ الاعتمادِ يُنفق رصيداً — يلزم إقرارٌ صريحٌ بالكلفة');
        }
        if ($mode === '') {
            return self::fail('وضعُ الاختبارِ يُمرَّر صراحةً ولا يُترَك للاستنتاج');
        }

        /*
         * ── **`model` إلزاميٌّ في هذا المسار — والعقدُ مقروءٌ لا مفترَض** ──
         *
         * مدخلُ المسارِ يقرأ الحقلَ بتساهلٍ (`.get()`)، لكنّ المعالجَ الذي يليه
         * في `proxy/health_check.py:774` يقرؤه **بقوسين** لا بـ`.get()`.
         * فحمولةٌ بلا `model` تُسقط المسارَ بـ`KeyError` يُغلَّف في `500`
         * ويعود للمستخدمِ نصّاً غامضاً: `'model'`. وهو ما وقع في أوّلِ قبولِ
         * إنتاجٍ حقيقيّ — **واعتمادٌ سليمٌ ظهر عطلاً في البوّابة، والمفتاحُ لم
         * يُجرَّب قطّ**. والأسطرُ بأرقامِها في
         * `docs/ai-hub/27-credential-probe-contract.md` — **وهي هناك لا هنا**
         * لأنّ متنَ ذلك السطرِ يحمل اسمَ مزوّدٍ بعينِه، ولا اسمَ مزوّدٍ في
         * سطحِ الذكاءِ داخلَ Hub.
         *
         * والحارسُ هنا لا في المُستدعي: هذه **نقطةُ اختناقٍ واحدة**، فمُستدعٍ
         * جديدٌ غداً لا يستطيع إعادةَ العطلِ من حيث لا يدري.
         *
         * **ولا يُخترَع اسمٌ عند الغياب.** لا اسمَ Hub الداخليَّ ولا غيرَه:
         * المزوّدُ لا يعرف أسماءَنا، وإرسالُ واحدٍ منها يُنتج فشلاً يبدو
         * «مفتاحاً خاطئاً» وهو **خطأُ تسمية** — فيُطارَد اعتمادٌ سليم.
         */
        if (trim((string) ($litellmParams['model'] ?? '')) === '') {
            return self::fail('فحصُ الاتصالِ يلزمه اسمُ النموذجِ عند المزوّد — '
                . 'البوّابةُ تختبر (اعتماداً × نموذجاً) ولا تختبر اعتماداً وحدَه');
        }

        return self::call('POST', '/health/test_connection', [
            'litellm_params' => $litellmParams,
            'mode'           => $mode,
        ]);
    }

    // ── المحرّك ────────────────────────────────────────────────────────

    /**
     * **النداءُ الواحد** — وكلُّ ما سبق يمرّ به، فلا حارسَ يُنسى في مسار.
     */
    private static function call(string $method, string $path, array $body = []): array
    {
        if (! AiGateway::configured()) {
            return self::fail(AiGateway::whyNotReady() ?? 'البوّابةُ غيرُ مهيّأة');
        }

        $url  = AiGateway::url($path);
        $gate = AiGateway::outboundGate($url);
        if (! $gate['ok']) {
            return self::fail($gate['why']);
        }

        $to = AiGateway::timeouts();

        try {
            $req = Http::withOptions(AiGateway::requestOptions($gate['ip'], $url))
                ->connectTimeout($to['connect'])
                ->timeout(min($to['read'], self::ADMIN_READ_TIMEOUT))
                ->withHeaders([
                    'User-Agent'    => 'LynomiaHub-Admin/1.0',
                    'Authorization' => 'Bearer ' . AiGateway::key(),
                    'Accept'        => 'application/json',
                ]);

            $res = $body === []
                ? $req->send($method, $url)
                : $req->send($method, $url, ['json' => $body]);
        } catch (\Throwable $e) {
            // **المُطهِّرُ قبل الإعادة** — رسالةُ استثناءٍ قد تحمل عنواناً أو ترويسة
            return self::fail(Redactor::text($e->getMessage()));
        }

        $code = $res->status();
        $ok   = $code >= 200 && $code < 300;

        return [
            'ok'    => $ok,
            'code'  => $code,
            'data'  => $ok ? (is_array($res->json()) ? $res->json() : []) : null,
            // **مسارُ الاعتمادِ لا يُعيد جسمَ ردٍّ أبداً** — حزامٌ ثانٍ فوق
            // `Redactor`: صيغةُ مفتاحٍ لم نعرفها بعدُ لا تُسرَّب من أخطرِ مسار.
            'error' => $ok ? null : self::explain($code, self::touchesCredentials($path) ? '' : $res->body()),
        ];
    }

    /** رسالةٌ عربيّةٌ تفرّق الحالاتِ التي تُخلَط عادةً */
    private static function explain(int $code, string $body): string
    {
        $why = match (true) {
            $code === 401 || $code === 403 => 'البوّابةُ حيّةٌ ومفتاحُ الإدارةِ مرفوض',
            $code === 404                  => 'المورِدُ غيرُ موجودٍ عند البوّابة',
            $code === 400                  => 'طلبٌ مرفوضُ الشكل',
            $code >= 500                   => 'عطلٌ داخليٌّ في البوّابة',
            default                        => '',
        };

        $tail = Redactor::text(mb_substr(trim($body), 0, 180));

        return 'HTTP ' . $code . ($why !== '' ? ' — ' . $why : '') . ($tail !== '' ? ' · ' . $tail : '');
    }

    /** أَيمسّ هذا المسارُ اعتماداً؟ — فيُمنَع جسمُ ردِّه من العودة */
    private static function touchesCredentials(string $path): bool
    {
        return str_contains($path, '/credentials');
    }

    private static function fail(string $why): array
    {
        return ['ok' => false, 'code' => null, 'data' => null, 'error' => $why];
    }
}
