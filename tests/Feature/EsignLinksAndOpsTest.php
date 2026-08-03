<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Attachment;
use App\Models\HubNotification;
use App\Models\Policy;
use App\Models\PolicyAck;
use App\Models\Project;
use App\Models\SignRequest;
use App\Models\SignTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * دفعة v2.106: ربط التوقيع بالموافقات والقرارات والسياسات وإقراراتها،
 * معاينة المرفقات الحية، درع الترحيلات المعلقة، ترقية مركز التشغيل،
 * ومؤشرات الشك في مركز النشاط.
 */
class EsignLinksAndOpsTest extends TestCase
{
    protected string $sig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sig = 'data:image/png;base64,' . base64_encode(str_repeat('توقيع', 40));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** طلب توقيع مربوط بجهة — عبر واجهة الإنشاء نفسها */
    protected function makeLinked(string $module, string $id): SignRequest
    {
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'وثيقة ' . $module, 'free_body' => 'نص الوثيقة للاختبار.',
            'pass' => 'p1234', 'link_module' => $module, 'link_id' => $id,
        ]);

        return SignRequest::where('link_module', $module)->where('link_id', $id)->firstOrFail();
    }

    protected function signIt(SignRequest $req, string $name = 'الموقّع'): void
    {
        auth()->logout();
        $this->post("/sign/{$req->token}/unlock", ['pass' => 'p1234']);
        $this->post("/sign/{$req->token}", ['signer_name' => $name, 'signature' => $this->sig])->assertOk();
    }

    /** موافقة موجهة لشخص: توقيعه يعتمدها، ورفضه يرفضها */
    public function test_signing_completes_directed_approval(): void
    {
        $this->seedCore();
        $ap = Approval::create(['title' => 'اعتماد ميزانية الحملة', 'type' => 'مصروف', 'status' => 'معلّق']);

        $req = $this->makeLinked('approvals', $ap->id);
        $this->signIt($req, 'المدير المالي');

        $ap->refresh();
        $this->assertSame('معتمد', $ap->status);
        $this->assertNotNull($ap->decided_at);
        $this->assertTrue(HubNotification::where('text', 'LIKE', '%اعتُمدت بتوقيع%المدير المالي%')->exists());

        // والرفض يرفض
        $ap2 = Approval::create(['title' => 'شراء أجهزة', 'type' => 'طلب شراء', 'status' => 'معلّق']);
        $req2 = $this->makeLinked('approvals', $ap2->id);
        auth()->logout();
        $this->post("/sign/{$req2->token}/unlock", ['pass' => 'p1234']);
        $this->post("/sign/{$req2->token}/decline", ['reason' => 'الميزانية لا تسمح'])->assertOk();
        $this->assertSame('مرفوض', $ap2->fresh()->status);
    }

    /** الموافقة المُلزِمة (لها عملية مؤجلة) لا تُعتمد بالتوقيع — حسمها من شاشتها حصراً */
    public function test_enforcement_approval_not_flipped_by_signature(): void
    {
        $this->seedCore();
        $ap = Approval::create(['title' => 'حذف سجل محمي', 'type' => 'حذف بيانات',
            'status' => 'معلّق', 'mod' => 'clients', 'op' => 'd']);

        $req = $this->makeLinked('approvals', $ap->id);
        $this->signIt($req);

        $this->assertSame('معلّق', $ap->fresh()->status, 'الملزِمة اعتُمدت بالتوقيع دون تنفيذ حمولتها!');
    }

    /** توقيع سياسة يولّد سجل إقرار موثّق تلقائياً */
    public function test_signing_policy_creates_documented_ack(): void
    {
        $this->seedCore();
        $pol = Policy::create(['title' => 'سياسة أمن المعلومات', 'ver' => '3.0', 'status' => 'سارية']);

        $req = $this->makeLinked('policies', $pol->id);
        $this->signIt($req, 'غيث الموظف');

        $ack = PolicyAck::first();
        $this->assertNotNull($ack, 'لم يتولد سجل إقرار');
        $this->assertSame($pol->id, $ack->policy_id);
        $this->assertSame('3.0', $ack->ver);
        $this->assertSame('مُقرّة', $ack->status);
        $this->assertStringContainsString('غيث الموظف', $ack->title);
        $this->assertStringContainsString($req->verify_code, (string) $ack->notes);
        $this->assertNotNull($ack->ack_at);
        $this->assertNotEmpty($ack->ip);
    }

    /** توقيع سجل إقرارٍ معلّق يُتمّه ببيانات التوقيع */
    public function test_signing_pending_ack_completes_it(): void
    {
        $this->seedCore();
        $pol = Policy::create(['title' => 'سياسة العهد', 'ver' => '1.0']);
        $ack = PolicyAck::create(['title' => 'سياسة العهد — موظف جديد',
            'policy_id' => $pol->id, 'status' => 'بانتظار الإقرار']);

        $req = $this->makeLinked('policyacks', $ack->id);
        $this->signIt($req, 'الموظف الجديد');

        $ack->refresh();
        $this->assertSame('مُقرّة', $ack->status);
        $this->assertStringContainsString($req->verify_code, (string) $ack->notes);
    }

    /** الجهات الجديدة تظهر في قائمة الربط، وقوالب المكتبة الجديدة تُبذر دون بعث المحذوف */
    public function test_new_linkables_listed_and_template_topup_respects_deletions(): void
    {
        $this->seedCore();
        Approval::create(['title' => 'موافقة للربط', 'type' => 'مصروف', 'status' => 'معلّق']);
        Policy::create(['title' => 'سياسة للربط', 'ver' => '1.0']);

        $this->actingAs($this->owner)->get('/esign')->assertOk()
            ->assertSee('✅ موافقة')->assertSee('📜 سياسة')
            ->assertSee('إقرار التزام بسياسة')->assertSee('اعتماد قرار / موافقة');

        // حذف قالبٍ مبذور ثم زيارة جديدة — لا يُبعث من جديد
        $tpl = SignTemplate::where('name', 'إقرار التزام بسياسة')->firstOrFail();
        $this->actingAs($this->owner)->delete('/esign/templates/' . $tpl->id)->assertRedirect();
        $this->actingAs($this->owner)->get('/esign')->assertOk();
        $this->assertNull(SignTemplate::where('name', 'إقرار التزام بسياسة')->first(),
            'القالب المحذوف عمداً بُعث من المكتبة');
    }

    /** بطاقة «التواقيع المرتبطة» على صفحة الموافقة نفسها برابط تهيئةٍ مسبقة */
    public function test_reverse_card_on_approval_page(): void
    {
        $this->seedCore();
        $ap = Approval::create(['title' => 'موافقة بصفحتها بطاقة', 'type' => 'مصروف', 'status' => 'معلّق']);
        $req = $this->makeLinked('approvals', $ap->id);

        $this->actingAs($this->owner)->get('/m/approvals/' . $ap->id)->assertOk()
            ->assertSee('التواقيع المرتبطة')->assertSee($req->title);
    }

    /* ────────── معاينة المرفقات الحية ────────── */

    /** الصورة تُعاين حيّةً inline، وSVG يبقى تنزيلاً (قد يحمل سكربتات) */
    public function test_attachment_live_preview_for_images_only(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع الشهادات', 'status' => 'قيد التنفيذ']);

        $this->actingAs($this->owner)->post('/attachments', ['module' => 'projects',
            'record_id' => $p->id, 'file' => UploadedFile::fake()->image('شهادة.png', 60, 40)]);
        $img = Attachment::latest('created_at')->first();

        $r = $this->actingAs($this->owner)->get('/attachments/' . $img->id . '/view');
        $r->assertOk();
        $this->assertSame('image/png', $r->headers->get('content-type'));
        $this->assertSame('nosniff', $r->headers->get('x-content-type-options'));
        // ويُسجَّل الاطلاع
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $img->id)->count());

        // صفحة السجل تعرض المصغّر والمعاينة داخل الصفحة
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)->assertOk()
            ->assertSee('/attachments/' . $img->id . '/view', false)
            ->assertSee('معاينة كاملة داخل الصفحة');

        $this->actingAs($this->owner)->post('/attachments', ['module' => 'projects',
            'record_id' => $p->id,
            'file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]);
        $svg = Attachment::where('original_name', 'logo.svg')->firstOrFail();
        $this->actingAs($this->owner)->get('/attachments/' . $svg->id . '/view')->assertStatus(415);
    }

    /** المعاينة تخضع لقفل نقل الملفات خارج الدوام كما التنزيل تماماً */
    public function test_preview_blocked_after_hours_like_download(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $p = Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $this->actingAs($this->owner)->post('/attachments', ['module' => 'projects',
            'record_id' => $p->id, 'file' => UploadedFile::fake()->image('sample.png')]);
        $a = Attachment::first();

        Carbon::setTestNow(now()->setTime(21, 30));
        $this->actingAs($this->employee)->get('/attachments/' . $a->id . '/view')->assertStatus(403);
        // المالك معفى
        $this->actingAs($this->owner)->get('/attachments/' . $a->id . '/view')->assertOk();
    }

    /* ────────── درع الترحيلات المعلقة ────────── */

    /** فجوة كود/قاعدة: لافتة تحذير للمالك في كل الصفحات وزر الحل */
    public function test_pending_migration_banner_for_owner(): void
    {
        $this->seedCore();
        DB::table('migrations')->orderBy('id', 'desc')->limit(1)->delete();   // نحاكي ترحيلاً معلقاً
        Cache::forget('hub.pending_migrations');

        $this->actingAs($this->owner)->get('/')->assertOk()
            ->assertSee('ترحيل قاعدة بيانات معلّق');
        // الموظف لا يُقلق بها
        $this->actingAs($this->employee)->get('/me')->assertOk()
            ->assertDontSee('ترحيل قاعدة بيانات معلّق');

        DB::table('migrations')->insert(['migration' => 'x_restore_marker', 'batch' => 99]);
        Cache::forget('hub.pending_migrations');
    }

    /** مركز التوقيع على قاعدة لم تُرحَّل: رسالة صريحة 503 لا QueryException غامضة */
    public function test_esign_gives_clear_error_before_migration(): void
    {
        $this->seedCore();
        Schema::drop('sign_requests');   // نحاكي خادماً وصله الكود ولم تصله الهجرة

        $this->actingAs($this->owner)->get('/esign')->assertStatus(503);
    }

    /* ────────── ترقية مركز التشغيل ────────── */

    public function test_ops_center_new_cards_and_buttons(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->get('/admin/ops')->assertOk()
            ->assertSee('بيئة التشغيل')->assertSee('نسخة احتياطية الآن')
            ->assertSee('وضع الصيانة')->assertSee('على النظام الآن');

        // النسخ الاحتياطي بضغطة — مع تنظيف الملفات المتولدة كي لا تلوث اختبارات أخرى
        $before = glob(storage_path('app/backups/hub-*.json')) ?: [];
        $this->actingAs($this->owner)->post('/admin/ops/backup')->assertRedirect();
        $made = array_diff(glob(storage_path('app/backups/hub-*.json')) ?: [], $before);
        $this->assertNotEmpty($made, 'لم يُكتب ملف نسخة احتياطية');
        foreach ($made as $f) @unlink($f);

        // تبديل وضع الصيانة ذهاباً وإياباً
        $this->actingAs($this->owner)->post('/admin/ops/maintenance')->assertRedirect();
        $this->assertSame('1', (string) setting('maintenance.on'));
        $this->actingAs($this->owner)->post('/admin/ops/maintenance')->assertRedirect();
        $this->assertSame('', (string) setting('maintenance.on', ''));

        // ليس للموظف
        $this->actingAs($this->employee)->post('/admin/ops/backup')->assertStatus(403);
    }

    /* ────────── مؤشرات الشك في مركز النشاط ────────── */

    public function test_activity_risk_profile(): void
    {
        $this->seedCore();
        $e = $this->employee;

        // نشاط داخل الدوام (١٠ صباحاً) وفي ساعة مريبة (٣ فجراً)
        foreach ([10 => 4, 3 => 2] as $hour => $n) {
            for ($i = 0; $i < $n; $i++) {
                DB::table('page_visits')->insert(['id' => \Illuminate\Support\Str::uuid(),
                    'user_id' => $e->id, 'path' => '/dashboard',
                    'at' => now()->subDay()->setTime($hour, $i * 10)]);
            }
        }
        DB::table('sessions_log')->insert(['id' => \Illuminate\Support\Str::uuid(),
            'user_id' => $e->id, 'device' => 'Chrome — Windows', 'ip' => '1.2.3.4',
            'started_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]);
        hub_audit('دخول مريب', null, null, $e->name, ['user_id' => $e->id]);

        $r = $this->actingAs($this->owner)->get('/admin/activity/' . $e->id);
        $r->assertOk()->assertSee('مؤشرات الشك')->assertSee('نسبة الشك بالمستخدم')
            ->assertSee('معدل التلاعب')->assertSee('الساعات المريبة')
            ->assertSee('التسجيل من أجهزة مختلفة');

        // ساعات مصنفة فعلاً: ٤ سلال دوام = ٠٫٣ ساعة، وسلتا فجرٍ = ٠٫٢
        $r->assertSee('0.3')->assertSee('0.2');
    }
}
