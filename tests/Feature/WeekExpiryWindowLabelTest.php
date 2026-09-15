<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **W-3 · لافتةٌ واحدةٌ فوق نافذتَين** — و**W-4** معها: عدّادٌ يعدّ المعروض.
 * (الطور ١٦٧ · اليوم ٥).
 *
 * على الشاشةِ الواحدةِ — `/morning` وشريطُها الجانبيُّ إلى جانبِها — كان
 * يُقرأ في آنٍ واحد:
 *
 *  · `🔔 ينتهي قريباً` **٥٠** في الشريط — و`hub_expiry_count()` خلفَها: متأخّرٌ
 *    أو ينتهي **خلال ٧ أيام**.
 *  · `⏳ ينتهي قريباً` **٥٥** في البطاقة — و`hub_expiry()` خلفَها: نافذةُ
 *    الرادارِ كلُّها (`hub_radar_window()` = ٦٠ يوماً افتراضاً).
 *
 * **ولا رقمَ منهما خاطئ.** كلٌّ صادقٌ في نافذتِه، واللافتةُ وحدَها هي التي
 * تكذب: كلمتان متطابقتان فوق سؤالَين مختلفَين. وهذا ما حذّر منه
 * `AlertController` بنفسِه يومَ أُصلح PROD-05: «**تناقضُ شاشتين أسوأُ من
 * صمتِهما: يتعلّم المستخدمُ ألّا يصدّقَ الشارة**» — فالقارئُ الذي يرى ٥٠ و٥٥
 * لا يستنتج نافذتَين، بل يستنتج أنّ أحدَ الرقمَين معطوب.
 *
 * **والعلاجُ تسميةٌ لا حساب** — على سابقةِ المستودعِ نفسِه (N-6): حين انقسمت
 * النافذةُ رقمَين وُحِّدت على أوسعِهما، وصرّحت صفحةُ الرادارِ بنافذتِها نصّاً
 * «بدل أن تقول «قريباً» مبهمةً خشيةَ أن تكذبَ نصفَ الصفحة». وفي `/morning`
 * نفسِها بطاقةٌ شقيقةٌ تفعلها أصلاً: «**مستحقات خلال أسبوع**». فالمفقودُ هنا
 * أن تقول بطاقةُ الرادارِ نافذتَها، وأن تُسمّي الرقمَ الأصغرَ وصاحبَه.
 *
 * **وW-4 صنفُ W-1 عائداً في موضعٍ ثانٍ:** إحصائيّةُ «يستحق أو ينتهي قريباً»
 * في `/w/{key}` تعدّ `$expiry->count()` — و`$expiry` **مقصوصةٌ بـ`take(6)`**.
 * فمساحةٌ فيها عشرون انتهاءً تقرأ «٦»، وفوقَها تعليقُ الصفِّ نفسِه يَعِد:
 * «مؤشرات حقيقية: مجاميع محسوبة فعلاً لا نسب مزعومة». والجارتان إلى جانبِها
 * مجموعان حقيقيّان (`count()` على الاستعلام) — فالثالثةُ وحدَها تقيس مساحةَ
 * العرض. والخطأُ في اتّجاهِ التهوينِ كما في W-1: كلّما ازداد الواقعُ ثبت الرقم.
 */
class WeekExpiryWindowLabelTest extends TestCase
{
    /**
     * رادارُ انتهاءاتٍ معلومُ الشكل: **اثنان** خلال ٧ أيام، و**ستّةٌ** أبعدُ
     * منها وداخلَ نافذةِ الرادار. فالنافذتان تُعطيان رقمَين مختلفَين قصداً —
     * ولولا اختلافُهما لمرّ الاختبارُ بلا معنى.
     */
    private function seedRadar(): void
    {
        foreach ([3, 5] as $d) {
            Employee::create(['name' => "قريبٌ {$d}", 'status' => 'نشط',
                'iqama_exp' => now()->addDays($d)->toDateString()]);
        }
        foreach ([20, 25, 30, 35, 40, 45] as $d) {
            Employee::create(['name' => "بعيدٌ {$d}", 'status' => 'نشط',
                'iqama_exp' => now()->addDays($d)->toDateString()]);
        }

        // الرادارُ مخبّأ — وزرعٌ بعد إحماءِ المخبأ لا يُرى
        Cache::flush();
    }

    /** عنوانُ بطاقةِ الرادارِ في `/morning` (أيقونتُها ⏳ وحدَها) */
    private function cardTitle(string $html): string
    {
        return preg_match('/<h3>⏳\s*([^<]*?)\s*<span class="bdg">/u', $html, $m) ? trim($m[1]) : '';
    }

    /** الرقمُ على شارةِ البطاقة */
    private function cardBadge(string $html): ?int
    {
        return preg_match('/<h3>⏳[^<]*<span class="bdg">\s*(\d+)\s*<\/span>/u', $html, $m) ? (int) $m[1] : null;
    }

    /** سطرُ «لماذا» تحتَ عنوانِ البطاقة */
    private function cardWhy(string $html): string
    {
        return preg_match('/<h3>⏳.*?<\/h3>\s*<div class="sub"[^>]*>(.*?)<\/div>/su', $html, $m) ? trim($m[1]) : '';
    }

    /** شارةُ الشريطِ الجانبيِّ على رابطِ الرادار */
    private function navBadge(string $html): ?int
    {
        return preg_match('/ينتهي قريباً<span class="nbdg"[^>]*>\s*(\d+)\s*<\/span>/u', $html, $m) ? (int) $m[1] : null;
    }

    /**
     * **البطاقةُ تقول نافذتَها.** لا «قريباً» مبهمةً فوق رقمِ ستّين يوماً —
     * بل النافذةُ المعلنةُ نفسُها التي تقرؤها `hub_expiry()`، فإن غيّرتها
     * المنشأةُ من الإعدادات تبعتها اللافتةُ ولم تتخلّف عنها.
     */
    public function test_the_morning_radar_card_names_the_window_its_number_covers(): void
    {
        $this->seedCore();
        $this->seedRadar();

        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();
        $title = $this->cardTitle($html);

        $this->assertNotSame('', $title, 'بطاقةُ الرادارِ غائبةٌ عن الشاشة — تهيئةٌ خاطئة');
        $this->assertStringContainsString('خلال ' . hub_radar_window() . ' يوماً', $title,
            'العنوانُ يقول «قريباً» ولا يقول نافذتَه — والشريطُ إلى جانبِه يقول «قريباً» عن نافذةٍ أخرى');
    }

    /**
     * **وتُسمّي الرقمَ الأصغرَ وصاحبَه.** فالقارئُ الذي يرى الرقمَين معاً
     * يجد التوفيقَ بينهما مكتوباً، لا يستنتجه ولا يظنّ أحدَهما معطوباً.
     */
    public function test_the_card_reconciles_itself_with_the_sidebar_badge_in_words(): void
    {
        $this->seedCore();
        $this->seedRadar();

        $html = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();

        $card = $this->cardBadge($html);
        $nav  = $this->navBadge($html);
        $this->assertNotNull($card, 'شارةُ البطاقةِ غائبة');
        $this->assertNotNull($nav, 'شارةُ الشريطِ غائبة');

        // لولا اختلافُهما لَما كان لهذا الاختبارِ معنى — التهيئةُ تضمنه
        $this->assertNotSame($nav, $card,
            'الرقمان متساويان في هذه التهيئة، فالاختبارُ لا يُثبت شيئاً — أصلِح الزرعَ لا المنتج');
        $this->assertSame(hub_expiry_count(), $nav, 'شارةُ الشريطِ ليست `hub_expiry_count()`');

        $why = $this->cardWhy($html);
        $this->assertStringContainsString('٧ أيام', $why,
            'سطرُ «لماذا» لا يذكر نافذةَ الشارةِ الأصغر');
        $this->assertStringContainsString((string) $nav, $why,
            'سطرُ «لماذا» لا يذكر رقمَ الشارةِ نفسَه — فالتوفيقُ بين الرقمَين ما زال على القارئ');
    }

    /**
     * **W-4:** إحصائيّةُ المساحةِ تعدّ الواقعَ لا الصفوفَ الستّةَ المعروضة —
     * والمسرودُ يبقى مقصوصاً كما هو (صدقٌ في العدّاد لا إغراقٌ للصفحة).
     */
    public function test_the_workspace_attention_stat_counts_every_row_not_only_the_six_shown(): void
    {
        $this->seedCore();
        $this->seedRadar();   // ثمانيةُ انتهاءاتٍ في وحدةِ `hr`

        $html = $this->actingAs($this->owner)->get('/w/hr')->assertOk()->getContent();

        $this->assertTrue((bool) preg_match('/<b[^>]*>(\d+)<\/b><span>يستحق أو ينتهي/u', $html, $m),
            'إحصائيّةُ الانتهاءاتِ غائبةٌ عن المساحة');
        $stat = (int) $m[1];

        $this->assertGreaterThanOrEqual(8, $stat,
            'العدّادُ يقيس مساحةَ العرضِ لا الواقع — ثمانيةُ انتهاءاتٍ تُقرأ «' . $stat . '»');
    }
}
