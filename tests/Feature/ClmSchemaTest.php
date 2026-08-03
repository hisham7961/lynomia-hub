<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractEvent;
use App\Models\ContractSigner;
use App\Models\SignRequest;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CLM م2 (v2.118): الترقيم، الموقّعون المرحّلون، سجل الأدلة، والخط الزمني الخامس */
class ClmSchemaTest extends TestCase
{
    public function test_contract_gets_auto_doc_no_with_yearly_sequence(): void
    {
        $this->seedCore();
        $a = Contract::create(['title' => 'أول', 'type' => 'عقد عميل']);
        $b = Contract::create(['title' => 'ثانٍ', 'type' => 'عقد عميل']);

        $y = now()->format('Y');
        $this->assertSame("CTR-$y-0001", $a->doc_no);
        $this->assertSame("CTR-$y-0002", $b->doc_no);
        $this->assertSame('أصلي', $a->kind);

        // رقم يدوي يُحترم ولا يُستبدل
        $c = Contract::create(['title' => 'يدوي', 'type' => 'عقد عميل', 'doc_no' => 'MANUAL-1']);
        $this->assertSame('MANUAL-1', $c->doc_no);

        // الصيغة من الإعدادات
        $this->hubSetting('contracts.doc_no_format', 'LYN/{YEAR}/{SEQ}');
        $d = Contract::create(['title' => 'مخصص', 'type' => 'عقد عميل']);
        $this->assertSame("LYN/$y/0001", $d->doc_no);
    }

    public function test_new_request_creates_signer_and_created_event(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد الأساس', 'type' => 'عقد عميل',
            'company_id' => \App\Models\Company::create(['name_ar' => 'شركة النطاق'])->id]);

        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'طلب الأساس', 'free_body' => 'نص', 'pass' => 'Secret1234',
            'contract_id' => $c->id,
        ]);
        $req = SignRequest::where('title', 'طلب الأساس')->firstOrFail();

        // النطاق مختوم من العقد
        $this->assertSame($c->company_id, $req->company_id);
        // موقّع مرحّل برمز الطلب
        $s = ContractSigner::where('request_id', $req->id)->first();
        $this->assertNotNull($s);
        $this->assertSame($req->token, $s->token);
        // حدث الإنشاء في سجل الأدلة
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'created')->exists());
    }

    public function test_open_sign_decline_append_events_and_mirror_signer(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'دورة الأدلة', 'free_body' => 'نص', 'pass' => 'Secret1234', 'opt_decline' => 1,
        ]);
        $req = SignRequest::where('title', 'دورة الأدلة')->firstOrFail();

        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Secret1234']);
        $this->get("/sign/{$req->token}");
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'opened')->exists());

        $png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
        $this->post("/sign/{$req->token}", ['signer_name' => 'موقّع الأدلة', 'signature' => $png])->assertOk();
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'signed')->exists());
        $s = ContractSigner::where('request_id', $req->id)->first();
        $this->assertSame('وُقّع', $s->status);
        $this->assertSame('موقّع الأدلة', $s->name);
        $this->assertNotNull($s->signed_at);
    }

    public function test_events_are_append_only(): void
    {
        $this->seedCore();
        $e = ContractEvent::log('created');
        $e->event = 'signed';
        $this->assertFalse($e->save(), 'سجل الأدلة لا يقبل تعديلاً');
        $this->assertFalse((bool) $e->delete(), 'سجل الأدلة لا يقبل حذفاً');
        $this->assertSame('created', $e->fresh()->event);
    }

    public function test_token_rotation_moves_signer_token_and_logs_voided(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'تدوير الأساس', 'free_body' => 'الأصل', 'pass' => 'Secret1234',
        ]);
        $req = SignRequest::where('title', 'تدوير الأساس')->firstOrFail();
        $old = $req->token;
        $this->post("/sign/{$old}/unlock", ['pass' => 'Secret1234']);
        $this->get("/sign/{$old}");

        $this->actingAs($this->owner)->put('/esign/' . $req->id, ['title' => 'تدوير الأساس', 'body' => 'معدل']);
        $fresh = $req->fresh();
        $this->assertNotSame($old, $fresh->token);
        $this->assertSame($fresh->token, ContractSigner::where('request_id', $req->id)->value('token'),
            'رمز الموقّع يتبع رمز الطلب عند التدوير');
        $this->assertTrue(ContractEvent::where('request_id', $req->id)->where('event', 'voided')->exists());
    }

    public function test_contract_timeline_shows_signing_events(): void
    {
        $this->seedCore();
        $c = Contract::create(['title' => 'عقد الخط الزمني', 'type' => 'عقد عميل']);
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'توقيع الخط الزمني', 'free_body' => 'نص', 'pass' => 'Secret1234',
            'contract_id' => $c->id,
        ]);
        $req = SignRequest::where('title', 'توقيع الخط الزمني')->firstOrFail();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'Secret1234']);
        $this->get("/sign/{$req->token}");
        $png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 120));
        $this->post("/sign/{$req->token}", ['signer_name' => 'موقّع الخط', 'signature' => $png]);

        $tl = hub_timeline('contracts', $c->id);
        $labels = array_column($tl, 'label');
        $this->assertContains('أُنشئ طلب توقيع', $labels);
        $this->assertContains('فُتحت الوثيقة', $labels);
        $this->assertContains('وُقّعت الوثيقة', $labels);

        // وصفحة العقد نفسها تعرضها
        $html = $this->actingAs($this->owner)->get('/m/contracts/' . $c->id)->assertOk()->getContent();
        $this->assertStringContainsString('وُقّعت الوثيقة', $html);
    }

    public function test_migration_backfill_is_idempotent_for_legacy_row(): void
    {
        $this->seedCore();
        // صف قديم خام (كما قبل م2): بلا موقّع وبلا أحداث — الترحيل يبنيهما مرة واحدة
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('sign_requests')->insert([
            'id' => $id, 'title' => 'قديم للترحيل', 'body' => 'نص', 'pass' => bcrypt('x1234'),
            'token' => \Illuminate\Support\Str::random(48), 'verify_code' => 'LYN-MIGR-TEST',
            'status' => 'وُقّع', 'signer_name' => 'موقّع قديم',
            'signed_at' => now()->subDay(), 'signed_ip' => '1.2.3.4',
            'opened_at' => now()->subDays(2),
            'created_at' => now()->subDays(3), 'updated_at' => now()->subDay(),
        ]);

        // إعادة تشغيل منطق الترحيل (المهاجرة نفسها idempotent بفحص الوجود)
        $mig = include base_path('database/migrations/2026_02_15_000001_clm_data_foundation.php');
        $mig->up();
        $mig->up();   // مرة ثانية عمداً

        $this->assertSame(1, ContractSigner::where('request_id', $id)->count(), 'موقّع واحد لا اثنان');
        $this->assertSame('موقّع قديم', ContractSigner::where('request_id', $id)->value('name'));
        $this->assertSame(3, ContractEvent::where('request_id', $id)->count(), 'created+opened+signed');
    }
}
