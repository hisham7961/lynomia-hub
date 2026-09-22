<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Contract;
use App\Models\Quote;
use App\Support\MailSettings;
use App\Support\Proposal;
use App\Support\Risk;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الدفعةُ الخامسة من #35أ** — ثمانيةُ مفاتيحَ كانت تُذكَر في **سبّاكةِ**
 * الإعدادات (حفظٌ وتصديرٌ واستعادة) ولا يذكرها اختبارُ **أثر**.
 *
 * وهذا أخبثُ صنفٍ في البند: المفتاحُ **يبدو مُغطّىً** لأنّ اسمَه يظهر في
 * حزمةٍ خضراء — بينما كلُّ ما أُثبِت أنّه **يُحفَظ ويُصدَّر**، لا أنّه يفعل
 * شيئاً. فالعدُّ باسمِ المفتاحِ في نصوصِ الاختبارات يُعطي تغطيةً وهمية.
 *
 * | المفتاح | الأثرُ المُثبَت |
 * |---|---|
 * | `quotes.doc_no_format` | قالبُ رقمِ العرض — **وقد كان يُعلّق الحفظَ بلا `{SEQ}`** |
 * | `contracts.doc_no_format` | قالبُ رقمِ العقد — والعلّةُ نفسُها |
 * | `app.color` | لونُ الهويّةِ في العرضِ المُصدَر |
 * | `mail.username` | مستخدمُ SMTP من الشاشة |
 * | `risk.band_medium` · `risk.band_high` · `risk.band_critical` | حدودُ نطاقاتِ الخطر |
 * | `sec.strict_minutes` | مهلةُ خمولِ الجلسةِ خارجَ الدوام |
 * | `security.sessions_keep_days` | عمرُ سجلِّ الجلساتِ المُبطَلة |
 */
class SettingsBehaviourRound5Test extends TestCase
{
    /* ═══════════ ١ · قوالبُ أرقامِ العروضِ والعقود ═══════════ */

    /** **قالبُ رقمِ العرض يُبنى منه الرقمُ حرفاً** */
    public function test_قالبُ_رقمِ_العرض_يُبنى_منه_الرقمُ(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.doc_no_format', 'PRO-{YEAR}-{SEQ}');

        $this->assertSame('PRO-' . now()->format('Y') . '-0001', Quote::nextDocNo(),
            'رقمُ العرض لم يُبنَ من القالبِ المضبوط');
    }

    /** **وقالبُ رقمِ العقد كذلك** */
    public function test_قالبُ_رقمِ_العقد_يُبنى_منه_الرقمُ(): void
    {
        $this->seedCore();
        $this->hubSetting('contracts.doc_no_format', 'AGR-{YEAR}-{SEQ}');

        $this->assertSame('AGR-' . now()->format('Y') . '-0001', Contract::nextDocNo(),
            'رقمُ العقد لم يُبنَ من القالبِ المضبوط');
    }

    /**
     * **وقالبٌ بلا `{SEQ}` لا يُعلّق أيّاً منهما** — وهما العضوان اللذان
     * فاتا إصلاحَ v2.592.0، فكُشفا بمسحٍ لاحقٍ لا بحدس.
     */
    public function test_قالبٌ_بلا_تسلسلٍ_لا_يُعلّق_العروضَ_ولا_العقود(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.doc_no_format', 'ثابت');
        $this->hubSetting('contracts.doc_no_format', 'ثابت');
        $c = Client::create(['name' => 'عميلُ الترقيم']);

        $q1 = Quote::create(['client_id' => $c->id, 'total' => 0]);
        $q2 = Quote::create(['client_id' => $c->id, 'total' => 0]);
        $this->assertNotSame((string) $q1->doc_no, (string) $q2->doc_no,
            'رقما العرضين تساويا — وهذا بعينُه ما يُعلّق الحلقة');
        $this->assertStringStartsWith('ثابت', (string) $q1->doc_no, 'صدرُ القالبِ المضبوطِ ضاع');

        $t1 = Contract::nextDocNo();
        Contract::create(['title' => 'عقدٌ أوّل', 'type' => 'عقد عميل', 'status' => 'ساري', 'doc_no' => $t1]);
        $this->assertNotSame($t1, Contract::nextDocNo(), 'رقما العقدين تساويا');
    }

    /* ═══════════ ٢ · app.color ═══════════ */

    /** **لونُ الهويّةِ يظهر في العرضِ المُصدَر** — لا في الشاشةِ وحدَها */
    public function test_لونُ_الهويّة_يظهر_في_العرضِ_المُصدَر(): void
    {
        $this->seedCore();
        $this->hubSetting('app.color', '#7B2D8E');
        $c = Client::create(['name' => 'عميلُ اللون']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 100, 'currency' => 'د.ك', 'title' => 'عرضٌ ملوّن']);

        $this->assertStringContainsString('#7B2D8E', Proposal::html($q->fresh()),
            'لونُ الهويّةِ لم يصل المستندَ المُصدَر');
    }

    /* ═══════════ ٣ · mail.username ═══════════ */

    /** **مستخدمُ SMTP يُقرأ من الشاشة** — مع مضيفِها لا بدونه */
    public function test_مستخدمُ_البريد_يُقرأ_من_الشاشة(): void
    {
        $this->seedCore();
        $this->hubSetting('mail.host', 'smtp.example.test');
        $this->hubSetting('mail.username', 'postbox@example.test');

        MailSettings::apply();

        $this->assertSame('postbox@example.test', config('mail.mailers.smtp.username'),
            'مستخدمُ SMTP لم يُقرأ من الشاشة');
    }

    /* ═══════════ ٤ · risk.band_medium · risk.band_high · risk.band_critical ═══════════ */

    /** **حدودُ النطاقاتِ تقرّر وسمَ الدرجة** */
    public function test_حدودُ_نطاقاتِ_الخطر_تقرّر_الوسم(): void
    {
        $this->seedCore();
        $this->hubSetting('risk.band_medium', '30');
        $this->hubSetting('risk.band_high', '60');
        $this->hubSetting('risk.band_critical', '80');

        $this->assertSame('منخفض', Risk::band(29));
        $this->assertSame('متوسط', Risk::band(30));
        $this->assertSame('عالٍ', Risk::band(60));
        $this->assertSame('حرج', Risk::band(80));

        // ورفعُ الحدودِ يُنزل الدرجةَ نفسَها نطاقاً
        $this->hubSetting('risk.band_high', '95');
        $this->hubSetting('risk.band_critical', '99');
        $this->assertSame('متوسط', Risk::band(60),
            'درجةُ ٦٠ بقيت «عالٍ» بعد رفعِ الحدِّ إلى ٩٥ — فالحدودُ لا تُقرأ');
    }

    /** **وأرضيّاتٌ صلبةٌ تمنع نطاقاتٍ منهارة** */
    public function test_أرضيّاتُ_نطاقاتِ_الخطر_مفروضة(): void
    {
        $this->seedCore();
        $this->hubSetting('risk.band_medium', '0');
        $this->hubSetting('risk.band_high', '0');
        $this->hubSetting('risk.band_critical', '0');

        $this->assertSame([1, 2, 3], array_values(Risk::bands()),
            'الأرضيّاتُ (١ · ٢ · ٣) لم تُفرَض — ونطاقاتٌ بصفرٍ تجعل كلَّ شيءٍ حرجاً');
    }

    /* ═══════════ ٥ · sec.strict_minutes ═══════════ */

    /**
     * **مهلةُ الخمولِ خارجَ الدوام تُقرأ من الإعداد** — والرسالةُ تقولها.
     *
     * فساعاتُ العملِ تُطفأ في الحزمةِ افتراضاً (`seedCore`)، وهذا الاختبارُ
     * يُشعلها صراحةً بنافذةِ تشدّدٍ تبدأ منتصفَ الليل — فيكون كلُّ وقتٍ
     * «خارجَ الدوام» مهما كانت ساعةُ التشغيل. **والمالكُ معفىً من الوسيط**،
     * فالموظّفةُ هي من يُقاس عليها.
     */
    public function test_مهلةُ_الخمول_خارجَ_الدوام_تُقرأ_من_الإعداد(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        // نافذةُ التشدّدِ تبدأ منتصفَ الليل ⇒ كلُّ وقتٍ «خارجَ الدوام» مهما كانت الساعة
        $this->hubSetting('sec.strict_from', '00:00');
        $this->hubSetting('sec.strict_files', '0');            // حارسُ الملفّاتِ ليس موضوعَنا
        $this->hubSetting('sec.strict_minutes', '7');

        // **والمالكُ معفىً من الوسيط** — فالموظّفةُ هي من يُقاس عليها
        $this->actingAs($this->employee)
            ->withSession(['wh.last' => now()->subMinutes(30)->timestamp])
            ->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $errors = session('errors');
        $this->assertStringContainsString('كل 7 دقائق', (string) $errors->first('email'),
            'الرسالةُ تعلن مهلةً غيرَ المضبوطة — ووعدٌ كاذبٌ لمن ضبط الإعداد');
    }

    /* ═══════════ ٦ · security.sessions_keep_days ═══════════ */

    /** **سجلُّ الجلساتِ المُبطَلة يُقلَّم بمدّةِ المفتاح** */
    public function test_عمرُ_سجلِّ_الجلسات_يتبع_الإعداد(): void
    {
        $this->seedCore();
        $old = $this->revokedSession(200);
        $mid = $this->revokedSession(100);

        $this->hubSetting('security.sessions_keep_days', '180');
        Artisan::call('hub:automation');

        $left = DB::table('sessions_log')->orderBy('id')->pluck('id')->all();
        $this->assertNotContains($old, $left, 'جلسةٌ عمرُها ٢٠٠ يوماً نجت من تقليمِ ١٨٠');
        $this->assertContains($mid, $left, 'جلسةٌ عمرُها ١٠٠ يومٍ قُلّمت بمدّةِ ١٨٠');

        $this->hubSetting('security.sessions_keep_days', '60');
        Artisan::call('hub:automation');

        $this->assertNotContains($mid, DB::table('sessions_log')->orderBy('id')->pluck('id')->all(),
            'خفضُ المدّةِ إلى ٦٠ لم يُقلّم جلسةَ المئة — فالمفتاحُ لا يُقرأ');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** جلسةٌ مُبطَلةٌ آخرُ ظهورٍ لها قبل كذا يوماً — تُعيد معرّفَها */
    protected function revokedSession(int $ageDays): string
    {
        $id = (string) Str::uuid();
        DB::table('sessions_log')->insert([
            'id' => $id, 'user_id' => $this->employee->id,
            'ip' => '198.51.100.9', 'revoked' => true, 'started_at' => now()->subDays($ageDays),
            'last_seen_at' => now()->subDays($ageDays),
        ]);

        return $id;
    }
}
