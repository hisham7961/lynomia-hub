<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\HubNotification;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * تدقيقٌ عميق (جولة ٢) — عزلُ المعتمِدين: لا يُسرَّب اسمُ/مبلغُ سجلٍّ لمعتمِدٍ
 * معزولٍ عن شركته في إشعارات مسار الطلب (كنمط notifyMonitors الآليّ).
 */
class ApproverScopeTest extends TestCase
{
    private function approverScopedTo(Company $c, string $email): User
    {
        $role = Role::create(['name' => 'معتمِدٌ معزول', 'scope' => 'company',
            'flags' => ['approve' => 1], 'matrix' => ['quotes' => ['v' => 1]]]);

        return User::create(['name' => 'معتمِد', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => [$c->id]]);
    }

    public function test_hub_approvers_for_excludes_out_of_scope_approver(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $approverB = $this->approverScopedTo($b, 'appb@test.local');

        $qA = Quote::create(['title' => 'عرض أ', 'total' => 9000, 'currency' => 'د.ك', 'company_id' => $a->id]);

        $for = hub_approvers_for('quotes', $qA->id);
        $this->assertContains($this->owner->id, $for, 'المالكُ يرى الكلّ');
        $this->assertNotContains($approverB->id, $for, 'معتمِدُ «ب» لا يرى عرضَ «أ»');
    }

    public function test_send_over_threshold_does_not_notify_out_of_scope_approver(): void
    {
        $this->seedCore();
        $this->hubSetting('quotes.approve_amount', '100');   // عتبةٌ منخفضةٌ تُفعّل مسارَ الاعتماد
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $approverB = $this->approverScopedTo($b, 'appb@test.local');

        // مُرسِلٌ غيرُ مالكٍ ولا معتمِد (يُفعّل مسارَ الاعتماد) — نطاقٌ شامل ليُرسل
        $senderRole = Role::create(['name' => 'مبيعات', 'scope' => 'all', 'flags' => [], 'matrix' => ['quotes' => ['v' => 1, 'e' => 1]]]);
        $sender = User::create(['name' => 'مندوب مبيعات', 'email' => 'sales@test.local', 'password' => 'Secret!2026x',
            'role_id' => $senderRole->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $qA = Quote::create(['title' => 'عرض حسّاس أ', 'total' => 9000, 'currency' => 'د.ك', 'company_id' => $a->id, 'status' => 'مسودة']);

        $this->actingAs($sender)->post('/quote/' . $qA->id . '/act', ['do' => 'send'])->assertRedirect();

        // المالكُ (معتمِدٌ غيرُ محدود) يُشعَر؛ معتمِدُ «ب» لا يُشعَر باسمِ عرضِ «أ» ومبلغِه
        $this->assertTrue(HubNotification::where('user_id', $this->owner->id)->where('kind', 'approval')->exists());
        $this->assertFalse(HubNotification::where('user_id', $approverB->id)->exists(),
            'سُرِّب عرضُ شركةٍ أخرى لمعتمِدٍ معزول');
    }
}
