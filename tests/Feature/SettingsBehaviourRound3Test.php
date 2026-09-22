<?php

namespace Tests\Feature;

use App\Models\IdentityLookup;
use App\Support\Discovery\Engine;
use App\Support\MailSettings;
use App\Support\Tracking;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الدفعةُ الثالثة من #35أ** — سبعةُ مفاتيحَ: الاحتفاظُ والبريدُ والكاش.
 *
 * تكملةُ `SettingsBehaviourTest` و`SettingsBehaviourRound2Test` بقاعدتهما
 * نفسِها: **الأثرُ المُعلَن في الكتالوج هو المرجع، لا اسمُ المفتاح** — وكلُّ
 * اختبارٍ هنا يموت إن كفّ مفتاحُه عن القراءة (بُرهِن بالطفرة).
 *
 * | المفتاح | الأثرُ المُثبَت |
 * |---|---|
 * | `field.points_keep_days` | عمرُ نقاطِ المسارِ الخام قبل تقليمها — سياسةُ خصوصيّةٍ لا تنظيف |
 * | `security.ip_keep_days` | عمرُ ذاكرةِ عناوينِ المستخدمين في `user_ips` |
 * | `retention.ai_usage_days` | عمرُ سجلِّ استهلاكِ الذكاء المفصَّل — **والمجمَّلُ لا يُقصّ** |
 * | `identity.cache_days` | عمرُ إجابةِ المزوّدين المخبوءة، ومقصُّها عند ستّةِ أضعافِها |
 * | `mail.from_address` · `mail.from_name` · `mail.port` | بريدُ الصادرِ من الشاشة لا من `.env` |
 *
 * ── **وما يحرسه هذا الملفّ تحديداً** ──
 *
 * مفاتيحُ الاحتفاظِ **تحذف بياناتٍ**. فخطؤها لا يُرى في شاشةٍ حمراءَ بل في
 * صفٍّ غاب — و«غياب صفٍّ» لا يُبلَّغ عنه أحد. وأرضيّاتُها الصلبةُ (سبعةُ
 * أيّامٍ للنقاط، وثلاثون للباقي) هي آخرُ ما يمنع إعداداً مكتوباً بخطأٍ من
 * محوِ تاريخِ تحقيقٍ أمنيٍّ قبل أن يُفتَح — فتُختبَر كما تُختبَر القيمةُ نفسُها.
 */
class SettingsBehaviourRound3Test extends TestCase
{
    /* ═══════════ ١ · field.points_keep_days ═══════════ */

    /** **النقاطُ الأقدمُ من المدّةِ تُقلَّم، وما دونَها يبقى** */
    public function test_عمرُ_نقاطِ_المسار_يتبع_الإعداد(): void
    {
        $this->seedCore();
        $old = $this->point(120);
        $mid = $this->point(60);
        $new = $this->point(3);

        $this->hubSetting('field.points_keep_days', '90');
        Tracking::prune();

        $ids = DB::table('track_points')->orderBy('captured_at')->pluck('id')->all();
        $this->assertNotContains($old, $ids, 'نقطةٌ عمرُها ١٢٠ يوماً نجت من تقليمِ ٩٠');
        $this->assertContains($mid, $ids, 'نقطةٌ عمرُها ٦٠ يوماً قُلّمت بمدّةِ ٩٠');
        $this->assertContains($new, $ids);

        $this->hubSetting('field.points_keep_days', '30');
        Tracking::prune();

        $this->assertNotContains($mid, DB::table('track_points')->orderBy('captured_at')->pluck('id')->all(),
            'خفضُ المدّةِ إلى ٣٠ لم يُقلّم نقطةَ الستّين — فالمفتاحُ لا يُقرأ');
    }

    /** **وأرضيّةُ سبعةِ أيّامٍ تمنع إعداداً صغيراً من محوِ اليومِ نفسِه** */
    public function test_أرضيّةُ_عمرِ_النقاط_سبعةُ_أيّام(): void
    {
        $this->seedCore();
        $threeDays = $this->point(3);

        $this->hubSetting('field.points_keep_days', '1');
        Tracking::prune();

        $this->assertContains($threeDays, DB::table('track_points')->orderBy('captured_at')->pluck('id')->all(),
            'الأرضيّةُ الصلبةُ (٧ أيّام) لم تُفرَض — ونقاطُ اليومِ الماضي مُحيت');
    }

    /* ═══════════ ٢ · security.ip_keep_days ═══════════ */

    /** **ذاكرةُ العناوينِ تُقلَّم بمدّةٍ مضبوطة — وهي بياناتُ تتبّعٍ لا سجلّ** */
    public function test_عمرُ_ذاكرةِ_العناوين_يتبع_الإعداد(): void
    {
        $this->seedCore();
        $old = $this->userIp('198.51.100.7', 400);
        $mid = $this->userIp('198.51.100.8', 200);

        $this->hubSetting('security.ip_keep_days', '365');
        Artisan::call('hub:automation');

        $ips = DB::table('user_ips')->orderBy('ip')->pluck('ip')->all();
        $this->assertNotContains($old, $ips, 'عنوانٌ عمرُه ٤٠٠ يوماً نجا من تقليمِ ٣٦٥');
        $this->assertContains($mid, $ips, 'عنوانٌ عمرُه ٢٠٠ يوماً قُلّم بمدّةِ ٣٦٥');

        $this->hubSetting('security.ip_keep_days', '100');
        Artisan::call('hub:automation');

        $this->assertNotContains($mid, DB::table('user_ips')->orderBy('ip')->pluck('ip')->all(),
            'خفضُ المدّةِ إلى ١٠٠ لم يُقلّم عنوانَ المئتين — فالمفتاحُ لا يُقرأ');
    }

    /* ═══════════ ٣ · retention.ai_usage_days ═══════════ */

    /** **المفصَّلُ يُقصّ بالمفتاح** — والتقليمُ على دفعاتٍ لا دفعةً واحدة */
    public function test_عمرُ_سجلِّ_استهلاكِ_الذكاء_يتبع_الإعداد(): void
    {
        $this->seedCore();
        $old = $this->usageEvent(500);
        $mid = $this->usageEvent(200);

        $this->hubSetting('retention.ai_usage_days', '400');
        Artisan::call('hub:automation');

        $ids = DB::table('ai_usage_events')->orderBy('created_at')->pluck('id')->all();
        $this->assertNotContains($old, $ids, 'حدثٌ عمرُه ٥٠٠ يوماً نجا من قصِّ ٤٠٠');
        $this->assertContains($mid, $ids, 'حدثٌ عمرُه ٢٠٠ يوماً قُصّ بمدّةِ ٤٠٠');

        $this->hubSetting('retention.ai_usage_days', '100');
        Artisan::call('hub:automation');

        $this->assertNotContains($mid, DB::table('ai_usage_events')->orderBy('created_at')->pluck('id')->all(),
            'خفضُ المدّةِ إلى ١٠٠ لم يقصّ حدثَ المئتين — فالمفتاحُ لا يُقرأ');
    }

    /* ═══════════ ٤ · identity.cache_days ═══════════ */

    /**
     * **الكاشُ يُعاد ما دام أحدثَ من المدّة** — وبعدها يُسأل المزوّدون.
     *
     * ولا مزوّدَ مُفعَّلٌ في الاختبار، فالإجابةُ البائتةُ تُستبدَل بـ`notfound`:
     * **وذاك هو الدليل** — لو بقي `found` لكان الكاشُ قد أُعيد رغم بياتِه.
     */
    public function test_عمرُ_كاشِ_الهويّة_يقرّر_متى_يُسأل_المزوّدون(): void
    {
        $this->seedCore();
        $gtin = '06281006001015';

        $this->lookupRow($gtin, 10);
        $this->hubSetting('identity.cache_days', '30');
        $fresh = Engine::lookup($gtin);

        $this->assertTrue($fresh['cached'] ?? false, 'إجابةٌ عمرُها ١٠ أيّامٍ لم تُعَد من الكاشِ ومدّتُه ٣٠');
        $this->assertSame('found', $fresh['status']);

        $this->hubSetting('identity.cache_days', '5');
        $stale = Engine::lookup($gtin);

        $this->assertFalse($stale['cached'] ?? false,
            'إجابةٌ عمرُها ١٠ أيّامٍ أُعيدت من كاشٍ مدّتُه ٥ — فالمفتاحُ لا يُقرأ');
    }

    /** **والمقصُّ عند ستّةِ أضعافِ المدّة** — لا عندها، كما تقول الشيفرة */
    public function test_مقصُّ_كاشِ_الهويّة_عند_ستّةِ_أضعافِ_المدّة(): void
    {
        $this->seedCore();
        $this->hubSetting('identity.cache_days', '30');

        $withinSix = $this->lookupRow('06281006001022', 150);   // ٥ أضعاف
        $beyondSix = $this->lookupRow('06281006001039', 200);   // أكثرُ من ٦ أضعاف

        Engine::prune();

        $left = IdentityLookup::query()->orderBy('norm')->pluck('norm')->all();
        $this->assertContains($withinSix, $left, 'صفٌّ دون ستّةِ أضعافِ المدّة قُصّ');
        $this->assertNotContains($beyondSix, $left, 'صفٌّ فوق ستّةِ أضعافِ المدّة نجا');
    }

    /* ═══════════ ٥ · mail.from_address · mail.from_name · mail.port ═══════════ */

    /** **حقولُ الشاشةِ تغلب ملفَّ الخادم** — والمنفذُ يسقط على ٥٨٧ فارغاً */
    public function test_بريدُ_الصادر_يُقرأ_من_الشاشة(): void
    {
        $this->seedCore();
        $this->hubSetting('mail.host', 'smtp.example.test');   // مفتاحُ التفعيل
        $this->hubSetting('mail.port', '2525');
        $this->hubSetting('mail.from_address', 'no-reply@example.test');
        $this->hubSetting('mail.from_name', 'لينوميا');

        MailSettings::apply();

        $this->assertSame(2525, config('mail.mailers.smtp.port'), 'المنفذُ لم يُقرأ من الشاشة');
        $this->assertSame('no-reply@example.test', config('mail.from.address'), 'عنوانُ المرسِل لم يُقرأ');
        $this->assertSame('لينوميا', config('mail.from.name'), 'اسمُ المرسِل لم يُقرأ');

        $this->hubSetting('mail.port', '');
        MailSettings::apply();
        $this->assertSame(587, config('mail.mailers.smtp.port'), 'المنفذُ الفارغُ لم يسقط على ٥٨٧');
    }

    /**
     * **وبلا `mail.host` لا يُطبَّق شيء** — «إضافةٌ لا كسر»: من ترك الحقولَ
     * فارغةً يبقى على `.env`. فعنوانُ مرسِلٍ وحدَه **لا يخطف** بريدَ الخادم.
     */
    public function test_حقولُ_البريد_بلا_مضيفٍ_لا_تخطف_إعدادَ_الخادم(): void
    {
        $this->seedCore();
        $before = config('mail.from.address');
        $this->hubSetting('mail.from_address', 'hijack@example.test');

        MailSettings::apply();

        $this->assertSame($before, config('mail.from.address'),
            'حقلٌ واحدٌ بلا مضيفٍ غلب إعدادَ الخادم — والمُعلَن أنّ المضيفَ مفتاحُ التفعيل');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** نقطةُ مسارٍ خامٌّ بعمرٍ بالأيّام — تُعيد معرّفَها */
    protected function point(int $ageDays): string
    {
        $id = (string) Str::uuid();
        DB::table('track_points')->insert([
            'id' => $id, 'session_id' => (string) Str::uuid(),
            'lat' => '29.3759000', 'lng' => '47.9774000',
            'captured_at' => now()->subDays($ageDays),
            'client_operation_id' => Str::random(20),
            'created_at' => now()->subDays($ageDays),
        ]);

        return $id;
    }

    /** عنوانٌ في ذاكرةِ المستخدم بعمرٍ بالأيّام — يُعيد العنوانَ نفسَه */
    protected function userIp(string $ip, int $ageDays): string
    {
        DB::table('user_ips')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'ip' => $ip, 'hits' => 1, 'last_seen_at' => now()->subDays($ageDays),
        ]);

        return $ip;
    }

    /** حدثُ استهلاكٍ بعمرٍ بالأيّام — يُعيد معرّفَه */
    protected function usageEvent(int $ageDays): string
    {
        $id = (string) Str::uuid();
        DB::table('ai_usage_events')->insert([
            'id' => $id, 'request_id' => (string) Str::uuid(),
            'status' => 'settled',
            'created_at' => now()->subDays($ageDays), 'updated_at' => now()->subDays($ageDays),
        ]);

        return $id;
    }

    /** إجابةُ مزوّدين مخبوءةٌ بعمرٍ بالأيّام — تُعيد الرقمَ المُطبَّع */
    protected function lookupRow(string $gtin, int $ageDays): string
    {
        $norm = \App\Support\Identity::norm('gtin', $gtin);
        IdentityLookup::create([
            'norm' => $norm, 'status' => 'found',
            'result' => ['name' => 'صنفٌ مخبوء'], 'providers' => ['اختبار'],
            'hits' => 0, 'checked_at' => now()->subDays($ageDays),
        ]);

        return $norm;
    }

    /* ═══════════ ٦ · esign.link_days_default · esign.remind_days ═══════════ */

    /**
     * **المفتاحُ يملأ خانةً مسبقاً — ولا يَعِد بأكثر.**
     *
     * بُلِّغ عنه بوصفه «زرّاً كاذباً يَعِد بانتهاءٍ ولا يُنفِذه الخادم»، **وفُنِّد
     * في §٤ه**: الكتالوجُ يقول حرفاً «يملأ مسبقاً خانةَ الصلاحيّة في المعالجِ
     * فقط؛ المرسِلُ يغيّرها لكلِّ طلب». فهذا الاختبارُ يثبّت الأثرَ المُعلَن
     * نفسَه — **والتفنيدُ الذي لا يُثبَّت باختبارٍ يُعاد البلاغُ بعده بشهر**.
     */
    public function test_مهلةُ_رابطِ_التوقيع_تملأ_الخانةَ_مسبقاً(): void
    {
        $this->seedCore();
        $this->hubSetting('esign.link_days_default', '123');

        $html = $this->actingAs($this->owner)->get('/esign')->assertOk()->getContent();

        $this->assertStringContainsString('value="123"', (string) $html,
            'المفتاحُ لم يملأ خانةَ الصلاحيّة — وهو كلُّ ما يَعِد به');
    }

    /**
     * **وعتباتُ التذكير تُقرأ من الإعداد** — تذكيرٌ واحدٌ لكلِّ عتبةٍ مقطوعة.
     */
    public function test_عتباتُ_تذكيرِ_التوقيع_تُقرأ_من_الإعداد(): void
    {
        $this->seedCore();
        $this->signerPending(5);

        // عتبةٌ واحدةٌ مقطوعةٌ من «3,7» بعد خمسةِ أيّام ⇒ تذكيرٌ واحد
        $this->hubSetting('esign.remind_days', '3,7');
        Artisan::call('hub:automation');
        $this->assertSame(1, $this->reminders(), 'لم يُرسَل تذكيرُ العتبةِ المقطوعة (٣ من ٣,٧)');

        // ولو رُفعت العتباتُ فوق العمر لما قُطعت واحدةٌ — ولا تذكيرَ ثانٍ
        $this->hubSetting('esign.remind_days', '30,60');
        Artisan::call('hub:automation');
        $this->assertSame(1, $this->reminders(), 'عتبةٌ لم تُقطَع أرسلت تذكيراً');

        // وخفضُها يقطع عتبتين ⇒ تذكيرٌ ثانٍ (لحاقُ العدد بعدد المقطوعات)
        $this->hubSetting('esign.remind_days', '1,4');
        Artisan::call('hub:automation');
        $this->assertSame(2, $this->reminders(),
            'عتبتان مقطوعتان ولم يلحق عددُ التذكيرات عددَهما — فالإعدادُ لا يُقرأ');
    }

    /** طلبُ توقيعٍ مُرسَلٌ منذ كذا يوماً وموقّعٌ لم يوقّع بعد */
    protected function signerPending(int $daysAgo): void
    {
        $req = \App\Models\SignRequest::create([
            'title' => 'اتفاقيةٌ تنتظر', 'body' => 'نصٌّ.',
            'pass' => bcrypt('p1234'), 'token' => Str::random(48),
            'status' => 'بانتظار التوقيع', 'verify_code' => 'LYN-RM-000001',
            'sent_at' => now()->subDays($daysAgo),
        ]);
        \App\Models\ContractSigner::create([
            'request_id' => $req->id, 'order' => 1, 'role' => 'موقّع',
            'name' => 'الموقّعُ المتلكّئ', 'email' => 'signer@test.local',
            'token' => Str::random(48), 'status' => 'بانتظار التوقيع',
        ]);
    }

    /** عددُ رسائلِ التذكير في الصادر */
    protected function reminders(): int
    {
        return (int) DB::table('outbox')->where('kind', 'sign_reminder')->count();
    }
}
