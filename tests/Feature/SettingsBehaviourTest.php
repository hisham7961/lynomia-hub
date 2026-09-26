<?php

namespace Tests\Feature;

use App\Models\AccountActivation;
use App\Models\AlertRule;
use App\Models\IpRule;
use App\Models\OutboxMessage;
use App\Models\Quote;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Ops\AlertEngine;
use App\Support\Finance\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مفتاحٌ يُقلَب فيُرصَد أثرُه** — البند #35أ من سجلِّ الدَّين.
 *
 * ── **ما الذي يُغلقه هذا الملفّ** ──
 *
 * سطحُ الإعدادات مقيسٌ ومكتمل (١٢٧ مجموعةً · §٤ه)، **لكنّ اكتمالَ السطحِ
 * ليس برهانَ أثر**. أربعون مفتاحاً كانت تُكتَب وتُقرَأ ولا اختبارَ واحدٌ
 * يُثبت أنّ قلبَها يغيّر شيئاً — وذاك بالضبط شكلُ «الزرِّ الكاذب» الذي
 * تُبلغ عنه المحاكاةُ البشريّة: الإعدادُ يُحفَظ، والشاشةُ تؤكّد، ولا أثرَ.
 *
 * فهنا **خمسةٌ** منها، مختارةٌ بذاتِ الأثرِ الماليِّ والأمنيِّ لا بالأسهل:
 *
 * | المفتاح | الأثرُ المُثبَت هنا |
 * |---|---|
 * | `quotes.approve_discount` | عرضٌ خصمُه فوق العتبةِ يُحال «مراجعة داخلية» بدل أن يُرسَل |
 * | `security.autoblock_window_min` | عودُ المعتدي **بعد** النافذةِ يبدأ من أوّلِ السلّم لا من درجتِه |
 * | `portal.activation_ttl_min` | مهلةُ رمزِ التفعيلِ ونصُّ رسالتِه — بأرضيّةِ خمسِ دقائق |
 * | `fin.base_currency` | العملةُ التي **إليها** يُحوَّل المجموعُ المخلوط |
 * | `sla.rules` | مهلتا الاستجابةِ والحلِّ لكلِّ أولويّة — ومطابقةُ الاحتواء |
 *
 * ── **والقاعدةُ التي تحكم كلَّ تأكيدٍ هنا** ──
 *
 * **المفتاحُ يُقاس بأثرِه المُعلَن في `config/hub_settings.php`، لا باسمِه.**
 * فقد سقط بلاغان كاذبان في §٤ه لأنّ قارئَهما استنتج من الاسمِ أثراً لم
 * يدّعِه الكتالوج. فكلُّ تأكيدٍ أدناه مشتقٌّ من نصِّ `effect`/`risk` المكتوبِ
 * للمفتاح — بما في ذلك ما يبدو عيباً وهو **سلوكٌ موصوفٌ مُحذَّرٌ منه**
 * (مطابقةُ `sla.rules` بالاحتواء، وأوّلُ قاعدةٍ تفوز).
 */
class SettingsBehaviourTest extends TestCase
{
    /* ═══════════ ١ · quotes.approve_discount ═══════════ */

    /**
     * **عرضٌ بخصمِ ٢٠٪** — ثلاثُ عتباتٍ وثلاثةُ مصائر.
     *
     * `effect`: «عرضٌ خصمُه (خصم العرض ÷ الإجمالي قبله) يبلغ هذه النسبة
     * فأكثر يتطلب اعتماداً قبل الإرسال. القيمة 0 تعطّل العتبة.»
     */
    public function test_عتبة_الخصم_تحيل_العرض_للمراجعة_وصفرٌ_يعطّلها(): void
    {
        $this->seedCore();
        $sender = $this->plainSender();

        // ٢٥٠ من ١٢٥٠ = ٢٠٪ بالضبط — والحدُّ «يبلغ فأكثر» لا «يتجاوز»
        foreach ([['0', 'مُرسل'], ['25', 'مُرسل'], ['20', 'مراجعة داخلية']] as [$threshold, $expected]) {
            $this->hubSetting('quotes.approve_discount', $threshold);
            $q = Quote::create(['title' => 'عرض بخصم', 'total' => 1000, 'discount' => 250,
                'currency' => 'د.ك', 'status' => 'مسودة']);

            $this->actingAs($sender)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();

            $this->assertSame($expected, (string) $q->fresh()->status,
                'العتبةُ ' . $threshold . '٪ لم تُنتج المصيرَ المُعلَن لخصمِ ٢٠٪');
        }
    }

    /** **والعتبةُ لا تمسّ من يملك الاعتماد** — المالكُ يُرسل فوقها */
    public function test_عتبة_الخصم_لا_تحجز_من_يملك_الاعتماد(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_discount', '5');
        $q = Quote::create(['title' => 'عرض المالك', 'total' => 1000, 'discount' => 250,
            'currency' => 'د.ك', 'status' => 'مسودة']);

        $this->actingAs($this->owner)->post('/quote/' . $q->id . '/act', ['do' => 'send'])->assertRedirect();

        $this->assertSame('مُرسل', (string) $q->fresh()->status,
            'المالكُ (وهو المعتمِد) حُجز خلف عتبةٍ هو من يرفعها');
    }

    /* ═══════════ ٢ · security.autoblock_window_min ═══════════ */

    /**
     * **نافذةٌ واسعة ⇒ العودُ يُصعِّد** — الدرجةُ الثانية من السلّم.
     *
     * `effect`: «إن عاد العنوانُ للإساءة خلال هذه المدّة من انقضاء حظره
     * الآليّ السابق، صعِد إلى الدرجة التالية من السلّم.»
     */
    public function test_نافذةُ_العود_الواسعةُ_تُصعِّد_للدرجةِ_التالية(): void
    {
        $this->assertEscalationLevelAfterReturn('60', 1,
            'عودٌ بعد ١٥ دقيقةً من انقضاءِ حظرٍ ونافذةُ العودِ ٦٠ — ولم يُصعَّد');
    }

    /**
     * **ونافذةٌ ضيّقة ⇒ العودُ يبدأ من أوّلِ السلّم** — وهذا هو الأثرُ الذي
     * لم يكن مُثبَتاً: كلُّ اختباراتِ التصعيدِ القائمةِ تُشغَّل على النافذةِ
     * الافتراضيّةِ وحدَها، فلو صار المفتاحُ لا يُقرأ أصلاً لبقيت خضراء.
     */
    public function test_نافذةُ_العود_الضيّقةُ_تُعيد_المعتدي_لأوّلِ_السلّم(): void
    {
        $this->assertEscalationLevelAfterReturn('5', 0,
            'عودٌ بعد ١٥ دقيقةً ونافذةُ العودِ ٥ — والمفروضُ بدءٌ من أوّلِ السلّم');
    }

    /* ═══════════ ٣ · portal.activation_ttl_min ═══════════ */

    /** **المهلةُ تسري على الرمزِ وعلى نصِّ رسالتِه معاً** */
    public function test_مهلةُ_رابطِ_التفعيل_تضبط_الصلاحيةَ_والنصّ(): void
    {
        $this->seedCore();
        $this->hubSetting('portal.activation_ttl_min', '15');

        [$act] = AccountActivation::issue($this->employee);

        $this->assertEqualsWithDelta(15, now()->diffInMinutes($act->otp_expires_at, true), 1.0,
            'مهلةُ الرمزِ لم تُقرأ من الإعداد');
        $this->assertStringContainsString('صالحٌ 15 دقيقة', (string) $this->lastOutboxText(),
            'نصُّ الرسالةِ يَعِد بمهلةٍ غيرِ المهلةِ المضبوطة — والوعدُ الكاذبُ أسوأُ من الصمت');
    }

    /**
     * **وأرضيّةُ خمسِ دقائقَ مفروضةٌ في الشيفرة** — `effect`: «أرضيةٌ صلبةٌ
     * خمسُ دقائق يفرضها الكود». فقيمةُ ١ لا تُنتج مهلةَ دقيقةٍ واحدة.
     */
    public function test_أرضيّةُ_المهلةِ_خمسُ_دقائقَ_مهما_صغُر_الإعداد(): void
    {
        $this->seedCore();
        $this->hubSetting('portal.activation_ttl_min', '1');

        [$act] = AccountActivation::issue($this->employee);

        $this->assertEqualsWithDelta(5, now()->diffInMinutes($act->otp_expires_at, true), 1.0,
            'الأرضيّةُ الصلبةُ (٥) لم تُفرَض على قيمةٍ أصغر');
        $this->assertStringContainsString('صالحٌ 5 دقيقة', (string) $this->lastOutboxText(),
            'النصُّ أعلن المهلةَ المضبوطةَ لا المهلةَ المفروضة');
    }

    /**
     * **وتغييرُها لا يمدّ ما أُصدِر** — `effect`: «تُقرأ لحظةَ إصدار كلِّ
     * تفعيلٍ فتسري على ما يُصدَر بعدها لا على المُصدَر قبلها».
     */
    public function test_تغييرُ_المهلة_لا_يمسّ_رمزاً_أُصدِر_قبلها(): void
    {
        $this->seedCore();
        $this->hubSetting('portal.activation_ttl_min', '15');
        [$first] = AccountActivation::issue($this->employee);

        $this->hubSetting('portal.activation_ttl_min', '90');
        [$second] = AccountActivation::issue($this->employee);

        $this->assertEqualsWithDelta(15, now()->diffInMinutes($first->fresh()->otp_expires_at, true), 1.0,
            'الرمزُ المُصدَرُ سلفاً امتدّت مهلتُه برفعِ الإعداد — وذاك تمديدُ نافذةِ خطرٍ بأثرٍ رجعيّ');
        $this->assertEqualsWithDelta(90, now()->diffInMinutes($second->otp_expires_at, true), 1.0,
            'المُصدَرُ بعد التغييرِ لم يأخذ القيمةَ الجديدة');
    }

    /* ═══════════ ٤ · fin.base_currency ═══════════ */

    /** **فارغةً تُستعمل `app.currency`** — فلا يتغيّر شيءٌ لمن لم يضبط شيئاً */
    public function test_عملةُ_الأساسِ_الفارغةُ_ترجع_إلى_عملةِ_التطبيق(): void
    {
        $this->seedCore();
        $this->hubSetting('app.currency', 'د.ك');
        $this->hubSetting('fin.base_currency', '');

        $this->assertSame('د.ك', Currency::base(), 'الفارغُ لم يرجع إلى app.currency');
    }

    /**
     * **والمضبوطةُ تقلب وجهةَ التحويلِ ومقدارَه** — المجموعُ المخلوطُ نفسُه
     * والسعرُ المسجَّلُ نفسُه، ومع ذلك يختلف الرقمُ والعملةُ باختلافِ الأساس.
     *
     * وهذا أقوى ما يُثبِت أنّ المفتاحَ مقروء: **لا شيءَ تغيّر سواه**.
     */
    public function test_عملةُ_الأساسِ_تحدّد_إلى_أيِّ_عملةٍ_يُجمَع_المخلوط(): void
    {
        $this->seedCore();
        $this->hubSetting('app.currency', 'د.ك');
        // سعرٌ واحدٌ مسجَّل: الدولارُ بثلاثمئةِ فلس — والمقلوبُ يُشتقُّ منه
        DB::table('currency_rates')->insert([
            'id' => (string) Str::uuid(), 'from_cur' => 'USD', 'to_cur' => 'د.ك',
            'rate' => '0.3000000000', 'as_of' => '2026-01-01',
            'source' => 'اختبار', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Currency::flush();

        $rows = [
            ['amount' => 100, 'currency' => 'USD',  'date' => '2026-06-01'],
            ['amount' => 100, 'currency' => 'د.ك', 'date' => '2026-06-01'],
        ];

        $this->hubSetting('fin.base_currency', '');
        Currency::flush();
        $toDinar = Currency::sum($rows);

        $this->hubSetting('fin.base_currency', 'USD');
        Currency::flush();
        $toDollar = Currency::sum($rows);

        $this->assertTrue($toDinar['converted'], 'الزوجُ مكتملُ السعرِ ولم يُحوَّل');
        $this->assertSame('د.ك', $toDinar['cur']);
        $this->assertEqualsWithDelta(130.0, $toDinar['total'], 0.001,
            '١٠٠ دولارٍ بـ٠٫٣ + ١٠٠ دينار = ١٣٠ ديناراً');

        $this->assertTrue($toDollar['converted'], 'المقلوبُ يُشتقُّ من السعرِ المسجَّل — ولم يُحوَّل');
        $this->assertSame('USD', $toDollar['cur'],
            'المجموعُ أُعلن بعملةٍ غيرِ الأساسِ المضبوط');
        $this->assertEqualsWithDelta(433.333, $toDollar['total'], 0.01,
            '١٠٠ دولار + ١٠٠ دينارٍ بمقلوبِ ٠٫٣ ≈ ٤٣٣٫٣٣٣ دولاراً');
    }

    /* ═══════════ ٥ · sla.rules ═══════════ */

    /** **الصيغةُ تُقرأ كما تُعلَن** — اسمٌ:استجابةٌ:حلٌّ، والكسورُ مقبولة */
    public function test_قواعدُ_SLA_تُقرأ_من_الإعدادِ_بكسورِها(): void
    {
        $this->seedCore();
        $this->hubSetting('sla.rules', 'عاجلة:0.5:6 افتراضي:9:99');

        $rules = hub_sla_rules();

        $this->assertSame([0.5, 6.0], $rules['عاجلة'] ?? null, 'الكسرُ في مهلةِ الاستجابةِ ضاع');
        $this->assertSame([9.0, 99.0], $rules['افتراضي'] ?? null, 'القاعدةُ الافتراضيّةُ لم تُقرأ');
    }

    /** **والمهلتان تُحسبان من الإنشاءِ بالساعاتِ المضبوطة** */
    public function test_مهلتا_التذكرةِ_تتبعان_قاعدةَ_أولويّتِها(): void
    {
        $this->seedCore();
        $this->hubSetting('sla.rules', 'عاجلة:2:6 افتراضي:9:99');

        $t = Ticket::create(['subject' => 'عطلٌ عاجل', 'status' => 'جديدة', 'priority' => 'عاجلة']);
        $sla = hub_sla($t, null);

        $this->assertSame(120, (int) round($t->created_at->diffInMinutes($sla['respDue'], true)),
            'مهلةُ الاستجابةِ لم تُؤخذ من القاعدة (٢ ساعة)');
        $this->assertSame(360, (int) round($t->created_at->diffInMinutes($sla['resDue'], true)),
            'مهلةُ الحلِّ لم تُؤخذ من القاعدة (٦ ساعات)');
        $this->assertStringStartsWith('عاجلة (2س / 6س)', (string) $sla['policy']);
    }

    /** **وأولويّةٌ بلا قاعدةٍ تقع على «افتراضي»** */
    public function test_أولويّةٌ_بلا_قاعدةٍ_تقع_على_الافتراضيّة(): void
    {
        $this->seedCore();
        $this->hubSetting('sla.rules', 'عاجلة:2:6 افتراضي:9:99');

        $t = Ticket::create(['subject' => 'سؤالٌ عاديّ', 'status' => 'جديدة', 'priority' => 'منخفضة']);
        $sla = hub_sla($t, null);

        $this->assertStringStartsWith('افتراضي (9س / 99س)', (string) $sla['policy']);
        $this->assertSame(540, (int) round($t->created_at->diffInMinutes($sla['respDue'], true)));
    }

    /**
     * **ومطابقةُ الاحتواءِ وأوّلُ فائزٍ — سلوكٌ موصوفٌ لا عيبٌ مكتشَف.**
     *
     * `risk`: «المطابقة بالاحتواء لا بالمساواة وأولُ قاعدةٍ تُطابق تفوز،
     * فترتيب الكتابة يقرّر النتيجة … اكتب الأطول أولاً».
     *
     * فيُثبَت هنا **حرفيّاً**: الأولويّةُ نفسُها والترتيبُ وحدَه ينقلب،
     * فينقلب الفائز. ومن قرأ هذا التأكيدَ عرف لماذا تُكتب الأطولُ أوّلاً —
     * وهذا أنفعُ من تحذيرٍ في كتالوجٍ لا يُقرأ.
     */
    public function test_ترتيبُ_قواعدِ_SLA_يقرّر_الفائزَ_عند_الاحتواء(): void
    {
        $this->seedCore();

        $t = Ticket::create(['subject' => 'تذكرةُ الاحتواء', 'status' => 'جديدة', 'priority' => 'عالية جداً']);

        $this->hubSetting('sla.rules', 'عالية:4:24 جداً:1:2 افتراضي:8:72');
        $this->assertStringStartsWith('عالية (4س / 24س)', (string) hub_sla($t, null)['policy'],
            'الأولى في الكتابةِ لم تفز رغم احتوائها');

        $this->hubSetting('sla.rules', 'جداً:1:2 عالية:4:24 افتراضي:8:72');
        $this->assertStringStartsWith('جداً (1س / 2س)', (string) hub_sla($t->fresh(), null)['policy'],
            'قلبُ الترتيبِ لم يقلب الفائز — فالتحذيرُ في الكتالوجِ بلا أثر');
    }

    /** **وإعدادٌ لا يُفهَم منه شيءٌ يسقط على الافتراضيِّ الصلب** — لا على فراغ */
    public function test_قواعدُ_SLA_المشوّهةُ_تسقط_على_الافتراضيِّ_الصلب(): void
    {
        $this->seedCore();
        $this->hubSetting('sla.rules', 'كلامٌ بلا نقطتين، ولا أرقام');

        $rules = hub_sla_rules();

        $this->assertSame([8.0, 72.0], array_map('floatval', $rules['افتراضي'] ?? []),
            'إعدادٌ مشوّهٌ أفرغ قواعدَ SLA بدل أن يسقط على الافتراضيِّ الصلب');
        $this->assertCount(1, $rules, 'الأجزاءُ المشوّهةُ تسرّبت إلى القواعد');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** مُرسِلٌ يملك التعديلَ ولا يملك الاعتماد — نمطُ `ApproverScopeTest` حرفاً */
    protected function plainSender(): User
    {
        $role = Role::create(['name' => 'مبيعات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]]);

        return User::create(['name' => 'مندوب مبيعات', 'email' => 'sales@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** نصُّ آخرِ رسالةِ صادرٍ — بترتيبٍ زمنيٍّ صريحٍ لا بقرعةِ المحرّك */
    protected function lastOutboxText(): string
    {
        $rows = OutboxMessage::query()->where('kind', 'account_activation')
            ->orderBy('created_at')->pluck('text')->all();

        return (string) (end($rows) ?: '');
    }

    /**
     * **جولتا اعتداءٍ بينهما انقضاءُ حظرٍ** ثمّ درجةُ القاعدةِ الثانية.
     *
     * الزمنُ مضبوطٌ لا مُقارَب: الحظرُ الأوّلُ ١٥ دقيقة، والعودُ بعد ٣٠ —
     * أي **بعد انقضائِه بخمسَ عشرةَ دقيقة**. فنافذةُ ٦٠ تشمل هذا العودَ
     * ونافذةُ ٥ لا تشمله، والفرقُ بين المصيرَين هو المفتاحُ وحدَه.
     */
    protected function assertEscalationLevelAfterReturn(string $window, int $expected, string $message): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        $this->hubSetting('security.autoblock_window_min', $window);
        AlertRule::create(['name' => 'رفض متكرر', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '3', 'status' => 'مفعّلة', 'source' => 'security.denials', 'window_min' => 60]);

        $ip = '203.0.113.77';
        $this->denialsFrom($ip, 5);
        (new AlertEngine())->evaluate(['components' => []]);

        // انقضى الحظرُ الأوّل (١٥ دقيقة) والمعتدي عاد بعده بخمسَ عشرةَ دقيقة
        $this->travel(30)->minutes();
        $this->denialsFrom($ip, 5);
        (new AlertEngine())->evaluate(['components' => []]);

        // ترتيبٌ حتميٌّ بـ`id` — لا `first()` بلا ترتيبٍ ولا تساوي `created_at`
        $rules = IpRule::query()->where('ip', $ip)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $rules, 'جولتان = قاعدتان — فالتهيئةُ نفسُها لم تصحّ');
        $this->assertSame(0, (int) $rules[0]->escalation_level, 'أوّلُ حظرٍ يبدأ من أوّلِ السلّم');
        $this->assertSame($expected, (int) $rules[1]->escalation_level, $message);
    }

    /** صفوفُ منعٍ خامٌّ من عنوانٍ واحد — التغذيةُ التي يقرؤها `fireSource` */
    protected function denialsFrom(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'ip' => $ip,
                'method' => 'GET', 'path' => '/admin/x' . $i, 'created_at' => now()->subMinute()]);
        }
    }
}
