<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FinDocument;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **الإصلاحات الأمنية الخمسة من فحص العشرين وكيلاً** — كلٌّ كُتب فاشلاً أولاً.
 *
 * النمط الجامع: أبوابٌ جانبية أفلتت من ثلاثية الحرّاس المفروضة على الأبواب
 * الرئيسة — الدفعةُ تحرّك بنكاً بلا تنطيق، والإعلانُ يجلب سجلاً بلا نطاق،
 * وربطُ الملف يمسّ حساباتِ الملّاك بلا حارس تصعيد، والتوقيعُ المنتهي يمرّ.
 */
class AuditRemediationSecurityTest extends TestCase
{
    /* ── ١) الدفعة لا تحرّك بنكاً خارج نطاق الدافع ── */

    public function test_payment_cannot_move_a_bank_outside_the_payers_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $bankB = BankAccount::create(['name' => 'بنك ب', 'balance' => 1000, 'company_id' => $b->id, 'status' => 'نشط']);
        $doc = FinDocument::create(['doc_no' => 'INV-1', 'kind' => 'فاتورة مبيعات',
            'total' => 500, 'state' => 'مرسلة', 'company_id' => $a->id]);

        // محاسبُ الشركة أ: مالية كاملة، معزولٌ على شركته
        $finRole = Role::create(['name' => 'محاسب', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1], 'banks' => ['v' => 1, 'e' => 1]]]);
        $clerk = User::create(['name' => 'محاسب أ', 'email' => 'acc-a@t.local',
            'password' => 'Secret!2026x', 'role_id' => $finRole->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$a->id]]);

        // يوجّه الدفعة إلى بنك الشركة ب — خارج نطاقه
        $this->actingAs($clerk)->post("/fin/{$doc->id}/act", [
            'do' => 'pay', 'amount' => 500, 'bankId' => $bankB->id,
        ])->assertStatus(422);

        $this->assertSame(1000.0, (float) $bankB->fresh()->balance, 'رصيدُ بنكٍ خارج النطاق تحرّك');
        $this->assertSame(0.0, (float) $doc->fresh()->paid, 'الدفعة سُجّلت رغم رفض البنك');
    }

    public function test_moving_a_bank_needs_banks_edit_permission(): void
    {
        $this->seedCore();
        $bank = BankAccount::create(['name' => 'الصندوق', 'balance' => 100, 'status' => 'نشط']);
        $doc = FinDocument::create(['doc_no' => 'INV-2', 'kind' => 'فاتورة مبيعات',
            'total' => 50, 'state' => 'مرسلة']);

        // مالية بلا أي صلاحية على البنوك
        $role = Role::create(['name' => 'مالية فقط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['fin' => ['v' => 1, 'e' => 1]]]);
        $u = User::create(['name' => 'م', 'email' => 'finonly@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->actingAs($u)->post("/fin/{$doc->id}/act", [
            'do' => 'pay', 'amount' => 50, 'bankId' => $bank->id,
        ])->assertStatus(422);
        $this->assertSame(100.0, (float) $bank->fresh()->balance);

        // وبلا bankId تمرّ الدفعة كما كانت — تحريكُ البنك وحده هو المحروس
        $this->actingAs($u)->post("/fin/{$doc->id}/act", ['do' => 'pay', 'amount' => 50])
            ->assertRedirect();
        $this->assertSame(50.0, (float) $doc->fresh()->paid);
    }

    /* ── ٢) التوقيع المنتهي لا يمرّ من أي باب ── */

    protected function signScene(): array
    {
        $this->seedCore();
        // من الباب الحقيقي — كما تفعل اختبارات التوقيع القائمة
        $this->actingAs($this->owner)->post('/esign', [
            'title' => 'عقد تجريبي', 'free_body' => 'نص العقد',
            'pass' => 'Secret1234', 'expire_days' => 1,
        ]);
        $req = \App\Models\SignRequest::where('title', 'عقد تجريبي')->firstOrFail();
        auth()->logout();

        return [$req, $req->token];
    }

    public function test_expired_link_rejects_unlock_sign_and_decline(): void
    {
        [$req, $token] = $this->signScene();
        $this->post("/sign/{$token}/unlock", ['pass' => 'Secret1234']);   // جلسة مفتوحة قبل الانتهاء

        $req->forceFill(['expires_at' => now()->subMinute()])->save();

        // الفتح بعد الانتهاء
        $this->post("/sign/{$token}/unlock", ['pass' => '1234'])->assertStatus(410);

        // التوقيع بعد الانتهاء — ولو كانت الجلسة مفتوحةً من قبل
        $this->post("/sign/{$token}", [
            'signer_name' => 'موقّع', 'signature' => 'data:image/png;base64,' . str_repeat('A', 120),
        ])->assertStatus(410);

        // والرفض كذلك
        $this->post("/sign/{$token}/decline", ['reason' => 'سبب'])->assertStatus(410);

        $this->assertSame('بانتظار التوقيع', $req->fresh()->status, 'الحالة تبدّلت من بابٍ منتهٍ');
    }

    /* ── ٣) ربط الملفات لا يتسلق للحسابات العليا ── */

    protected function hrActor(): User
    {
        $hr = Role::create(['name' => 'موارد', 'scope' => 'all', 'flags' => [],
            'matrix' => ['hr' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => 'موارد', 'email' => 'hract@t.local',
            'password' => 'Secret!2026x', 'role_id' => $hr->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    public function test_hr_cannot_link_a_file_to_an_owner_account(): void
    {
        $this->seedCore();
        $actor = $this->hrActor();
        $emp = Employee::create(['name' => 'ملف', 'email' => 'file1@t.local', 'status' => 'نشط']);

        $this->actingAs($actor)->post("/staff/{$emp->id}/link", ['user_id' => $this->owner->id])
            ->assertForbidden();
        $this->assertNull($emp->fresh()->user_id, 'ملفٌ رُبط بحساب مالكٍ من دور موارد');

        // والمالك نفسه يستطيع — الحارس تصعيدٌ لا منعٌ مطلق
        $this->actingAs($this->owner)->post("/staff/{$emp->id}/link", ['user_id' => $this->owner->id]);
        $this->assertSame($this->owner->id, $emp->fresh()->user_id);
    }

    public function test_status_change_does_not_auto_suspend_privileged_accounts(): void
    {
        $this->seedCore();
        $actor = $this->hrActor();

        // إداري براية users — ليس مالكاً
        $adminRole = Role::create(['name' => 'إداري', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => []]);
        $admin = User::create(['name' => 'إداري', 'email' => 'adm2@t.local',
            'password' => 'Secret!2026x', 'role_id' => $adminRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
        $emp = Employee::create(['name' => 'إداري', 'email' => 'adm2@t.local',
            'status' => 'نشط', 'user_id' => $admin->id]);

        // دورُ الموارد يعدّل حالة الملف إلى منتهي — الإغلاق الآلي يجب ألا يوقف
        // حساباً ذا امتياز بيد فاعلٍ لا يملك إدارة المستخدمين
        $this->actingAs($actor)->put("/m/hr/{$emp->id}", [
            'name' => 'إداري', 'email' => 'adm2@t.local', 'status' => 'منتهي', '_version' => 1,
        ]);

        $this->assertSame('نشط', $admin->fresh()->status,
            'حسابُ إداريٍّ أُوقف آلياً بفاعلٍ لا يملك إدارة المستخدمين');
    }

    /* ── ٤) الإعلان والإقرار خلف النطاق ── */

    public function test_announce_and_ack_respect_company_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'أ']);
        $b = Company::create(['name_ar' => 'ب']);
        $policy = \App\Models\Policy::create(['title' => 'سياسة ب', 'status' => 'سارية', 'company_id' => $b->id]);

        $role = Role::create(['name' => 'سياسات', 'scope' => 'all', 'flags' => [],
            'matrix' => ['policies' => ['v' => 1, 'e' => 1]]]);
        $iso = User::create(['name' => 'معزول أ', 'email' => 'isoA@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$a->id]]);

        $this->actingAs($iso)->post("/policies/{$policy->id}/announce")->assertNotFound();
        $this->assertNull($policy->fresh()->announced_at, 'أُعلنت سياسةُ شركةٍ أخرى');

        $this->actingAs($iso)->post("/policies/{$policy->id}/ack")->assertNotFound();
        $this->assertSame(0, \App\Models\PolicyAck::where('record_id', $policy->id)
            ->where('user_id', $iso->id)->count(), 'إقرارٌ على سجلٍّ خارج النطاق');
    }

    /* ── ٥) حدّ معدل API مطبَّق لا مُعرَّفاً فحسب ── */

    public function test_api_routes_actually_carry_the_throttle_middleware(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => str_starts_with($r->uri(), 'api/v1/me'));
        $this->assertNotNull($route);
        $this->assertContains('throttle:api', $route->gatherMiddleware(),
            'حدُّ المعدل مُعرَّف في AppServiceProvider ولا مسارَ يحمله — تعريفٌ بلا تطبيق');
    }
}
