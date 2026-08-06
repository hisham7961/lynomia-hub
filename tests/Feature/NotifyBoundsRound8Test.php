<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\Contract;
use App\Models\FinDocument;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\SignRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٣) — **الإشعارُ لا يفيض عمودَه ولا يتجاوز صلاحيةَ مستلمه.**
 *
 *  ١) **إشعارُ رفض التوقيع يفيض عمودَه** (`EsignController:1326`): نصُّ الإشعار =
 *     عنوانٌ (٢٠٠) + رمز + سببٌ (٤٠٠) — يتجاوز `notifications_hub.text` (‏VARCHAR‏600)
 *     فيسقط على MySQL (‏22001). فموقّعٌ خارجيّ **غيرُ مصادَق** يرى ٥٠٠ على مسارٍ
 *     علنيّ، والرفضُ يقع ولا مالكَ يُبلَّغ. الآن يُقصّ النصُّ مركزيّاً في نموذج
 *     الإشعار — فكلُّ موضعِ إنشاءٍ محروس.
 *  ٢) **قواعدُ التنبيه تتجاوز صلاحيةَ المستلم** (`HubAutomation:396`): النطاقُ
 *     يُفرض لكل مستلم، لكن مستلماً في الشركة الصحيحة قد لا يملك رؤيةَ الوحدة
 *     أصلاً (راية `monitor` لا تمنح المصفوفة). فتصل مبالغُ ميزانياتٍ وأرقامُ
 *     فواتير من مُنع من الوحدة. الآن: الصلاحيةُ تُفحَص قبل النطاق.
 */
class NotifyBoundsRound8Test extends TestCase
{
    /* ── ١) الإشعار لا يفيض عموده ── */

    public function test_a_long_notification_text_is_fitted_not_overflowed(): void
    {
        $this->seedCore();

        // نصٌّ أطولُ من عرض العمود بكثير — قبل الإصلاح: 22001 على MySQL، وقصٌّ
        // صامتٌ على SQLite. بعده: يُقصّ إلى عرض العمود على المحرّكين.
        $n = HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'sign',
            'text' => str_repeat('نصٌّ طويلٌ جداً ', 80), 'read' => false, 'created_at' => now()]);

        $this->assertLessThanOrEqual(600, mb_strlen((string) $n->fresh()->text),
            'نصُّ الإشعار يتجاوز عرضَ عموده — يسقط على MySQL بـ22001 ويُقصّ صامتاً '
            . 'على SQLite. القصُّ مركزيٌّ في النموذج يحرس كلَّ موضعِ إنشاء');
    }

    public function test_declining_a_signature_with_a_long_reason_does_not_500(): void
    {
        $this->seedCore();

        // عنوانٌ طويل (يقارب سقف ٢٠٠) عبر مسار الإنشاء المصادَق بموقّعٍ واحد
        $longTitle = mb_substr('اتفاقيةُ خدماتٍ مطوّلةُ العنوان ' . str_repeat('بندٌ ', 40), 0, 190);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => $longTitle, 'free_body' => 'نص الاتفاق', 'mode' => 'متوازٍ', 'opt_decline' => 1,
            'signers' => json_encode([['name' => 'الموقّع', 'email' => 'signer@example.com', 'role' => 'موقّع']],
                JSON_UNESCAPED_UNICODE),
        ])->assertRedirect();
        $req = SignRequest::where('title', $longTitle)->firstOrFail();
        $signer = \App\Models\ContractSigner::where('request_id', $req->id)->orderBy('order')->firstOrFail();

        // بوابةُ الموقّع: OTP مستهلكٌ مرة ثم فتح الجلسة
        $signer->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10)])->save();
        $this->post("/sign/{$signer->token}/unlock", ['otp' => '123456'])->assertRedirect();
        $this->get("/sign/{$signer->token}");

        // رفضٌ بسببٍ طويل (سقف ٤٠٠) — قبل الإصلاح: 22001 → ٥٠٠ على مسارٍ علنيّ
        $reason = mb_substr(str_repeat('السببُ المفصّلُ للرفض ', 20), 0, 400);
        $this->post("/sign/{$signer->token}/decline", ['reason' => $reason])->assertOk();

        $this->assertSame('رُفض', $req->fresh()->status, 'الرفضُ لم يُسجَّل');
        $this->assertDatabaseHas('audits', ['action' => 'رفض توقيع']);
        $this->assertTrue(
            HubNotification::where('kind', 'sign')->where('user_id', $this->owner->id)->exists(),
            'لم يُبلَّغ المالكُ بالرفض — إشعارُه فاض عمودَه فسقط');
    }

    /* ── ٢) قاعدةُ التنبيه لا تتجاوز صلاحيةَ مستلمها ── */

    public function test_an_alert_rule_does_not_notify_a_recipient_who_cannot_view_the_module(): void
    {
        $this->seedCore();

        // مراقبٌ برايةِ monitor لكن بلا أيِّ رؤيةٍ في المصفوفة (fin:v=0)
        $modules = array_keys(config('hub.modules'));
        $blind = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0]])->all();
        $role = Role::create(['name' => 'مراقبٌ أعمى', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => $blind]);
        $watcher = User::create(['name' => 'مراقب', 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        // قاعدةُ تنبيهٍ على المالية موجَّهةٌ لكل المراقبين (to_id = null)
        $rule = AlertRule::create(['name' => 'فواتيرُ مرسلة', 'mod' => 'fin',
            'field' => 'state', 'op' => 'يساوي', 'val' => 'مرسلة',
            'every' => 7, 'status' => 'مفعّلة']);

        FinDocument::create(['doc_no' => 'INV-9', 'kind' => 'فاتورة مبيعات', 'state' => 'مرسلة',
            'total' => 12345, 'date' => now()->toDateString()]);

        $this->artisan('hub:automation')->run();

        // ضابطٌ: المالكُ (fin:v=1 + monitor) يُشعَر — فالقاعدةُ تُطلق فعلاً
        $this->assertTrue(
            HubNotification::where('kind', 'rule:' . $rule->id)->where('user_id', $this->owner->id)->exists(),
            'القاعدةُ لم تُطلق أصلاً — الاختبارُ سيمرّ فراغاً لا حراسة');

        // والمراقبُ الأعمى (fin:v=0) لا يُشعَر — الصلاحيةُ قبل النطاق
        $this->assertFalse(
            HubNotification::where('kind', 'rule:' . $rule->id)->where('user_id', $watcher->id)->exists(),
            'قاعدةُ التنبيه أشعرت مستلماً لا يملك رؤيةَ الوحدة — رقمُ فاتورةٍ يصل '
            . 'من مُنع من المالية. الصلاحيةُ تُفحَص قبل النطاق');
    }
}
