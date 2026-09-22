<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\IpRule;
use App\Models\Role;
use App\Models\Station;
use App\Models\User;
use App\Support\AlertEngine;
use App\Support\Custody;
use App\Support\SecurityPosture;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الدفعةُ الثانية من #35أ** — ثمانيةُ مفاتيحَ أخرى تُقلَب فيُرصَد أثرُها.
 *
 * تكملةُ `SettingsBehaviourTest`، وبقاعدتِه نفسِها: **الأثرُ المُعلَن في
 * `config/hub_settings.php` هو المرجع، لا اسمُ المفتاح**.
 *
 * | المفتاح | الأثرُ المُثبَت |
 * |---|---|
 * | `security.autoblock_steps` | مُددُ سلّمِ الحظرِ الآليّ — والفاسدُ يسقط للسلّمِ الصلب |
 * | `security.idle_days_2` | عتبةُ فحصِ «حسابات نشطة بلا دخول» — **ووسمُ الشاشةِ يتبعها** |
 * | `security.idle_days_1/3` | الدرجتان الدنيا والعليا في `idleTiers` |
 * | `radar.lookback_days` | كم يبقى المنتهي على الرادارِ قبل أن يسقط صامتاً |
 * | `assets.code_format` · `stations.code_format` · `assets.permit_format` | قوالبُ الترقيم |
 * | `cost.work_days` | مقامُ أجرِ الساعة — ورفعُه يقلب خاسراً رابحاً |
 *
 * ── **وما كشفته هذه الجولة** ──
 *
 * **أ) وسمٌ يكذب على من غيّر الإعداد.** كان وسمُ فحصِ الخمول نصّاً ثابتاً
 * («بلا دخولٍ ٦٠ يوماً») بينما العدُّ يجري بالعتبةِ المضبوطة. فمن خفضها إلى
 * ثلاثين قرأ عدداً محسوباً بثلاثين تحت عنوانٍ يقول ستّين — **وهذا هو الزرُّ
 * الكاذبُ مقلوباً**: المفتاحُ يعمل والشاشةُ تنكره.
 *
 * **ب) صيغةُ ترقيمٍ بلا `{SEQ}` تُعلّق الحفظَ إلى الأبد.** الكتالوجُ يُحذّر
 * («يعلَق حفظُ الأصل الثاني حتى مهلة PHP») — وما لم يُقَل إنّ الحلقةَ
 * تستعلم القاعدةَ في كلِّ دورة، فهي مهلةُ PHP **واستعلامٌ لا ينقطع معها**.
 * وقد قِيس: خمسةُ آلافِ دورةٍ والمرشَّحُ ثابتٌ لم يتغيّر حرفاً.
 */
class SettingsBehaviourRound2Test extends TestCase
{
    /* ═══════════ ١ · security.autoblock_steps ═══════════ */

    /** **سلّمُ المدد يُقرأ من الإعداد** — أوّلُ درجةٍ بمدّةِ أوّلِ رقم */
    public function test_سلّمُ_الحظرِ_الآليِّ_يأخذ_مُددَه_من_الإعداد(): void
    {
        $this->seedCore();
        $this->armAutoBlock();
        $this->hubSetting('security.autoblock_steps', '7,90,1440');

        $ip = '203.0.113.81';
        $this->denialsFrom($ip, 5);
        (new AlertEngine())->evaluate(['components' => []]);

        $rule = IpRule::query()->where('ip', $ip)->orderBy('created_at')->orderBy('id')->first();
        $this->assertNotNull($rule, 'لم يُنشأ حظرٌ أصلاً — التهيئةُ لم تصحّ');
        $this->assertEqualsWithDelta(7, $rule->created_at->diffInMinutes($rule->expires_at, true), 1.0,
            'مدّةُ أوّلِ درجةٍ لم تُقرأ من `security.autoblock_steps`');
    }

    /**
     * **وقيمةٌ فاسدةٌ تسقط إلى السلّمِ الصلب** — `risk`: «قيمةٌ فاسدة تسقط
     * إلى السلّم الافتراضي (15,60,1440) — لا حظرَ أطولَ مما أعلنت».
     */
    public function test_سلّمٌ_فاسدٌ_يسقط_إلى_الافتراضيِّ_لا_إلى_العدم(): void
    {
        $this->seedCore();
        $this->armAutoBlock();
        $this->hubSetting('security.autoblock_steps', 'لا أرقامَ هنا');

        $ip = '203.0.113.82';
        $this->denialsFrom($ip, 5);
        (new AlertEngine())->evaluate(['components' => []]);

        $rule = IpRule::query()->where('ip', $ip)->orderBy('created_at')->orderBy('id')->first();
        $this->assertNotNull($rule, 'إعدادٌ فاسدٌ ألغى الحظرَ بدل أن يسقط للسلّمِ الصلب');
        $this->assertEqualsWithDelta(15, $rule->created_at->diffInMinutes($rule->expires_at, true), 1.0,
            'السقوطُ لم يكن إلى ١٥ دقيقةً — أوّلِ درجةٍ في السلّمِ الصلب');
    }

    /* ═══════════ ٢ · security.idle_days_1/2/3 ═══════════ */

    /** **الدرجاتُ الثلاثُ تُقرأ من مفاتيحها الثلاثة، وبأرضيّةِ يومٍ واحد** */
    public function test_عتباتُ_الخمولِ_الثلاثُ_من_مفاتيحها(): void
    {
        $this->seedCore();
        $this->hubSetting('security.idle_days_1', '10');
        $this->hubSetting('security.idle_days_2', '20');
        $this->hubSetting('security.idle_days_3', '30');

        $this->assertSame([10, 20, 30], SecurityPosture::idleTiers(),
            'الدرجاتُ الثلاثُ لم تُقرأ من مفاتيحها');

        $this->hubSetting('security.idle_days_1', '0');
        $this->assertSame(1, SecurityPosture::idleTiers()[0],
            'أرضيّةُ اليومِ الواحدِ لم تُفرَض على صفر');
    }

    /**
     * **والعتبةُ الوسطى تقرّر من يُعَدّ مهجوراً** — حسابٌ آخرُ دخولٍ له قبل
     * أربعين يوماً: خارجُ العدِّ عند ستّين، داخلُه عند ثلاثين.
     */
    public function test_العتبةُ_الوسطى_تقرّر_عدَّ_الحساباتِ_المهجورة(): void
    {
        $this->seedCore();
        DB::table('users')->where('id', $this->employee->id)
            ->update(['last_login_at' => now()->subDays(40)]);
        // البقيّةُ بلا دخولٍ إطلاقاً فهم في العدِّ دوماً — والفرقُ المقيسُ هو هذا الحساب
        DB::table('users')->whereIn('id', [$this->owner->id, $this->viewer->id])
            ->update(['last_login_at' => now()]);

        $this->hubSetting('security.idle_days_2', '60');
        $wide = $this->idleCheck();

        $this->hubSetting('security.idle_days_2', '30');
        $narrow = $this->idleCheck();

        $this->assertSame(0, (int) $wide['n'], 'عتبةُ ستّين عدّت حساباً دخل قبل أربعين يوماً');
        $this->assertSame(1, (int) $narrow['n'], 'عتبةُ ثلاثين لم تعدّ حساباً دخل قبل أربعين يوماً');
    }

    /**
     * **ووسمُ الفحصِ يقول العتبةَ المضبوطةَ لا رقماً منسوخاً.**
     *
     * كان الوسمُ نصّاً ثابتاً «بلا دخولٍ ٦٠ يوماً» والعدُّ بالعتبةِ المضبوطة،
     * فمن خفضها قرأ رقمَ ثلاثين تحت عنوانِ ستّين. **والعنوانُ الكاذبُ أخطرُ
     * من الرقمِ الخاطئ**: الرقمُ يُراجَع، والعنوانُ يُصدَّق.
     */
    public function test_وسمُ_فحصِ_الخمول_يتبع_العتبةَ_المضبوطة(): void
    {
        $this->seedCore();
        $this->hubSetting('security.idle_days_2', '45');

        $this->assertStringContainsString('45', (string) $this->idleCheck()['label'],
            'الوسمُ يعلن عتبةً غيرَ التي يعدّ بها — وعدٌ كاذبٌ لمن ضبط الإعداد');
    }

    /* ═══════════ ٣ · radar.lookback_days ═══════════ */

    /**
     * **المنتهي يبقى على الرادارِ بقدرِ المفتاح** — وخفضُه يُسقطه صامتاً.
     *
     * `risk`: «خفضُه يُسقط المتأخّرَ من الشاشةِ قبل معالجته — والسقوطُ صامتٌ
     * لا يترك أثراً، فيبدو الرادارُ نظيفاً لأنّ المشكلةَ خرجت منه لا لأنّها حُلّت».
     */
    public function test_مدى_بقاءِ_المنتهي_على_الرادار_يتبع_الإعداد(): void
    {
        $this->seedCore();
        $e = Employee::create(['name' => 'مَي العنزي', 'status' => 'نشط',
            'iqama_exp' => now()->subDays(40)->toDateString()]);
        $this->actingAs($this->owner);

        $this->hubSetting('radar.lookback_days', '60');
        $this->assertContains((string) $e->id, $this->radarIds(),
            'منتهٍ قبل أربعين يوماً سقط من رادارٍ مداه ستّون');

        $this->hubSetting('radar.lookback_days', '30');
        $this->assertNotContains((string) $e->id, $this->radarIds(),
            'منتهٍ قبل أربعين يوماً بقي على رادارٍ مداه ثلاثون');
    }

    /** **والمدى محصورٌ بين يومٍ وسنة، والصفرُ يعني الافتراض** */
    public function test_مدى_الرادار_محصورٌ_ولا_يقبل_صفراً(): void
    {
        $this->seedCore();

        $this->hubSetting('radar.lookback_days', '0');
        $this->assertSame(60, hub_radar_lookback(), 'الصفرُ لم يرجع إلى الافتراض');

        $this->hubSetting('radar.lookback_days', '4000');
        $this->assertSame(365, hub_radar_lookback(), 'لم يُحصَر السقفُ عند سنة');

        $this->hubSetting('radar.lookback_days', '-9');
        $this->assertSame(1, hub_radar_lookback(), 'لم تُفرَض أرضيّةُ اليومِ الواحد');
    }

    /* ═══════════ ٤ · قوالبُ الترقيم الثلاثة ═══════════ */

    /** **قالبُ كودِ العهدة يُبنى منه الكودُ حرفاً** */
    public function test_قالبُ_كودِ_العهدة_يُبنى_منه_الكودُ(): void
    {
        $this->seedCore();
        $this->hubSetting('assets.code_format', 'ORG-{YEAR}-{SEQ}');

        $code = Asset::nextCode('لابتوب');

        $this->assertSame('ORG-' . now()->format('Y') . '-0001', $code,
            'كودُ العهدة لم يُبنَ من القالبِ المضبوط');
    }

    /** **وقالبُ كودِ المحطة كذلك** */
    public function test_قالبُ_كودِ_المحطة_يُبنى_منه_الكودُ(): void
    {
        $this->seedCore();
        $this->hubSetting('stations.code_format', 'DESK-{YEAR}-{SEQ}');

        $this->assertSame('DESK-' . now()->format('Y') . '-0001', Station::nextCode(),
            'كودُ المحطة لم يُبنَ من القالبِ المضبوط');
    }

    /** **وقالبُ رقمِ التصريح كذلك** */
    public function test_قالبُ_رقمِ_التصريح_يُبنى_منه_الرقمُ(): void
    {
        $this->seedCore();
        $this->hubSetting('assets.permit_format', 'GATE-{YEAR}-{SEQ}');

        $this->assertSame('GATE-' . now()->format('Y') . '-0001', Custody::nextPermitNo(),
            'رقمُ التصريح لم يُبنَ من القالبِ المضبوط');
    }

    /**
     * **وقالبٌ بلا `{SEQ}` لا يُعلّق النظام** — الحارسُ الذي أضافته هذه الجولة.
     *
     * الحلقةُ كانت `do … while (exists($candidate))` والمرشَّحُ ثابتٌ إذ لا
     * تسلسلَ فيه يتزايد: **دورةٌ أبديّةٌ باستعلامِ قاعدةٍ في كلِّ لفّة**. فصار
     * التسلسلُ يُلحَق بالقالبِ الناقصِ لحظةَ القراءة — فيبقى الكودُ فريداً
     * والقالبُ المضبوطُ محترَماً في صدرِه.
     */
    public function test_قالبٌ_بلا_تسلسلٍ_لا_يُعلّق_الترقيم(): void
    {
        $this->seedCore();
        $this->hubSetting('assets.code_format', 'ثابت');
        $this->hubSetting('stations.code_format', 'ثابت');
        $this->hubSetting('assets.permit_format', 'ثابت');

        $first = Asset::nextCode('لابتوب');
        Asset::create(['name' => 'جهازٌ أوّل', 'code' => $first, 'status' => 'متاح']);
        $second = Asset::nextCode('لابتوب');

        $this->assertNotSame($first, $second,
            'الكودُ الثاني ساوى الأوّلَ — وهذا بعينُه ما يُعلّق الحلقة');
        $this->assertStringStartsWith('ثابت', $first, 'صدرُ القالبِ المضبوطِ ضاع');

        $s = Station::nextCode();
        Station::create(['name' => 'مقعدٌ أوّل', 'code' => $s]);
        $this->assertNotSame($s, Station::nextCode(), 'كودُ المحطةِ الثاني ساوى الأوّل');

        $this->assertNotSame('', Custody::nextPermitNo(), 'رقمُ التصريح خرج فارغاً');
    }

    /* ═══════════ ٥ · cost.work_days ═══════════ */

    /**
     * **مقامُ أجرِ الساعة** — `risk`: «رفعه إلى ثلاثين يخفض أجر الساعة نحو
     * ٢٧٪ فيظهر مشروعٌ خاسر رابحاً». فالنسبةُ تُقاس هنا لا تُدَّعى.
     */
    public function test_أيّامُ_العمل_تقسم_أجرَ_الساعة(): void
    {
        $this->seedCore();
        Employee::create(['name' => 'فهدٌ الراشد', 'status' => 'نشط',
            'user_id' => $this->employee->id, 'salary' => 1760, 'allow' => 0]);
        $this->hubSetting('cost.work_hours', '8');

        $this->hubSetting('cost.work_days', '22');
        Cache::forget('cost:rates');
        $at22 = hub_hourly_rates();

        $this->hubSetting('cost.work_days', '30');
        Cache::forget('cost:rates');
        $at30 = hub_hourly_rates();

        $this->assertSame(10.0, $at22['rates'][$this->employee->id] ?? null,
            '١٧٦٠ ÷ (٢٢ × ٨) = ١٠ — المقامُ لم يُقرأ من الإعداد');
        $this->assertSame(22, $at22['days'], 'الجدولُ لم يُعلن المقامَ الذي حسب به');

        $hourly30 = $at30['rates'][$this->employee->id] ?? null;
        $this->assertEqualsWithDelta(7.333, $hourly30, 0.001, '١٧٦٠ ÷ (٣٠ × ٨) = ٧٫٣٣٣');
        $this->assertEqualsWithDelta(26.67, (1 - $hourly30 / 10.0) * 100, 0.5,
            'الانخفاضُ المُعلَن في الكتالوج (نحو ٢٧٪) لا يطابق المقيس');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** الحظرُ الآليُّ مُشتعلٌ بعتبةٍ منخفضةٍ وقاعدةِ رفضٍ نافذية */
    protected function armAutoBlock(): void
    {
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        AlertRule::create(['name' => 'رفض متكرر', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '3', 'status' => 'مفعّلة', 'source' => 'security.denials', 'window_min' => 60]);
    }

    /** صفوفُ منعٍ خامٌّ من عنوانٍ واحد */
    protected function denialsFrom(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'ip' => $ip,
                'method' => 'GET', 'path' => '/admin/x' . $i, 'created_at' => now()->subMinute()]);
        }
    }

    /** صفُّ فحصِ «حسابات نشطة بلا دخول» من وضعيّةِ الأمان */
    protected function idleCheck(): array
    {
        foreach (SecurityPosture::checks() as $row) {
            if (($row['key'] ?? '') === 'idle') return $row;
        }

        $this->fail('فحصُ الخمولِ غائبٌ عن وضعيّةِ الأمان');
    }

    /** معرّفاتُ سجلّاتِ الرادارِ كما يراها المالك */
    protected function radarIds(): array
    {
        return collect(hub_expiry(true, $this->owner))->pluck('id')->map('strval')->all();
    }
}
