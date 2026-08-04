<?php

namespace Tests\Feature;

use App\Models\SignRequest;
use App\Support\Evidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الرابعة — ثغرة دليلٍ صامتة (tamper-evidence-gap):
 * الشهادة تطبع «أي عبثٍ بحدثٍ ماضٍ يكسر كل ما بعده»، لكن سلسلة `Evidence::chain`
 * تُجزّئ من صف الموقّع خمسة أعمدة فقط (الاسم/الهوية/وقت التوقيع/التوقيع/السيلفي)
 * وتُغفل ما تعرضه شهادة الإتمام حرفياً: **الدور** و**الحالة** و**IP الموقّع**؛
 * كما تُغفل `sign_requests.signer_name` الذي تعرضه صفحة /verify. فتعديلٌ قاعديّ
 * (قلبُ حالةٍ من «رُفض» إلى «وُقّع»، تغييرُ دورٍ، تزويرُ IP، أو تبديلُ signer_name)
 * يُبقي `evidence_hash` مطابقاً بينما تُعرض القيم المزوّرة كـ«موثّقة».
 *
 * الإصلاح: ضمّ role/status/ip إلى تجزئة الموقّع، وsigner_name إلى مرساة الرأس —
 * فالعبث بأيٍّ منها يكسر السلسلة، كما يفعل العبث بالاسم/التوقيع (المُجزّأين سلفاً).
 */
class EvidenceTamperRound4Test extends TestCase
{
    protected function seedSignedRequest(): string
    {
        $rid = (string) Str::uuid();
        DB::table('sign_requests')->insert(['id' => $rid, 'title' => 'اتفاقية موقّعة',
            'body' => 'النص المتفق عليه', 'doc_hash' => hash('sha256', 'النص المتفق عليه'),
            'pass' => bcrypt('Secret1234'), 'token' => Str::random(48),
            'verify_code' => 'LYN-' . strtoupper(Str::random(4)) . '-TMP',
            'signer_name' => 'الموقّع الأصلي', 'status' => 'وُقّع', 'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_signers')->insert([
            'id' => (string) Str::uuid(), 'request_id' => $rid, 'order' => 1, 'name' => 'الموقّع الأصلي',
            'token' => Str::random(48), 'role' => 'موقّع', 'status' => 'وُقّع', 'id_no' => '299001234567',
            'ip' => '10.0.0.1', 'signature' => 'data:image/png;base64,SIGN', 'signed_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('contract_events')->insert(['id' => (string) Str::uuid(), 'request_id' => $rid,
            'event' => 'signed', 'signer_id' => DB::table('contract_signers')
                ->where('request_id', $rid)->where('order', 1)->value('id'),
            'ip' => '10.0.0.1', 'created_at' => now()]);

        return $rid;
    }

    protected function chainHead(string $rid): string
    {
        [, $head] = Evidence::chain(SignRequest::findOrFail($rid));

        return $head;
    }

    public function test_chain_covers_signer_role_status_ip(): void
    {
        $this->seedCore();
        $rid = $this->seedSignedRequest();
        $base = $this->chainHead($rid);

        $cases = [
            'الحالة'    => ['status' => 'رُفض',        'orig' => 'وُقّع'],
            'الدور'     => ['role'   => 'مستلم نسخة',  'orig' => 'موقّع'],
            'IP الموقّع' => ['ip'     => '6.6.6.6',      'orig' => '10.0.0.1'],
        ];

        foreach ($cases as $label => $c) {
            $col = array_key_first($c);
            DB::table('contract_signers')->where('request_id', $rid)->where('order', 1)
                ->update([$col => $c[$col]]);

            $this->assertNotSame($base, $this->chainHead($rid),
                "العبث بـ«{$label}» لم يكسر سلسلة الأدلة — الشهادة تعرضه كموثّق وهو مزوّر");

            // استعادة القيمة الأصلية قبل الحالة التالية
            DB::table('contract_signers')->where('request_id', $rid)->where('order', 1)
                ->update([$col => $c['orig']]);
            $this->assertSame($base, $this->chainHead($rid), 'الاستعادة لم تُرجع الرأس — الاختبار غير معزول');
        }
    }

    public function test_chain_covers_request_signer_name(): void
    {
        $this->seedCore();
        $rid = $this->seedSignedRequest();
        $base = $this->chainHead($rid);

        // signer_name على مستوى الطلب — تعرضه /verify، عمودٌ مختلف عن meta حدث signed
        DB::table('sign_requests')->where('id', $rid)->update(['signer_name' => 'اسمٌ مزوّر']);

        $this->assertNotSame($base, $this->chainHead($rid),
            'بُدّل signer_name المعروض في /verify والسلسلة «سليمة» — عمودٌ خارج التجزئة');
    }
}
