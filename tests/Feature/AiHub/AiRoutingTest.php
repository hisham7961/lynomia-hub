<?php

namespace Tests\Feature\AiHub;

use App\Support\AiRouting;
use Tests\TestCase;

/**
 * **جدولُ القرارِ صفّاً صفّاً** (المرحلة ٢ · W7 · §٨).
 *
 * الخطّةُ تشترط حرفاً: «**محاكاةُ كلِّ صفٍّ في §٨ تُنتج القرارَ المكتوب**».
 * فالاختبارُ هنا **يمرّ على `TABLE` كلِّها** لا على عيّنةٍ منها: صفٌّ يُضاف غداً
 * بلا تغطيةٍ يُسقط الحزمةَ بدل أن يمرّ صامتاً.
 *
 * **ولا قاعدةَ بياناتٍ ولا شبكةَ في هذا الصنف** — قرارٌ خالصٌ من حالةٍ مُعطاة.
 */
class AiRoutingTest extends TestCase
{
    /** الصفوفُ المكتوبةُ في §٨ — **مكتوبةً هنا مرّةً ثانيةً باليد** */
    private const EXPECTED = [
        'auth'                   => ['retry' => 0, 'fallback' => false],
        'bad_request'            => ['retry' => 0, 'fallback' => false],
        'context_overflow'       => ['retry' => 0, 'fallback' => true],
        'unsupported_capability' => ['retry' => 0, 'fallback' => false],
        'budget_exceeded'        => ['retry' => 0, 'fallback' => false],
        'content_policy'         => ['retry' => 0, 'fallback' => false],
        'rate_limited'           => ['retry' => 1, 'fallback' => true],
        'transient'              => ['retry' => 2, 'fallback' => true],
        'model_gone'             => ['retry' => 0, 'fallback' => true],
        'provider_cooldown'      => ['retry' => 0, 'fallback' => true],
        'stream_interrupted'     => ['retry' => 0, 'fallback' => false],
    ];

    // ═══ الجدولُ كلُّه ═══

    /**
     * **التوقّعُ مكتوبٌ باليدِ لا مقروءٌ من الجدول.**
     *
     * اختبارٌ يقرأ `TABLE` ويؤكّد أنّها تساوي نفسَها لا يُثبت شيئاً: تعديلُ
     * `fallback` في الجدولِ كان سيُنتج حزمةً خضراءَ وسياسةً منقلبة. فالنسخةُ
     * الثانيةُ أعلاه هي **الشاهد**، وأيُّ انحرافٍ بينهما يُسقط الحزمة.
     */
    public function test_كلُّ_صفٍّ_في_الجدولِ_يُنتج_القرارَ_المكتوب(): void
    {
        foreach (self::EXPECTED as $cause => $want) {
            $d = AiRouting::decide($cause);

            $this->assertSame($cause, $d['cause'], "الصفُّ {$cause} أجاب بسببٍ آخر");
            $this->assertSame($want['retry'], $d['retry'],
                "**انحرافٌ عن §٨**: عددُ الإعاداتِ للصفِّ {$cause}");
            $this->assertSame($want['fallback'], $d['fallback'],
                "**انحرافٌ عن §٨**: سياسةُ الاحتياطِ للصفِّ {$cause}");
            $this->assertNotSame('', trim((string) $d['why']),
                "الصفُّ {$cause} بلا «لماذا» — وسياسةٌ بلا سببٍ تُنقَض بعد شهر");
        }
    }

    /** ولا صفَّ في الجدولِ خارجَ التغطية، ولا سببَ معروفٌ بلا صفّ */
    public function test_الجدولُ_والأسبابُ_والتغطيةُ_متطابقة(): void
    {
        $this->assertSame(AiRouting::CAUSES, array_keys(AiRouting::TABLE),
            'قائمةُ الأسبابِ والجدولُ افترقا');
        $this->assertSame(AiRouting::CAUSES, array_keys(self::EXPECTED),
            '**صفٌّ بلا تغطية**: أُضيف سببٌ إلى §٨ ولم يُختبَر');
    }

    // ═══ التصنيف — من ردِّ البوّابةِ إلى صفٍّ ═══

    public static function signals(): array
    {
        return [
            'اعتمادٌ مرفوض'     => [['code' => 401], 'auth'],
            'ممنوع'             => [['code' => 403], 'auth'],
            'طلبٌ مشوّه'        => [['code' => 400, 'body' => 'invalid parameter "foo"'], 'bad_request'],
            'سياقٌ أكبر'        => [['code' => 400, 'body' => 'This model maximum context length is 8192'], 'context_overflow'],
            'طولٌ مفرط'         => [['code' => 400, 'body' => 'input is too long'], 'context_overflow'],
            'سياسةُ محتوى'      => [['code' => 400, 'body' => 'content policy violation'], 'content_policy'],
            'مرشّحٌ حجب'        => [['code' => 400, 'body' => 'response was filtered'], 'content_policy'],
            'معدّلٌ متجاوَز'    => [['code' => 429], 'rate_limited'],
            'بوّابةٌ سيّئة'     => [['code' => 502], 'transient'],
            'خدمةٌ متوقّفة'     => [['code' => 503], 'transient'],
            'مهلةٌ انقضت'       => [['code' => 504], 'transient'],
            'عطلٌ داخليّ'       => [['code' => 500], 'transient'],
            'نموذجٌ مفقود'      => [['code' => 404], 'model_gone'],
            'بثٌّ منقطع'        => [['code' => 500, 'streamed' => true], 'stream_interrupted'],
            'استثناءٌ بلا رمز'  => [['code' => null, 'body' => 'cURL error 28'], 'transient'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('signals')]
    public function test_الإشارةُ_تُصنَّف_إلى_صفِّها(array $signal, string $cause): void
    {
        $this->assertSame($cause, AiRouting::classify($signal));
    }

    /**
     * **ثلاثةُ صفوفٍ لا تُصنَّف من ردٍّ — لأنّها حرّاسٌ قبليّة.**
     *
     * «قدرةٌ غيرُ مدعومة» يُمنَع **عند الاختيارِ لا عند الفشل**، و«تجاوزُ
     * الميزانيّة» حارسٌ قبليّ، و«المزوّدُ في تهدئة» حالةٌ محلّيّة. ولو اشتقّها
     * `classify` من ردٍّ لكان ذلك اعترافاً بأنّ الحارسَ لم يعمل.
     */
    public function test_الحرّاسُ_القبليّةُ_لا_تُشتقّ_من_ردِّ_البوّابة(): void
    {
        $pre = ['unsupported_capability', 'budget_exceeded', 'provider_cooldown'];

        foreach ([400, 401, 403, 404, 422, 429, 500, 502, 503, 504] as $code) {
            foreach (['', 'budget exceeded', 'capability not supported', 'cooldown'] as $body) {
                $this->assertNotContains(AiRouting::classify(['code' => $code, 'body' => $body]), $pre,
                    "‏{$code} صُنِّف حارساً قبليّاً — والحارسُ القبليُّ لا يُكتشَف من ردّ");
            }
        }
    }

    // ═══ الثلاثةُ التي تمنع الاحتياطَ حيث يبدو مغرياً ═══

    /** ‏401 لا يُعاد ولا يُحتاط — **والاحتياطُ يُخفي العطل** */
    public function test_اعتمادٌ_خاطئٌ_لا_يُعاد_ولا_يُحتاط(): void
    {
        $d = AiRouting::decideFrom(['code' => 401]);

        $this->assertSame(0, $d['retry']);
        $this->assertFalse($d['fallback'], '**الاحتياطُ على 401 يُخفي مفتاحاً خاطئاً سنةً**');
        $this->assertNull(AiRouting::delayFor($d, 1), 'اختُرعت مهلةٌ حيث لا إعادة');
    }

    /** البثُّ المنقطعُ لا يُكرَّر — **قد يُكرِّر أثراً جانبيّاً** */
    public function test_البثُّ_المنقطعُ_لا_يُعاد_ولا_يُحتاط_مهما_كان_الرمز(): void
    {
        foreach ([null, 200, 429, 500, 502, 503] as $code) {
            $d = AiRouting::decideFrom(['code' => $code, 'streamed' => true]);

            $this->assertSame('stream_interrupted', $d['cause'],
                'رمزٌ عابرٌ غلب البثَّ المنقطعَ — والأثرُ الجانبيُّ يُنفَّذ مرّتين');
            $this->assertSame(0, $d['retry']);
            $this->assertFalse($d['fallback']);
        }
    }

    /** وتجاوزُ الميزانيّةِ لا يُحتاط — **الاحتياطُ يُضاعف الإنفاقَ الممنوع** */
    public function test_تجاوزُ_الميزانيّةِ_لا_يُحتاط(): void
    {
        $d = AiRouting::decide('budget_exceeded');

        $this->assertFalse($d['fallback']);
        $this->assertSame(0, $d['retry']);
    }

    // ═══ المهلة ═══

    /** ‏`Retry-After` المُعلَنةُ تُحترَم — بسقفٍ عاقلٍ يمنع مهلةً بالساعات */
    public function test_مهلةُ_المعدّلِ_من_الترويسةِ_ثمّ_احتياط(): void
    {
        $d = AiRouting::decide('rate_limited');

        $this->assertSame(7, AiRouting::delayFor($d, 1, 7));
        $this->assertSame(60, AiRouting::delayFor($d, 1, 3600), 'مهلةٌ بلا سقفٍ تُجمّد الطلبَ ساعة');
        $this->assertSame(1, AiRouting::delayFor($d, 1, 0), 'مهلةُ صفرٍ تُنتج حلقةً محمومة');
        $this->assertNull(AiRouting::delayFor($d, 2, 7), '**إعادةٌ ثانيةٌ على 429 — والجدولُ يأذن بواحدة**');
    }

    /** والعابرُ يتراجع أُسّيّاً مرّتين ثمّ يُحتاط */
    public function test_العابرُ_مرّتان_بتراجعٍ_أُسّيّ(): void
    {
        $d = AiRouting::decide('transient');

        $this->assertSame(2, AiRouting::delayFor($d, 1));
        $this->assertSame(4, AiRouting::delayFor($d, 2));
        $this->assertNull(AiRouting::delayFor($d, 3), 'الثالثةُ مُنِعت في §٨');
    }

    // ═══ الحارسُ الثاني — الميزانيّة ═══

    public function test_السقفُ_يمنع_القفزةَ_التي_تتجاوزه(): void
    {
        $this->assertTrue(AiRouting::withinBudget(0.02, 0.01, 0.05)['ok']);
        $this->assertFalse(AiRouting::withinBudget(0.04, 0.02, 0.05)['ok']);
        $this->assertTrue(AiRouting::withinBudget(9.99, 5.0, 0.0)['ok'], 'سقفُ صفرٍ يعني «بلا سقف»');
    }

    /**
     * **كلفةٌ مجهولةٌ لا تمرّ تحت سقف** — و`null` ليست صفراً.
     *
     * حسابُ المجهولِ صفراً يجعل حارسَ الكلفةِ يُمرّر ما لا يُقاس، فيصير السقفُ
     * زينةً: يُوضَع ثمّ يُلتَفُّ حوله بأوّلِ نموذجٍ بلا سعرٍ معروف.
     */
    public function test_كلفةٌ_مجهولةٌ_لا_تمرّ_تحت_سقف(): void
    {
        $blocked = AiRouting::withinBudget(0.0, null, 0.05);
        $this->assertFalse($blocked['ok']);
        $this->assertStringContainsString('مجهول', (string) $blocked['why']);

        $this->assertTrue(AiRouting::withinBudget(0.0, null, 0.0)['ok'],
            'بلا سقفٍ لا شيءَ يُقاس — فالمجهولُ يمرّ');
    }

    public function test_تقديرُ_القفزةِ_من_الأسعارِ_المخزّنة(): void
    {
        $pricing = [
            'input_per_1k'  => ['v' => 0.0025, 'src' => 'litellm'],
            'output_per_1k' => ['v' => 0.01,   'src' => 'litellm'],
        ];

        $this->assertEqualsWithDelta(0.0025 + 0.005,
            (float) AiRouting::estimateHop($pricing, 1000, 500), 0.000001);

        $this->assertNull(AiRouting::estimateHop([], 1000, 500),
            '**سعرٌ مجهولٌ حُسِب صفراً** — والصفرُ يكذب على حارسِ الميزانيّة');
        $this->assertNull(AiRouting::estimateHop(
            ['input_per_1k' => ['v' => null, 'src' => 'unknown']], 1000, 500));
    }

    // ═══ الحارسُ الثالث — التهدئة ═══

    public function test_التهدئةُ_بعد_ثلاثةِ_إخفاقاتٍ_متتالية(): void
    {
        $id = 'prov-' . substr(sha1('cooldown'), 0, 12);

        AiRouting::noteSuccess($id);
        $this->assertFalse(AiRouting::inCooldown($id));

        AiRouting::noteFailure($id);
        AiRouting::noteFailure($id);
        $this->assertFalse(AiRouting::inCooldown($id), 'استُبعد المزوّدُ قبل بلوغِ العتبة');

        AiRouting::noteFailure($id);
        $this->assertTrue(AiRouting::inCooldown($id));

        AiRouting::noteSuccess($id);
        $this->assertFalse(AiRouting::inCooldown($id), '**مزوّدٌ تعافى ويبقى معاقَباً**');
    }

    /** وما ليس ذنبَ المزوّدِ لا يُحسَب عليه */
    public function test_عيبُنا_لا_يُهدِّئ_مزوّداً_سليماً(): void
    {
        foreach (['bad_request', 'content_policy', 'context_overflow',
                  'unsupported_capability', 'stream_interrupted'] as $cause) {
            $this->assertNotContains($cause, AiRouting::FAULTS,
                "‏{$cause} يُحسَب على المزوّدِ وهو ليس ذنبَه");
        }

        foreach (['auth', 'rate_limited', 'transient', 'model_gone'] as $cause) {
            $this->assertContains($cause, AiRouting::FAULTS);
        }
    }

    // ═══ حدودٌ ═══

    /** سببٌ لا يعرفه الجدولُ يُعامَل عابراً — **لا يُرفَض الطلبُ لجهلِنا** */
    public function test_سببٌ_مجهولٌ_يسقط_إلى_العابر(): void
    {
        $d = AiRouting::decide('something_we_never_saw');

        $this->assertSame('transient', $d['cause']);
        $this->assertSame(2, $d['retry']);
    }

    public function test_العمقُ_الأقصى_اثنتان(): void
    {
        $this->assertSame(2, AiRouting::MAX_DEPTH, '**الحارسُ ①**: ثلاثةُ نماذجَ لا أكثر');
    }
}
