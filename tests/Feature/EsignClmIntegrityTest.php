<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Support\Evidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * سلامة أدلة التوقيع والتزامات العقود — الموجة الثانية:
 * (١) تحرير نص طلبٍ متعدد الموقّعين بعد توقيع طرفٍ أول كان مسموحاً — فيصير
 *     توقيعه الحيّ منسوباً لنصٍّ لم يره قط (الحالة تبقى «بانتظار التوقيع»)؛
 * (٢) تحرير نصٍّ «بانتظار الموافقة» بعد اعتماد مرحلةٍ كان يُبقي الاعتماد
 *     ملصقاً بمحتوى لم يره صاحبه؛
 * (٣) سلسلة الأدلة لم تكن تغطي هوية الموقّع ولا صورة توقيعه — أهم ما يُزوَّر
 *     خارج التجزئة كلياً؛
 * (٤) الرفض والإلغاء يتركان المراحل اللاحقة «بانتظار» للأبد — أشباحاً في
 *     بطاقة المركز القانوني؛
 * (٥) حذف العقد يترك التزاماته «قائمة» تُنذر للأبد.
 */
class EsignClmIntegrityTest extends TestCase
{
    /** طلب توقيع + موقّعان (وقّع الأول) — زرعاً مباشراً كما اختبارات esign الأخرى */
    protected function seedSignedRequest(): string
    {
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'اتفاقية الطرفين',
            'body' => 'النص الأصلي المتفق عليه', 'doc_hash' => hash('sha256', 'النص الأصلي المتفق عليه'),
            'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-' . strtoupper(Str::random(4)) . '-TEST',
            'status' => 'بانتظار التوقيع', 'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_signers')->insert([
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'order' => 1, 'name' => 'الموقّع الأول',
             'token' => Str::random(48), 'status' => 'وُقّع', 'id_no' => '299001234567',
             'signature' => 'data:image/png;base64,SIGNATURE1', 'signed_at' => now(),
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'order' => 2, 'name' => 'الموقّع الثاني',
             'token' => Str::random(48), 'status' => 'معلّق', 'id_no' => null,
             'signature' => null, 'signed_at' => null,
             'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('contract_events')->insert(['id' => (string) Str::uuid(), 'request_id' => $rid,
            'event' => 'signed', 'signer_id' => DB::table('contract_signers')
                ->where('request_id', $rid)->where('order', 1)->value('id'),
            'ip' => '10.0.0.1', 'created_at' => now()]);

        return $rid;
    }

    /* ── ١) لا تحرير بعد توقيعٍ حيّ ── */

    public function test_body_edit_after_a_party_signed_is_blocked(): void
    {
        $this->seedCore();
        $rid = $this->seedSignedRequest();

        $this->actingAs($this->owner)->put('/esign/' . $rid, [
            'title' => 'اتفاقية الطرفين', 'body' => 'نصٌّ بُدّل بعد توقيع الأول',
        ])->assertStatus(410);

        $this->assertSame('النص الأصلي المتفق عليه',
            DB::table('sign_requests')->where('id', $rid)->value('body'),
            'حُرّر النص بعد توقيع طرفٍ أول — توقيعه الحيّ صار منسوباً لنصٍّ لم يره');
    }

    /* ── ٢) تحرير النص يُبطل الاعتمادات السابقة ── */

    public function test_body_edit_during_approval_resets_approved_stages(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'طلب محجوز',
            'body' => 'النص A', 'doc_hash' => hash('sha256', 'النص A'),
            'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-APRV-TEST', 'status' => 'بانتظار الموافقة',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_approval_steps')->insert([
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'stage' => 1,
             'status' => 'معتمد', 'decided_by' => $this->owner->id, 'decided_at' => now(),
             'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'stage' => 2,
             'status' => 'بانتظار', 'decided_by' => null, 'decided_at' => null,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->owner)->put('/esign/' . $rid, [
            'title' => 'طلب محجوز', 'body' => 'النص B — بنودٌ وقيمٌ أخرى',
        ]);

        $this->assertSame(0, DB::table('contract_approval_steps')->where('request_id', $rid)
            ->where('status', 'معتمد')->count(),
            'اعتماد المرحلة الأولى بقي ملصقاً بنصٍّ لم يره صاحبه — تجاوزٌ فعلي لسلسلة الموافقات');
    }

    /* ── ٣) السلسلة تغطي هوية الموقّع وتوقيعه ── */

    public function test_evidence_chain_covers_signer_identity(): void
    {
        $this->seedCore();
        $rid = $this->seedSignedRequest();
        $req = \App\Models\SignRequest::findOrFail($rid);

        [, $before] = Evidence::chain($req);

        // عبثٌ بهوية الموقّع وصورة توقيعه — أخطر ما يُزوَّر في وثيقة موقعة
        DB::table('contract_signers')->where('request_id', $rid)->where('order', 1)
            ->update(['name' => 'شخص آخر تماماً', 'signature' => 'data:image/png;base64,FORGED']);

        [, $after] = Evidence::chain($req->fresh());
        $this->assertNotSame($before, $after,
            'بُدّل اسم الموقّع وصورة توقيعه والسلسلة «سليمة» — الشهادة تُعرض من أعمدة غير مُجزّأة');
    }

    /* ── ٤) الرفض/الإلغاء يُنهيان المراحل المتبقية ── */

    public function test_cancel_finalizes_remaining_pending_steps(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'طلبٌ سيُلغى',
            'body' => 'نص', 'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-CNCL-TEST', 'status' => 'بانتظار الموافقة',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_approval_steps')->insert([
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'stage' => 1,
             'status' => 'بانتظار', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'request_id' => $rid, 'stage' => 2,
             'status' => 'بانتظار', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->owner)->post('/esign/' . $rid . '/cancel');

        $this->assertSame(0, DB::table('contract_approval_steps')->where('request_id', $rid)
            ->where('status', 'بانتظار')->count(),
            'أُلغي الطلب ومراحله باقية «بانتظار» للأبد — أشباحٌ بزر قرارٍ في المركز القانوني');
    }

    /* ── ٥) حذف العقد يُغلق التزاماته ── */

    public function test_deleting_a_contract_closes_its_obligations(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقدٌ سيُحذف', 'type' => 'خدمات', 'status' => 'ساري']);
        DB::table('contract_obligations')->insert(['id' => (string) Str::uuid(),
            'contract_id' => $c->id, 'title' => 'تسليم شهري', 'status' => 'قائم',
            'due' => now()->addDays(10)->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);

        $c->delete();

        $this->assertSame('ملغي', DB::table('contract_obligations')
            ->where('contract_id', $c->id)->value('status'),
            'حُذف العقد والتزاماته «قائمة» تُنذر للأبد في الرادار والموجز ولوحة CEO');
    }
}
