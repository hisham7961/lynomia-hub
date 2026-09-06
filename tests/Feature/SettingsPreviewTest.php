<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **معاينةُ التغيير قبل الحفظ** (WP-9.3 · spec §7.7 · §18).
 *
 * شاشةُ الإعدادات كانت تحفظ ٩٥ مفتاحاً في نقرةٍ واحدة بلا أن تقول ماذا سيتبدّل:
 * لا «من ماذا إلى ماذا»، ولا وسمَ خطرٍ على ما يُبطل حارساً — فتعديلُ عتبة القفل
 * وتعديلُ اسم المنشأة يمرّان بالنقرة نفسِها. §18 يشترط للإعداد الخطر خطوةَ
 * تأكيدٍ تعرض الأثر، و§7.7 يشترط أن تكون **جافّة**: لا تكتب حرفاً.
 *
 * وهذا الحارس يثبّت أربعةً:
 *   ١) المعاينةُ **لا تكتب شيئاً**: لا صفَّ إعداد، ولا صفَّ تاريخ، ولا قيدَ
 *      تدقيق، ولا حتى إبطالَ خبيئة `settings:all` (وهو الأثرُ الوحيد الذي كان
 *      يمكن أن يتسلّل من كاتبٍ يُنادى «للقراءة»).
 *   ٢) تعرض `A → B` لكل مفتاحٍ **يتغيّر فعلاً** لا لكل مفتاحٍ أُرسل.
 *   ٣) تسم عاليَ الخطورة من `Settings::HIGH_RISK_RE` — نمطُ `SecurityEvents`
 *      نفسُه مضافاً إليه البريدُ وأودو وسقفُ الرفع ومقاما أجر الساعة.
 *   ٤) ولا قيمةَ سرٍّ في الصفحة: بصمةٌ أو «مضبوط»، لا ثالث.
 */
class SettingsPreviewTest extends TestCase
{
    /* ═══════════════ ١) جافّةٌ تماماً ═══════════════ */

    public function test_the_preview_writes_absolutely_nothing(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'الاسم القديم');
        $this->hubSetting('ops.slow_ms', '1000');

        setting('app.name');                              // إحماءُ خبيئة settings:all
        $this->assertTrue(Cache::has('settings:all'), 'الخبيئةُ لم تُحمَّ — الاختبارُ لا يقيس شيئاً');

        $this->actingAs($this->owner)->post(route('settings.preview'), [
            'app_name' => 'الاسم الجديد', 'ops_slow_ms' => '2500', 'auth_pw_min' => '14',
        ])->assertRedirect();

        $this->assertSame('الاسم القديم', (string) setting('app.name'), 'المعاينةُ كتبت في جدول الإعدادات');
        $this->assertSame('1000', (string) setting('ops.slow_ms'));
        $this->assertDatabaseMissing('setting_changes', ['key' => 'app.name']);
        $this->assertDatabaseMissing('setting_changes', ['key' => 'ops.slow_ms']);
        $this->assertSame(0, DB::table('setting_changes')->count(), 'المعاينةُ كتبت تاريخاً');
        $this->assertSame(0, DB::table('audits')->where('action', Settings::AUDIT_ACTION)->count(),
            'المعاينةُ كتبت قيدَ تدقيق');
        $this->assertTrue(Cache::has('settings:all'),
            'المعاينةُ أبطلت خبيئةَ الإعدادات — أثرٌ جانبيٌّ من خطوةٍ تدّعي أنها قراءة');
    }

    /* ═══════════════ ٢) A → B لما يتغيّر وحدَه ═══════════════ */

    public function test_the_preview_shows_a_to_b_for_each_changed_key_only(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'الاسم القديم');
        $this->hubSetting('ops.slow_ms', '1000');

        $this->actingAs($this->owner)->post(route('settings.preview'), [
            'app_name' => 'الاسم الجديد',
            'ops_slow_ms' => '1000',                      // القيمةُ نفسُها — ليست تغييراً
        ])->assertRedirect();

        $html = $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('الاسم القديم', $html, 'الطرفُ «قبل» غائب');
        $this->assertStringContainsString('الاسم الجديد', $html, 'الطرفُ «بعد» غائب');
        $this->assertStringContainsString('app.name', $html);

        // مفتاحٌ أُرسل بقيمته نفسِها لا يظهر في المعاينة سطراً
        $card = $this->previewCard($html);
        $this->assertStringNotContainsString('ops.slow_ms', $card,
            'مفتاحٌ لم يتغيّر عُرض تغييراً — المعاينةُ تعدّ الإرسالَ لا الفرق');
    }

    /** ولا معاينةَ لفراغ: طلبٌ بلا فرقٍ يقول ذلك ولا يفتح تأكيداً كاذباً */
    public function test_a_preview_with_no_difference_says_so(): void
    {
        $this->seedCore();
        $this->hubSetting('ops.slow_ms', '1000');

        $this->actingAs($this->owner)->post(route('settings.preview'), ['ops_slow_ms' => '1000'])
            ->assertRedirect();

        $html = $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('لا تغيير', $html, 'معاينةٌ بلا فرقٍ لم تقل إنها بلا فرق');
    }

    /* ═══════════════ ٣) وسمُ عالي الخطورة ═══════════════ */

    public function test_the_preview_marks_high_risk_keys_and_leaves_the_rest_plain(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.preview'), [
            'app_name'      => 'اسمٌ جديد',        // لا خطرَ فيه
            'auth_pw_min'   => '14',               // auth.  — من نمط SecurityEvents
            'mail_host'     => 'smtp.example.com', // mail.  — إضافةُ WP-9.3
            'mail_username' => 'bot@example.com',
            'mail_from_address' => 'no-reply@example.com',
            'files_max_kb'  => '4096',             // files.max_kb — إضافةُ WP-9.3
        ])->assertRedirect();

        $card = $this->previewCard($this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->getContent());

        $this->assertStringContainsString('عالي الخطورة', $card, 'لا وسمَ خطرٍ في المعاينة إطلاقاً');
        foreach (['auth.pw_min', 'mail.host', 'files.max_kb'] as $key) {
            $this->assertTrue(Settings::isHighRisk($key), "المفتاح $key ليس عاليَ الخطورة في التعريف الواحد");
        }
        $this->assertFalse(Settings::isHighRisk('app.name'), 'اسمُ المنشأة صار «عاليَ الخطورة»');

        // والتأكيدُ على سكّة data-confirm القائمة لا على نافذةٍ جديدة
        $this->assertStringContainsString('data-confirm', $card, 'لا تأكيدَ قبل تطبيق معاينةٍ خطرة');
    }

    /* ═══════════════ ٤) لا سرَّ في الصفحة ═══════════════ */

    public function test_the_preview_never_renders_a_secret_value(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post(route('settings.preview'), [
            'quoteflow_pass' => 'PLAIN-SECRET-9f2b',
        ])->assertRedirect();

        $html = $this->actingAs($this->owner)->get(route('settings.edit'))->assertOk()->getContent();
        $this->assertStringNotContainsString('PLAIN-SECRET-9f2b', $html, 'قيمةُ سرٍّ خرجت في المعاينة');
        $this->assertStringContainsString('quoteflow.pass', $html, 'السرُّ لم يُذكر تغيّره أصلاً');
    }

    /* ═══════════════ ٥) التطبيق مرّةً واحدة ═══════════════ */

    public function test_confirming_a_preview_applies_it_once_and_clears_the_session(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'الاسم القديم');

        $this->actingAs($this->owner)->post(route('settings.preview'), ['app_name' => 'الاسم الجديد'])
            ->assertRedirect();
        $this->assertSame('الاسم القديم', (string) setting('app.name'));

        $this->actingAs($this->owner)->post(route('settings.update'), ['_preview' => '1'])->assertRedirect();
        $this->assertSame('الاسم الجديد', (string) setting('app.name'), 'تأكيدُ المعاينة لم يطبّقها');
        $this->assertSame(1, DB::table('setting_changes')->where('key', 'app.name')->count());

        // وإعادةُ التأكيد لا تعيد التطبيق: الحمولةُ استُهلكت
        $this->hubSetting('app.name', 'أُعيد يدوياً');
        $this->actingAs($this->owner)->post(route('settings.update'), ['_preview' => '1'])->assertRedirect();
        $this->assertSame('أُعيد يدوياً', (string) setting('app.name'),
            'حمولةُ المعاينة طُبّقت مرّتين — لم تُمسح من الجلسة');
    }

    /**
     * **ولا يُقال «حُفظت» لما لم يُحفظ.**
     *
     * حمولةُ المعاينة تُستهلَك بـ`pull` (فلا تُطبَّق مرّتين)، فزرُّ التأكيد بعد
     * انتهاء الجلسة أو بعد تطبيقٍ في لسانٍ آخر يصل بلا حمولة. وردُّ «حُفظت
     * الإعدادات وطُبّقت فوراً» عن **صفرِ** كتابةٍ كذبةٌ تُخرج المشغّلَ مطمئنّاً
     * إلى ضبطٍ لم يقع — وهو أسوأُ ما يمكن أن تفعله شاشةٌ بُنيت كلُّها للصدق.
     */
    public function test_a_confirmed_preview_that_expired_says_so_instead_of_claiming_a_save(): void
    {
        $this->seedCore();
        $this->hubSetting('app.name', 'الاسم القديم');

        $this->actingAs($this->owner)->post(route('settings.preview'), ['app_name' => 'الاسم الجديد'])->assertRedirect();
        $this->actingAs($this->owner)->post(route('settings.update'), ['_preview' => '1'])->assertRedirect();
        $this->assertSame('الاسم الجديد', (string) setting('app.name'));

        // التأكيدُ الثاني: لا حمولةَ في الجلسة — ولا كتابةَ ولا ادّعاءَ حفظ
        $r = $this->actingAs($this->owner)->post(route('settings.update'), ['_preview' => '1'])->assertRedirect();
        $r->assertSessionMissing('ok');
        $this->assertNotNull($r->getSession()->get('warn') ?? $r->getSession()->get('err'),
            'صمتَ الردُّ عن ضياع المعاينة — أو ادّعى حفظاً لم يقع');
        $this->assertSame(1, DB::table('setting_changes')->where('key', 'app.name')->count(),
            'كتابةٌ ثانيةٌ من حمولةٍ مستهلَكة');
    }

    public function test_preview_is_owner_only(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post(route('settings.preview'), ['app_name' => 'اختراق'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('settings.preview'), ['app_name' => 'اختراق'])->assertForbidden();
    }

    /** بطاقةُ المعاينة وحدَها — كي لا يُخلَط محتواها بنموذج الإعدادات الكامل */
    protected function previewCard(string $html): string
    {
        $from = mb_strpos($html, 'id="setprev"');
        $this->assertNotFalse($from, 'لا بطاقةَ معاينةٍ في الصفحة إطلاقاً');
        $to = mb_strpos($html, '<form method="POST" action="' . route('settings.update') . '" enctype', $from);

        return mb_substr($html, $from, ($to === false ? mb_strlen($html) : $to) - $from);
    }
}
