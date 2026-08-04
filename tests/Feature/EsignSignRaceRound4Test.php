<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\EsignController;
use App\Models\Attachment;
use App\Models\ContractSigner;
use App\Models\Policy;
use App\Models\PolicyAck;
use App\Models\SignRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * الموجة الرابعة — سباق توقيع esign:
 * sign() يفحص «بانتظار التوقيع؟» من نسخةٍ في الذاكرة ثم يكتب الأثر ويعدّ المعلّقين
 * وعند صفرٍ يُنفّذ كتلة الاكتمال (flipContract + إقرار السياسة + أرشفة النسخة + بريد
 * النسخ لكل موقّع) — بلا معاملةٍ ولا قفلٍ يسلسل الفحص مع الإغلاق. إرساليتان
 * متزامنتان لنفس التوقيع تجتازان الفحص معاً فتُنفَّذ كتلة الاكتمال مرّتين: سجلا
 * إقرار سياسة (PolicyAck) لنفس التوقيع، ومرفقا «نسخة موقعة»، وبريد نسخٍ مزدوج.
 *
 * تُحاكى المتزامنة حتميّاً بنسختين للطلب قُرئتا وهما «بانتظار التوقيع»: الحارس
 * السليم يعيد قراءة الحالة من صفٍّ مقفول داخل المعاملة فالثانية ترى «وُقّع» وتُرفض.
 */
class EsignSignRaceRound4Test extends TestCase
{
    protected string $png;

    protected function setUp(): void
    {
        parent::setUp();
        $this->png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
    }

    /** طلب توقيعٍ مربوطٍ بسياسة — عبر واجهة الإنشاء نفسها (موقّعٌ واحد يكتمل فوراً) */
    protected function linkedToPolicy(Policy $pol): SignRequest
    {
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'إقرار السياسة', 'free_body' => 'نص السياسة للتوقيع.',
            'pass' => 'p1234', 'link_module' => 'policies', 'link_id' => $pol->id,
        ]);

        return SignRequest::where('link_module', 'policies')->where('link_id', $pol->id)->firstOrFail();
    }

    protected function signRequest(): Request
    {
        return Request::create('/sign/x', 'POST', [], [], [], [
            'REMOTE_ADDR' => '9.9.9.9', 'HTTP_USER_AGENT' => 'TestUA', 'HTTP_ACCEPT_LANGUAGE' => 'ar',
        ]);
    }

    /** استدعاء الاكتمال المُغلِّف على نسخةٍ بعينها (محاكاة معالجٍ مستقل) */
    protected function invokeFinalize(SignRequest $req, array $d, Request $r): void
    {
        $ctrl = new EsignController();
        $ref = new \ReflectionMethod($ctrl, 'finalize');
        $ref->setAccessible(true);
        $ref->invoke($ctrl, $req, null, $d, $r);
    }

    public function test_two_stale_submissions_complete_only_once(): void
    {
        $this->seedCore();
        $pol = Policy::create(['title' => 'سياسة أمن المعلومات', 'ver' => '3.0', 'status' => 'سارية']);
        $req = $this->linkedToPolicy($pol);
        $this->assertSame('بانتظار التوقيع', $req->status);

        $d = ['signer_name' => 'غيث الموظف', 'signature' => $this->png,
              'signer_id_no' => null, 'selfie' => null];
        $r = $this->signRequest();

        // معالجان قرآ الطلبَ وهو «بانتظار التوقيع» قبل أن يُغلقه أيٌّ منهما
        $a = SignRequest::find($req->id);
        $b = SignRequest::find($req->id);

        $this->invokeFinalize($a, $d, $r);   // الأول يُغلق ويُنشئ إقراراً واحداً

        try {
            $this->invokeFinalize($b, $d, $r);   // النسخة القديمة يجب أن تُرفض لا تُكرّر الاكتمال
            $this->fail('إرساليةٌ متزامنة أعادت تنفيذ كتلة الاكتمال — إقرارُ سياسةٍ مكرّر');
        } catch (HttpException $ex) {
            $this->assertSame(410, $ex->getStatusCode());
        }

        $this->assertSame(1, PolicyAck::where('policy_id', $pol->id)->count(),
            'تلوّث سجل الامتثال: أُنشئ إقرارُ سياسةٍ لنفس التوقيع مرتين');
        $this->assertSame('وُقّع', SignRequest::find($req->id)->status);
    }

    /** ولا مرفقَ نسخةٍ موقّعةٍ مزدوج على العقد المربوط */
    public function test_two_stale_submissions_do_not_double_archive(): void
    {
        $this->seedCore();
        $pol = Policy::create(['title' => 'سياسة الخصوصية', 'ver' => '1.0', 'status' => 'سارية']);
        $req = $this->linkedToPolicy($pol);

        $d = ['signer_name' => 'سالم', 'signature' => $this->png, 'signer_id_no' => null, 'selfie' => null];
        $r = $this->signRequest();

        $a = SignRequest::find($req->id);
        $b = SignRequest::find($req->id);

        $this->invokeFinalize($a, $d, $r);
        $before = Attachment::where('module', 'contracts')->count();

        try {
            $this->invokeFinalize($b, $d, $r);
            $this->fail('إرساليةٌ متزامنة كرّرت الأرشفة');
        } catch (HttpException $ex) {
            $this->assertSame(410, $ex->getStatusCode());
        }

        $this->assertSame($before, Attachment::where('module', 'contracts')->count(),
            'مرفقُ نسخةٍ موقّعةٍ مزدوج من إرساليةٍ متزامنة');
    }
}
