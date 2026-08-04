<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\SignRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * بند ١: مركزُ العقد المُعاد تصميمه — التوقيع ومراحله، الموقّعون، التحقّق بالرمز/QR،
 * وسلسلةُ الملاحق/التجديدات، في شاشة عرض العقد. اختبارُ تصيير: يظهر ولا ينكسر.
 */
class ContractDetailRedesignTest extends TestCase
{
    public function test_contract_detail_shows_the_signing_command_center(): void
    {
        $this->seedCore();

        $c = Contract::create(['title' => 'عقد الخدمة الرئيسي', 'type' => 'عقد عميل',
            'status' => 'ساري', 'kind' => 'أصلي']);

        $req = SignRequest::create([
            'title' => 'توقيع عقد الخدمة', 'contract_id' => $c->id, 'mode' => 'مفرد',
            'body' => 'نص العقد', 'pass' => Hash::make(Str::random(12)), 'token' => Str::random(48),
            'status' => 'وُقّع', 'verify_code' => 'LYN-ABCD-1234', 'signer_name' => 'أحمد المدير',
            'signed_at' => now(), 'signed_ip' => '1.2.3.4',
        ]);
        ContractSigner::create(['request_id' => $req->id, 'order' => 1, 'role' => 'موقّع',
            'name' => 'أحمد المدير', 'status' => 'وُقّع', 'token' => Str::random(20)]);

        // تجديدٌ فرعيّ لإظهار سلسلة العقد
        Contract::create(['title' => 'تجديد عقد الخدمة', 'type' => 'عقد عميل',
            'status' => 'مسودة', 'kind' => 'تجديد', 'parent_id' => $c->id]);

        $html = $this->actingAs($this->owner)->get('/m/contracts/' . $c->id)->assertOk()->getContent();

        $this->assertStringContainsString('التوقيع الإلكتروني ومراحله', $html, 'لوحة التوقيع غائبة');
        $this->assertStringContainsString('LYN-ABCD-1234', $html, 'رمز التحقق لا يظهر');
        $this->assertStringContainsString('أحمد المدير', $html, 'الموقّع لا يظهر');
        $this->assertStringContainsString('سلسلة العقد', $html, 'سلسلة العقد غائبة');
        $this->assertStringContainsString('تجديد عقد الخدمة', $html, 'التجديد الفرعيّ لا يظهر في السلسلة');
    }
}
