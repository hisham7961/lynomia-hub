<?php

namespace Tests\Feature;

use App\Models\AiUsageEvent;
use App\Providers\AppServiceProvider;
use App\Support\AiGateway;
use App\Support\AiLedger;
use App\Support\AskPolicy;
use Tests\TestCase;

/**
 * **الدفعةُ الرابعة من #35أ** — خمسةُ مفاتيحَ: الجلسةُ والحجزُ والسقفُ والمهلتان.
 *
 * تكملةُ الجولاتِ الثلاثِ بقاعدتها نفسِها: **الأثرُ المُعلَن هو المرجع**،
 * والبرهانُ **طفرةٌ** تُعطِّل قراءةَ المفتاح فيموت اختبارُه.
 *
 * | المفتاح | الأثرُ المُثبَت |
 * |---|---|
 * | `auth.session_min` | عمرُ الجلسة — **وفارغُه يترك افتراضيَّ الإطار** لا يُصفّره |
 * | `ai.reservation_ttl_min` | متى يُفرَج عن حجزِ طلبٍ لم يُسوَّ — وأرضيّتُه دقيقةٌ واحدة |
 * | `ask.cost_ceiling` | سقفُ الكلفةِ المقدَّرة — والصفرُ يعني ألّا سقف |
 * | `ai.timeout_connect` · `ai.timeout_read` | مهلتا البوّابة، محصورتان [١،٣٠] و[١،٣٠٠] |
 *
 * ── **وثلاثةُ مفاتيحَ في هذه الدفعةِ تحرس «الصفرُ ليس تعطيلاً»** ──
 *
 * `auth.session_min` فارغاً **لا يُصفّر الجلسة** بل يترك افتراضَ الإطار —
 * وهذا ما فنّد بلاغَه في §٤ه. و`ai.reservation_ttl_min` صفراً **لا يُفرج
 * عن كلِّ حجزٍ لحظةَ وضعِه** بل تُفرَض دقيقةٌ صلبة، وإلّا بطَل سقفُ
 * الميزانيّةِ كلُّه بإدخالٍ واحد. و`ask.cost_ceiling` صفراً **يعني ألّا
 * سقفَ** لا سقفاً بصفر. **فثلاثُ دلالاتٍ مختلفةٍ لصفرٍ واحد** — ومن
 * قاسها باسمِ المفتاحِ أخطأ في اثنتين.
 */
class SettingsBehaviourRound4Test extends TestCase
{
    /* ═══════════ ١ · auth.session_min ═══════════ */

    /** **عمرُ الجلسةِ يُقرأ من الإعداد** — بأرضيّةِ خمسِ دقائق */
    public function test_عمرُ_الجلسة_يُقرأ_من_الإعداد_بأرضيّةٍ(): void
    {
        $this->seedCore();

        $this->hubSetting('auth.session_min', '240');
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame(240, (int) config('session.lifetime'), 'عمرُ الجلسة لم يُقرأ من الإعداد');

        $this->hubSetting('auth.session_min', '1');
        (new AppServiceProvider($this->app))->boot();
        $this->assertSame(5, (int) config('session.lifetime'),
            'أرضيّةُ الخمسِ دقائقَ لم تُفرَض على قيمةٍ أصغر');
    }

    /**
     * **والفارغُ يترك افتراضَ الإطار — لا يُصفّر الجلسة.**
     *
     * بُلِّغ عن هذا المفتاحِ أنّ «٠ تُسقط الضبطَ صامتاً»، **وفُنِّد في §٤ه**:
     * الكتالوجُ يقول «فارغه يترك افتراضيَّ الإطار». وهذا الاختبارُ يثبّت
     * التفنيد — **فتفنيدٌ بلا اختبارٍ يُعاد البلاغُ بعده**.
     */
    public function test_عمرُ_جلسةٍ_فارغٌ_يترك_افتراضَ_الإطار(): void
    {
        $this->seedCore();
        config(['session.lifetime' => 120]);

        $this->hubSetting('auth.session_min', '0');
        (new AppServiceProvider($this->app))->boot();

        $this->assertSame(120, (int) config('session.lifetime'),
            'الصفرُ صفّر عمرَ الجلسةِ بدل أن يترك افتراضَ الإطار');
    }

    /* ═══════════ ٢ · ai.reservation_ttl_min ═══════════ */

    /** **الحجزُ الذي طال يُفرَج عنه بمدّةِ المفتاح** — وما دونَها يبقى محجوزاً */
    public function test_مهلةُ_حجزِ_الذكاء_تقرّر_متى_يُفرَج(): void
    {
        $this->seedCore();
        $old = $this->reserved(20);
        $new = $this->reserved(5);

        $this->hubSetting('ai.reservation_ttl_min', '15');
        $this->assertSame(1, AiLedger::expireStale(), 'حجزٌ عمرُه ٢٠ دقيقةً لم يُفرَج بمهلةِ ١٥');

        $this->assertSame('expired', (string) $old->fresh()->status);
        $this->assertSame('reserved', (string) $new->fresh()->status,
            'حجزٌ عمرُه ٥ دقائقَ أُفرج عنه بمهلةِ ١٥');

        $this->hubSetting('ai.reservation_ttl_min', '3');
        $this->assertSame(1, AiLedger::expireStale(), 'خفضُ المهلةِ إلى ٣ لم يُفرج حجزَ الخمس');
        $this->assertSame('expired', (string) $new->fresh()->status);
    }

    /**
     * **وأرضيّةُ الدقيقةِ الواحدةِ تحرس سقفَ الميزانيّة.**
     *
     * صفرٌ في الشاشةِ كان سيُفرج عن حجزِ كلِّ طلبٍ لحظةَ وضعِه — فيُبطل
     * السقفَ كلَّه بإدخالٍ واحد. والأرضيّةُ تمنع ذلك، فحجزٌ وُضع الآن يبقى.
     */
    public function test_أرضيّةُ_مهلةِ_الحجز_دقيقةٌ_واحدة(): void
    {
        $this->seedCore();
        $justNow = $this->reserved(0);

        $this->hubSetting('ai.reservation_ttl_min', '0');
        AiLedger::expireStale();

        $this->assertSame('reserved', (string) $justNow->fresh()->status,
            'صفرٌ أفرج عن حجزٍ وُضع لحظتَه — والسقفُ يبطل بإدخالٍ واحد');
    }

    /* ═══════════ ٣ · ask.cost_ceiling ═══════════ */

    /** **السقفُ يُقرأ كما ضُبط، والصفرُ يعني ألّا سقفَ لا سقفاً بصفر** */
    public function test_سقفُ_الكلفة_يُقرأ_ولا_يقبل_سالباً(): void
    {
        $this->seedCore();

        $this->hubSetting('ask.cost_ceiling', '0.25');
        $this->assertSame(0.25, AskPolicy::costCeiling(), 'السقفُ لم يُقرأ من الإعداد');

        $this->hubSetting('ask.cost_ceiling', '0');
        $this->assertSame(0.0, AskPolicy::costCeiling(), 'الصفرُ ليس «ألّا سقف»');

        $this->hubSetting('ask.cost_ceiling', '-5');
        $this->assertSame(0.0, AskPolicy::costCeiling(),
            'قيمةٌ سالبةٌ لم تُقصّ إلى صفر — وسقفٌ سالبٌ يمنع كلَّ طلب');
    }

    /* ═══════════ ٤ · ai.timeout_connect · ai.timeout_read ═══════════ */

    /** **مهلتا البوّابة تُقرآن من مفتاحيهما** */
    public function test_مهلتا_بوّابةِ_الذكاء_تُقرآن_من_الإعداد(): void
    {
        $this->seedCore();
        $this->hubSetting('ai.timeout_connect', '7');
        $this->hubSetting('ai.timeout_read', '45');

        $t = AiGateway::timeouts();

        $this->assertSame(7, (int) $t['connect'], 'مهلةُ الاتصال لم تُقرأ');
        $this->assertSame(45, (int) $t['read'], 'مهلةُ القراءة لم تُقرأ');
    }

    /** **ومحصورتان — فإعدادٌ شاذٌّ لا يُعلّق عاملاً ولا يقطع طلباً سليماً** */
    public function test_مهلتا_البوّابة_محصورتان(): void
    {
        $this->seedCore();

        $this->hubSetting('ai.timeout_connect', '0');
        $this->hubSetting('ai.timeout_read', '0');
        $low = AiGateway::timeouts();
        $this->assertSame([1, 1], [(int) $low['connect'], (int) $low['read']],
            'الأرضيّةُ (١ ثانية) لم تُفرَض على صفر');

        $this->hubSetting('ai.timeout_connect', '900');
        $this->hubSetting('ai.timeout_read', '9000');
        $high = AiGateway::timeouts();
        $this->assertSame([30, 300], [(int) $high['connect'], (int) $high['read']],
            'السقفان (٣٠ و٣٠٠ ثانية) لم يُفرضا');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** حجزٌ قائمٌ بدأ قبل كذا دقيقة */
    protected function reserved(int $minutesAgo): AiUsageEvent
    {
        return AiUsageEvent::create([
            'request_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'reserved', 'reserved_micro' => 1000,
            'started_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}
