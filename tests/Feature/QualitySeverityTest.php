<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\DataQuality;
use App\Support\Severity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **شدّةُ نتيجة الجودة** (spec §6.2 · §6.3) — WP-8.2.
 *
 * كان مركز الجودة يعرض ستّمئة فحصٍ **بمرتبةٍ واحدة**: مرجعٌ ماليّ مكسور
 * وسجلٌّ بلا شركة في السطر نفسِه، والترتيبُ بالعدد وحده — فأربعةُ آلافِ
 * «بلا شركة» تدفن مرجعاً واحداً مكسوراً إلى فاتورة. ومَن لا يعرف بماذا
 * يبدأ يبدأ بلا شيء.
 *
 * والشدّةُ هنا **مشتقّةٌ من صنف الفحص** بمفردات `Severity` الواحدة — لا جدولَ
 * شدّاتٍ ولا محرّكَ ثانٍ — وspec §6.3 صريحةٌ في أن **لا يُصنَّف كلُّ شيءٍ حرجاً**:
 * إنذارٌ يصرخ دائماً لا يُسمَع.
 */
class QualitySeverityTest extends TestCase
{
    /** كلُّ قاعدةٍ تحمل شدّةً، ومن السلّم الواحد لا من مفرداتٍ سادسة */
    public function test_every_rule_carries_a_severity_from_the_one_scale(): void
    {
        $this->seedCore();

        $seen = 0;
        foreach (array_keys(hub_modules()) as $m) {
            foreach (DataQuality::rules($m) as $k => $r) {
                $seen++;
                $this->assertArrayHasKey('sev', $r, "القاعدة «{$m}.{$k}» بلا شدّة — تُعرض بمرتبة كلِّ شيء");
                $this->assertContains($r['sev'], Severity::LEVELS,
                    "شدّةُ «{$m}.{$k}» = «{$r['sev']}» خارج سلّم Severity — مفرداتٌ سادسة");
            }
        }
        $this->assertGreaterThan(300, $seen, 'المسحُ لم يقرأ السجلَّ أصلاً');
    }

    /** الخريطةُ تتبع المعنى: المالُ والهُويّة حرج، والمطلوبُ مرتفع، والصيغةُ متوسط، والاختياريُّ منخفض */
    public function test_the_map_follows_meaning_and_is_not_all_critical(): void
    {
        $this->seedCore();

        $clients = DataQuality::rules('clients');

        // مرجعٌ مكسورٌ إلى المستخدمين = يتيمٌ في المساءلة ⇒ حرج
        $this->assertSame('critical', $clients['ref:ownerId']['sev'] ?? null,
            'مرجعُ مالكٍ مكسور ليس حرجاً — والمساءلةُ تسقط بلا صوت');
        // ومرجعٌ مكسورٌ إلى وحدةٍ غير ماليّةٍ ولا هُويّة ⇒ مرتفع لا حرج
        $this->assertSame('high', $clients['ref:competitorId']['sev'] ?? null);

        $this->assertSame('high', $clients['req:name']['sev'] ?? null, 'حقلٌ مطلوبٌ غائب ⇒ مرتفع');
        $this->assertSame('medium', $clients['mail:email']['sev'] ?? null, 'بريدٌ فاسد ⇒ متوسط');
        $this->assertSame('medium', $clients['status']['sev'] ?? null, 'حالةٌ خارج الخيارات ⇒ متوسط');
        $this->assertSame('low', $clients['co']['sev'] ?? null, 'بلا شركة ⇒ منخفض (spec §6.3)');
        $this->assertSame('low', $clients['stale']['sev'] ?? null, 'ركودٌ ⇒ منخفض');

        // ومرجعٌ ماليّ أو تعاقديّ: المستحقُّ الأول للحرج (spec §6.3 حرفياً)
        $this->assertSame('critical', DataQuality::rules('entries')['ref:finId']['sev'] ?? null,
            'قيدٌ يشير إلى مستندٍ ماليٍّ غير موجود ليس حرجاً');
        $this->assertSame('critical', DataQuality::rules('engagements')['ref:contractId']['sev'] ?? null,
            'ارتباطٌ يشير إلى عقدٍ غير موجود ليس حرجاً');

        // ولا يُصنَّف كلُّ شيءٍ حرجاً: أربعُ درجاتٍ مأهولة، والحرجُ أقليّة
        $dist = array_fill_keys(Severity::LEVELS, 0);
        foreach (array_keys(hub_modules()) as $m) {
            foreach (DataQuality::rules($m) as $r) $dist[$r['sev']]++;
        }
        $total = array_sum($dist);
        foreach (['critical', 'high', 'medium', 'low'] as $lvl) {
            $this->assertGreaterThan(0, $dist[$lvl], "لا قاعدةَ واحدة بشدّة «{$lvl}» — سلّمٌ بدرجةٍ واحدة");
        }
        $this->assertLessThan($total * 0.25, $dist['critical'],
            'الحرجُ ' . $dist['critical'] . " من {$total} قاعدة — إنذارٌ يصرخ دائماً لا يُسمَع (spec §6.3)");
    }

    /**
     * قاعدةٌ من صنفٍ لم يُصنَّف بعد تهبط إلى **الوسط**: «حرجة» تُطلق إنذاراً
     * كاذباً يُدرَّب الناسُ على تجاهله، و«معلوماتية» تدفنها تحت السطر.
     */
    public function test_an_unclassified_rule_falls_to_medium_not_to_either_end(): void
    {
        $this->assertSame('medium', DataQuality::severity(['kind' => 'صنفٌ جديدٌ لم يُصنَّف']));
        $this->assertSame('medium', DataQuality::severity([]));
    }

    /** الشدّةُ تصل إلى المسح والشاشة، والترتيبُ بها قبل العدد */
    public function test_the_scan_orders_by_severity_before_count(): void
    {
        $this->seedCore();

        // مرجعُ مالكٍ مكسور: سجلٌّ واحد ⇒ حرج
        $orphan = Client::create(['name' => 'عميلٌ بمالكٍ محذوف']);
        DB::table('clients')->where('id', $orphan->id)->update(['owner_id' => (string) Str::uuid()]);
        // وأربعةٌ بلا شركة ⇒ منخفض، وعددُها أكبر
        foreach (range(1, 4) as $i) Client::create(['name' => "عميلٌ يتيم {$i}"]);

        $scan = DataQuality::scan(true);
        $keys = array_map(fn ($c) => $c['module'] . '.' . $c['key'], $scan['checks']);
        $crit = array_search('clients.ref:ownerId', $keys, true);
        $low  = array_search('clients.co', $keys, true);

        $this->assertNotFalse($crit, 'المرجعُ المكسور لا يُرصد');
        $this->assertNotFalse($low);
        $this->assertSame('critical', $scan['checks'][$crit]['sev']);
        $this->assertSame(1, $scan['checks'][$crit]['count']);
        $this->assertSame(5, $scan['checks'][$low]['count']);
        $this->assertLessThan($low, $crit,
            'خمسةُ سجلاتٍ «بلا شركة» تسبق مرجعاً ماليّاً مكسوراً — الترتيبُ بالعدد يدفن الأهمّ');

        // وعدّادُ الشدّة في المجاميع = مجموعُ الفحوص بتلك الشدّة، لا رقماً ثانياً
        foreach (Severity::LEVELS as $lvl) {
            $sum = array_sum(array_map(fn ($c) => $c['sev'] === $lvl ? $c['count'] : 0, $scan['checks']));
            $this->assertSame($sum, $scan['totals']['sev'][$lvl] ?? null,
                "عدّادُ «{$lvl}» في المجاميع لا يطابق مجموعَ فحوصه");
        }

        // (WP-8.1) جدولُ النواقص بشدّاته في تبويب «البيانات» — للمالك وحدَه
        $html = $this->actingAs($this->owner)->get('/admin/quality?tab=data')->assertOk()->getContent();
        $this->assertStringContainsString('الشدّة', $html, 'الشاشةُ ما زالت بمرتبةٍ واحدة');
        $this->assertStringContainsString(Severity::LABELS['critical'], $html);
    }
}
