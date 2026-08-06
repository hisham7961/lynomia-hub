<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractSigner;
use App\Models\SignRequest;
use App\Support\Evidence;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الموجة السابعة — **رأسُ السلسلة المُجمَّد لا يُقارَن بشيء.**
 *
 * `Evidence` توثّق نفسَها بأن «إعادةَ الحساب في أي وقتٍ لاحق تثبت سلامةَ
 * السجل»، و`sign_requests.evidence_hash` يُجمَّد لحظةَ الاكتمال، والشهادةُ
 * تطبعه تحت عنوان «رأس سلسلة الأدلة المجمّد». ولا يوجد في المشروع **سطرٌ
 * واحد** يقارن المُجمَّد بالمحسوب:
 *
 *   · `certificate.blade.php:40` يطبع `$req->evidence_hash ?: $head` — أي
 *     **المُجمَّد يحجب المحسوب**: عبثٌ بحدثٍ ماضٍ يغيّر `$head` والشهادةُ تظلّ
 *     تطبع الرأسَ القديم وتبدو سليمة.
 *   · `/verify` يطبع `evidence_hash` وحدَه بلا إعادةِ حساب.
 *
 * فالقيمةُ المخزَّنة **عقيمةٌ لا تابعَ لها**: تكلفةُ عمودٍ ووعدٌ مطبوعٌ على
 * وثيقةٍ قانونية بلا فاحصٍ خلفه. وهذا أسوأُ من غياب الضمان: ضمانٌ معلَنٌ لا
 * يُنفَّذ يُسكِت السؤالَ الذي كان سيَكشف العبث.
 *
 * **وقيدٌ ثانٍ:** ترتيبُ الأحداث تغيّر في v2.334 (رتبةٌ دلاليّة عند تساوي
 * الثانية)، فرأسٌ جُمّد قبلها قد لا يُطابق الحسابَ الجديد — والفاحصُ الجديد
 * يجب أن يقبل الترتيبَ القديم لا أن يتّهم عقداً سليماً.
 */
class EvidenceHeadVerifiedRound7Test extends TestCase
{
    protected function signed(): SignRequest
    {
        $c = Contract::create(['title' => 'عقد الأدلة', 'type' => 'عقد عميل', 'status' => 'ساري',
            'date_start' => now()->toDateString()]);

        $req = SignRequest::create(['contract_id' => $c->id, 'title' => 'وثيقة',
            'body' => 'نصُّ الوثيقة', 'pass' => 'x', 'status' => 'وُقّع',
            'token' => 'rt' . random_int(100000, 999999),
            'verify_code' => 'VC' . str_pad((string) random_int(1, 99999), 6, '0', STR_PAD_LEFT),
            'doc_hash' => hash('sha256', 'doc'), 'signer_name' => 'فلان']);

        ContractSigner::create(['request_id' => $req->id, 'name' => 'فلان', 'role' => 'موقّع',
            'status' => 'وُقّع', 'order' => 1, 'token' => 'tok' . random_int(1000, 9999),
            'signed_at' => now()]);

        foreach (['created', 'sent', 'opened', 'signed'] as $ev) {
            ContractEvent::create(['request_id' => $req->id, 'event' => $ev, 'created_at' => now()]);
        }

        [, $head] = Evidence::chain($req);
        $req->forceFill(['evidence_hash' => $head])->saveQuietly();

        return $req->fresh();
    }

    public function test_a_tampered_event_is_reported_not_hidden(): void
    {
        $this->seedCore();
        $req = $this->signed();

        $this->assertTrue(Evidence::verify($req)['ok'], 'السلسلةُ سليمةٌ قبل العبث');

        // عبثٌ بحدثٍ ماضٍ — الحارسُ `updating` يمنع Eloquent، فالعبثُ الواقعيّ
        // يقع على القاعدة مباشرةً (وصولٌ إلى phpMyAdmin مثلاً) وهو ما بُنيت
        // السلسلةُ لكشفه
        DB::table('contract_events')->where('request_id', $req->id)
            ->where('event', 'signed')->update(['ip' => '9.9.9.9']);

        $v = Evidence::verify($req->fresh());
        $this->assertFalse($v['ok'],
            'عُبث بحدثٍ في سجل الأدلة والفاحصُ يقول «سليم» — الرأسُ المجمَّد '
            . 'مخزَّنٌ ولا يُقارَن بشيء، فالضمانُ المطبوع على الشهادة بلا فاحص');
        $this->assertSame($req->evidence_hash, $v['frozen']);
    }

    /** والشهادةُ تُظهر الحكمَ لا الرأسَ المجمَّد وحدَه */
    public function test_the_certificate_shows_the_verdict(): void
    {
        $this->seedCore();
        $req = $this->signed();

        DB::table('contract_events')->where('request_id', $req->id)
            ->where('event', 'opened')->update(['ip' => '1.2.3.4']);

        $res = $this->actingAs($this->owner)->get("/esign/{$req->id}/certificate");
        $res->assertOk();
        $res->assertSee('السلسلة مكسورة', false);
    }

    public function test_the_public_verify_page_recomputes_the_chain(): void
    {
        $this->seedCore();
        $req = $this->signed();

        $res = $this->get('/verify?code=' . $req->verify_code);
        $res->assertOk();
        $res->assertSee('السلسلة سليمة', false);

        DB::table('contract_events')->where('request_id', $req->id)
            ->where('event', 'sent')->update(['ip' => '5.5.5.5']);

        $res2 = $this->get('/verify?code=' . $req->verify_code);
        $res2->assertOk();
        $res2->assertSee('السلسلة مكسورة', false);
    }

    /** ورأسٌ جُمّد بالترتيب القديم يُقبل — لا يُتَّهم عقدٌ سليمٌ بتغيّرِ تقنيةٍ عندنا */
    public function test_a_head_frozen_under_the_pre_v2334_ordering_still_verifies(): void
    {
        $this->seedCore();
        $req = $this->signed();

        // إعادةُ حسابٍ بالترتيب القديم (وقتاً ثم معرّفاً) وتجميدُه — كما كان
        // يُجمَّد قبل إضافة الرتبة الدلالية
        $legacy = Evidence::chain($req, legacy: true)[1];
        $req->forceFill(['evidence_hash' => $legacy])->saveQuietly();

        $v = Evidence::verify($req->fresh());
        $this->assertTrue($v['ok'],
            'رأسٌ جُمّد قبل v2.334 يُعلَن مكسوراً لمجرّد أنّ ترتيبَنا تغيّر — '
            . 'اتّهامُ عقدٍ سليمٍ أسوأُ من صمتٍ');
    }
}
