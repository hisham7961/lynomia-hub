<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractSigner;
use App\Models\OutboxMessage;
use App\Models\SignRequest;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** CLM م4 (v2.120): موقّعون متعددون بروابط شخصية وOTP بريدي وتسلسل وتذكيرات وإلغاء */
class EsignMultiSignerTest extends TestCase
{
    protected string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
    }

    protected function makeMulti(string $mode = 'متوازٍ', ?string $contractId = null): SignRequest
    {
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة متعددة', 'free_body' => 'نص الاتفاق الثنائي',
            'contract_id' => $contractId, 'mode' => $mode,
            'signers' => json_encode([
                ['name' => 'الموقّع الأول', 'email' => 'first@example.com', 'role' => 'موقّع'],
                ['name' => 'الموقّع الثاني', 'email' => 'second@example.com', 'role' => 'موقّع'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

        return SignRequest::where('title', 'وثيقة متعددة')->firstOrFail();
    }

    /** بوابة الموقّع المستقل بـ OTP مستهلك مرة — لا كلمة سر مشتركة */
    protected function unlockSigner(ContractSigner $s): void
    {
        $s->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->addMinutes(10)])->save();
        $this->post("/sign/{$s->token}/unlock", ['otp' => '123456'])->assertRedirect();
    }

    public function test_store_with_signers_creates_rows_tokens_and_queues_mail(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();

        $signers = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();
        $this->assertCount(2, $signers);
        $this->assertNotSame($signers[0]->token, $signers[1]->token);
        $this->assertNotSame($req->token, $signers[0]->token);
        $this->assertSame('متوازٍ', $req->mode);
        $this->assertNotNull($req->sent_at);

        // بريدا رابطين في صندوق الصادر + حدثا sent
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_link')->where('channel', 'mail')->count());
        $this->assertSame(2, ContractEvent::where('request_id', $req->id)->where('event', 'sent')->count());
    }

    /**
     * صفحةُ التحرير تسرد **كلَّ** موقّعٍ باسمه وحالته ورابطه الخاصّ — كانت تعرض
     * رابطاً واحداً (رمز الطلب) فيبدو للمنشئ «موقّعٌ واحدٌ ورابطٌ واحد».
     */
    public function test_edit_page_lists_every_signer_and_their_own_link(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();
        $signers = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        $html = $this->actingAs($this->owner)->get("/esign/{$req->id}/edit")->assertOk()->getContent();

        $this->assertStringContainsString('الموقّع الأول', $html, 'الموقّع الأول غائبٌ عن صفحة التحرير');
        $this->assertStringContainsString('الموقّع الثاني', $html, 'الموقّع الثاني غائبٌ عن صفحة التحرير');
        foreach ($signers as $s) {
            $this->assertStringContainsString(route('sign.show', $s->token), $html,
                'رابطُ الموقّع «' . $s->name . '» الخاصّ غائبٌ عن صفحة التحرير');
        }
    }

    /**
     * روابطُ الموقّعين متاحةٌ من **المركز** دائماً — لا عند الإنشاء فقط: صفُّ
     * الطلب في القائمة يفرد قائمةَ روابطَ لكل موقّعٍ برابطه الخاصّ.
     */
    public function test_center_list_exposes_every_signer_link_anytime(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();
        $signers = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        $html = $this->actingAs($this->owner)->get('/esign')->assertOk()->getContent();

        $this->assertStringContainsString('sglinks', $html, 'قائمةُ روابط الموقّعين غائبةٌ عن المركز');
        foreach ($signers as $s) {
            // الرمزُ لا الرابطُ الكامل: زرُّ النسخ يحمله عبر @js التي تُهرِّب الشرطات
            $this->assertStringContainsString($s->token, $html,
                'رابطُ الموقّع «' . $s->name . '» غير متاحٍ من المركز بعد الإنشاء');
        }
    }

    public function test_otp_gate_wrong_expired_and_correct(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();
        $s = ContractSigner::where('request_id', $req->id)->orderBy('order')->first();

        // طلب الرمز يخزنه مجزأً ويسجل otp_sent ويقف في الصادر
        $this->post("/sign/{$s->token}/otp")->assertRedirect();
        $this->assertNotNull($s->fresh()->otp_code);
        $this->assertTrue(ContractEvent::where('signer_id', $s->id)->where('event', 'otp_sent')->exists());
        $this->assertTrue(OutboxMessage::where('kind', 'sign_otp')->where('target', 'first@example.com')->exists());

        // خاطئ يُرد
        $this->post("/sign/{$s->token}/unlock", ['otp' => '000000'])->assertSessionHas('err');
        // منتهي يُرد
        $s->forceFill(['otp_code' => Hash::make('123456'), 'otp_expires_at' => now()->subMinute()])->save();
        $this->post("/sign/{$s->token}/unlock", ['otp' => '123456'])->assertSessionHas('err');
        // صحيح يفتح ويُستهلك ويسجل otp_ok
        $this->unlockSigner($s->fresh());
        $this->assertNull($s->fresh()->otp_code, 'الرمز يُستهلك مرة واحدة');
        $this->assertTrue(ContractEvent::where('signer_id', $s->id)->where('event', 'otp_ok')->exists());
        $this->get("/sign/{$s->token}")->assertOk()->assertSee('نص الاتفاق الثنائي');
    }

    public function test_parallel_completes_only_after_all_and_fires_contract_signed_once(): void
    {
        $this->seedCore();
        $captured = [];
        \App\Support\HubEvents::listen(function ($event) use (&$captured) { $captured[] = $event; });
        $c = Contract::create(['title' => 'عقد الثنائي', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $req = $this->makeMulti('متوازٍ', $c->id);
        [$s1, $s2] = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        $this->unlockSigner($s1);
        $this->get("/sign/{$s1->token}");
        $this->post("/sign/{$s1->token}", ['signer_name' => 'الموقّع الأول', 'signature' => $this->png])->assertOk();

        // الأول وقّع — الطلب معلق والعقد لم يسرِ بعد
        $this->assertSame('وُقّع', $s1->fresh()->status);
        $this->assertSame('بانتظار التوقيع', $req->fresh()->status);
        $this->assertSame('قيد التوقيع', $c->fresh()->status);
        $this->assertNotContains('contract.signed', $captured);

        $this->unlockSigner($s2);
        $this->get("/sign/{$s2->token}");
        $this->post("/sign/{$s2->token}", ['signer_name' => 'الموقّع الثاني', 'signature' => $this->png])->assertOk();

        // اكتمل الكل: الطلب وُقّع والعقد ساري والحدث انطلق مرة واحدة
        $this->assertSame('وُقّع', $req->fresh()->status);
        $this->assertSame('ساري', $c->fresh()->status);
        $this->assertSame(1, count(array_filter($captured, fn ($e) => $e === 'contract.signed')));
        $this->assertSame(2, ContractEvent::where('request_id', $req->id)->where('event', 'signed')->count());
    }

    public function test_sequential_blocks_second_until_first_signs_then_mails_him(): void
    {
        $this->seedCore();
        $req = $this->makeMulti('متسلسل');
        [$s1, $s2] = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        // المتسلسل: بريد الإرسال الأولي للأول فقط
        $this->assertSame(1, OutboxMessage::where('kind', 'sign_link')->count());

        // الثاني محجوب بصفحة انتظار مهذبة
        $this->unlockSigner($s2);
        $this->get("/sign/{$s2->token}")->assertOk()->assertSee('دورك')->assertDontSee('نص الاتفاق الثنائي');
        $this->post("/sign/{$s2->token}", ['signer_name' => 'متعجل', 'signature' => $this->png])->assertForbidden();

        // الأول يوقّع → بريد الثاني يخرج تلقائياً وبابه يفتح
        $this->unlockSigner($s1);
        $this->get("/sign/{$s1->token}");
        $this->post("/sign/{$s1->token}", ['signer_name' => 'الموقّع الأول', 'signature' => $this->png])->assertOk();
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_link')->count(), 'دور الثاني حان فوصله رابطه');
        $this->get("/sign/{$s2->token}")->assertOk()->assertSee('نص الاتفاق الثنائي');
        $this->post("/sign/{$s2->token}", ['signer_name' => 'الموقّع الثاني', 'signature' => $this->png])->assertOk();
        $this->assertSame('وُقّع', $req->fresh()->status);
    }

    public function test_decline_by_one_signer_stops_request_and_other_sees_closed(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد سيتعثر', 'type' => 'عقد عميل', 'status' => 'مسودة']);
        $req = $this->makeMulti('متوازٍ', $c->id);
        [$s1, $s2] = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        $this->unlockSigner($s1);
        $this->get("/sign/{$s1->token}");
        $this->post("/sign/{$s1->token}/decline", ['reason' => 'بند غير مقبول'])->assertOk();

        $this->assertSame('رُفض', $req->fresh()->status);
        $this->assertSame('رُفض', $s1->fresh()->status);
        $this->assertSame('مسودة', $c->fresh()->status, 'الرفض يعيد العقد مسودة');
        // الموقّع الآخر يرى الإغلاق لا نموذج توقيع ميتاً
        $this->unlockSigner($s2);
        $this->get("/sign/{$s2->token}")->assertOk()->assertSee('أُغلقت هذه الوثيقة');
    }

    public function test_cancel_kills_all_links_and_resend_reminds(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();
        [$s1] = ContractSigner::where('request_id', $req->id)->orderBy('order')->get();

        // تذكير يدوي
        $this->actingAs($this->owner)->post('/esign/' . $req->id . '/resend')->assertRedirect();
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_reminder')->count());
        $this->assertSame(2, ContractEvent::where('request_id', $req->id)->where('event', 'reminded')->count());

        // إلغاء: الحالة والروابط والحدث
        $this->actingAs($this->owner)->post('/esign/' . $req->id . '/cancel')->assertRedirect();
        $fresh = $req->fresh();
        $this->assertSame('أُلغي', $fresh->status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'voided')->exists());
        $this->get("/sign/{$s1->token}")->assertStatus(410);
    }

    public function test_automation_reminders_are_threshold_idempotent(): void
    {
        $this->seedCore();
        $req = $this->makeMulti();
        // مضى ٤ أيام على الإرسال — العتبة الأولى (3) قُطعت
        $req->forceFill(['sent_at' => now()->subDays(4)])->save();
        OutboxMessage::query()->delete();

        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_reminder')->count(), 'تذكير واحد لكل موقّع');
        // تشغيل ثانٍ في اليوم نفسه: لا تكرار
        $this->artisan('hub:automation')->assertSuccessful();
        $this->assertSame(2, OutboxMessage::where('kind', 'sign_reminder')->count());
    }

    public function test_legacy_password_path_untouched_and_pass_required_without_signers(): void
    {
        $this->seedCore();
        // بلا موقّعين وبلا كلمة سر → رسالة إرشاد
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'بلا شيء', 'free_body' => 'نص',
        ])->assertSessionHas('err');

        // المسار القديم كاملاً كما هو
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'قديم النمط', 'free_body' => 'نص قديم', 'pass' => 'Old12345',
        ])->assertSessionHas('sign_link');
        $req = SignRequest::where('title', 'قديم النمط')->firstOrFail();
        $this->assertSame('مفرد', $req->mode);
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Old12345'])->assertRedirect();
        $this->get("/sign/{$req->token}")->assertOk();
        $this->post("/sign/{$req->token}", ['signer_name' => 'قديم', 'signature' => $this->png])
            ->assertOk()->assertSee('تم التوقيع بنجاح');
        $this->assertSame('وُقّع', $req->fresh()->status);
    }
}
