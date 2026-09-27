<?php

namespace Tests\Feature\AiHub;

use App\Support\Ai\Gateway\AiChat;
use App\Support\Ai\Routing\AiRouting;
use App\Support\Ai\Ask\AskFailures;
use Tests\TestCase;

/**
 * **تصنيفُ الإخفاقِ في زمنِ الحوكمة** (المرحلة ٤ · P4-W8).
 *
 * ── **العيبُ الذي أنتج هذا الملفّ** ──
 *
 * أوّلُ قبولِ إنتاجٍ حقيقيٍّ بلغ المزوّدَ فعلاً وعاد:
 *
 * ```
 * HTTP 429 · You have no credits remaining. Add credits to continue using the API
 * ```
 *
 * والنظامُ كان يقرؤها **حدَّ معدّل**: ينتظر `Retry-After` ثمّ يُعيد النداءَ ثمّ
 * يحتاط إلى نموذجٍ آخرَ **على الاعتمادِ الميّتِ نفسِه**. أي ثلاثةُ نداءاتٍ
 * ضائعةٍ ومهلةٌ مهدورةٌ عن رصيدٍ نفد — **ورسالةٌ للمستخدمِ تقول «انتظر قليلاً»
 * عن شيءٍ لا يُصلحه الانتظارُ أبداً**.
 *
 * ── **والدليلُ من الحزمةِ المثبَّتةِ لا من الظنّ** ──
 *
 * ‏`litellm_core_utils/exception_mapping_utils.py` يبني رسالةَ الخطأ
 * `f"RateLimitError: {exception_provider} - {message}"` — أي أنّ **نصَّ المزوّدِ
 * الأصليَّ ينجو حرفيّاً** ويصل إلينا في المتن. فالتصنيفُ على دلالةِ المتنِ
 * قراءةُ عقدٍ مقيسٍ لا تخمين.
 *
 * وفي `exceptions.py:21` تُفرّق الحزمةُ نفسُها بين `VENDOR_RATE_LIMIT`
 * و`LITELLM_RATE_LIMIT` — **فحتّى صاحبُ العقدِ لا يعدّ ٤٢٩ معنىً واحداً**.
 *
 * **ولا اسمَ مزوّدٍ في القاعدة** — الدلالاتُ عامّةٌ (`credit` · `quota` · `billing`)
 * ويحرسها `AiCatalogFoundationTest`.
 */
class AiFailureTaxonomyTest extends TestCase
{
    /** ما يعود من المزوّدِ حين ينفد الرصيد — نصُّ القبولِ الحقيقيِّ حرفاً */
    private const CREDITS_BODY =
        'RateLimitError: - You have no credits remaining. Add credits to continue using the API';

    // ═══ ① نفادُ الرصيدِ ليس حدَّ معدّل ═══

    public function test_نفادُ_الرصيدِ_يُصنَّف_رصيداً_لا_حدَّ_معدّل(): void
    {
        $this->assertSame(AskFailures::PROVIDER_CREDITS,
            AiChat::classify(429, self::CREDITS_BODY),
            'ردُّ «لا رصيدَ متبقٍّ» صُنِّف حدَّ معدّلٍ — فيُنتظَر ويُعاد بلا طائل');
    }

    public function test_حدُّ_المعدّلِ_الحقيقيُّ_يبقى_حدَّ_معدّل(): void
    {
        $this->assertSame(AskFailures::RATE_LIMITED,
            AiChat::classify(429, 'RateLimitError: - Rate limit reached for requests'));
    }

    public function test_رمزُ_الدفعِ_المطلوبِ_٤٠٢_رصيدٌ_أيضاً(): void
    {
        $this->assertSame(AskFailures::PROVIDER_CREDITS,
            AiChat::classify(402, 'Payment Required'));
    }

    // ═══ ② والقرارُ يتبع التصنيف: لا إعادةَ ولا احتياطَ على الاعتمادِ نفسِه ═══

    public function test_التوجيهُ_يعرف_سببَ_الرصيدِ_ولا_يُعيد_المحاولة(): void
    {
        $cause = AiRouting::classify(['code' => 429, 'body' => self::CREDITS_BODY]);
        $this->assertSame('provider_credits', $cause);

        $d = AiRouting::decide($cause);
        $this->assertSame(0, $d['retry'], 'إعادةُ المحاولةِ لا تُنشئ رصيداً');
        $this->assertTrue($d['fallback'], 'الاحتياطُ إلى مزوّدٍ آخرَ صحيحٌ — فالرصيدُ رصيدُ حسابٍ بعينِه');
        $this->assertSame('other_provider', $d['conditional'],
            'الاحتياطُ على المزوّدِ نفسِه يصطدم بالرصيدِ نفسِه');
    }

    public function test_نفادُ_الرصيدِ_يُحسَب_على_المزوّدِ_فيُهدَّأ(): void
    {
        $this->assertContains('provider_credits', AiRouting::FAULTS,
            'حسابٌ بلا رصيدٍ يجب أن يُستبعَد مؤقّتاً بدل أن يُطلَب في كلِّ سؤال');
    }

    public function test_لا_مهلةَ_تُخترَع_حيث_لا_إعادة(): void
    {
        $d = AiRouting::decide('provider_credits');
        $this->assertNull(AiRouting::delayFor($d, 1, 30),
            'مهلةُ `Retry-After` من ردِّ رصيدٍ لا تُحترَم — لا شيءَ ينقضي بانقضائها');
    }

    // ═══ ③ الرسالةُ تقول الحقيقةَ ولا تُرسل المستخدمَ ينتظر ═══

    public function test_رسالةُ_الرصيدِ_لا_تقول_انتظر(): void
    {
        $msg = AskFailures::message(AskFailures::PROVIDER_CREDITS);

        $this->assertNotSame('', trim($msg));
        $this->assertStringNotContainsString('انتظر', $msg);
        $this->assertNotSame(AskFailures::message(AskFailures::RATE_LIMITED), $msg);
    }

    public function test_الرمزُ_معروفٌ_وليس_صلاحيّة(): void
    {
        $this->assertTrue(AskFailures::known(AskFailures::PROVIDER_CREDITS));
        $this->assertFalse(AskFailures::isPermission(AskFailures::PROVIDER_CREDITS),
            'نفادُ الرصيدِ عطلُ تشغيلٍ لا بابٌ مغلقٌ على المستخدم');
    }

    // ═══ ④ وكلُّ رمزٍ في التصنيفِ له رسالةٌ — حارسٌ يمنع رمزاً أخرسَ ═══

    public function test_كلُّ_رمزٍ_له_رسالةٌ_ولا_رمزَ_أخرس(): void
    {
        foreach (AskFailures::CODES as $code) {
            $this->assertArrayHasKey($code, AskFailures::MESSAGES, "الرمز {$code} بلا رسالة");
            $this->assertNotSame('', trim(AskFailures::MESSAGES[$code]));
        }
    }
}
