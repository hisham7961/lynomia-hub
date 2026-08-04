<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * الموجة الرابعة — إنفاذ قفل الحقل (hub_field_mode) على مسارات العرض:
 * قفل الحقل ('hide') كان يُحترم في القائمة والصفحة والتصدير والنموذج، لكن ثلاث
 * شاشاتٍ موازية تسرّبه: لوحة الكانبان، ومستند أمر الشراء القابل للطباعة، وبطاقات
 * البوابة. دورٌ محجوبٌ عليه حقلٌ يقرأ قيمته كاملةً من هذه الشاشات.
 */
class FieldModeViewLeakRound4Test extends TestCase
{
    /** مستخدم بدورٍ يرى الوحدة لكن يُخفي حقلاً بعينه */
    protected function restricted(string $module, string $fieldKey, array $ops = ['v' => 1]): User
    {
        $role = Role::create(['name' => 'مقيّد ' . uniqid(), 'scope' => 'all', 'flags' => [],
            'matrix' => [$module => $ops], 'field_rules' => [$module => [$fieldKey => 'hide']]]);

        return User::create(['name' => 'مقيّد', 'email' => 'fm-' . uniqid() . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** (أ) لوحة الكانبان لا تكشف الأولوية المحجوبة */
    public function test_kanban_board_hides_field_masked_priority(): void
    {
        $this->seedCore();
        $u = $this->restricted('tasks', 'priority');
        \App\Models\Task::create(['title' => 'مهمة', 'status' => 'جديدة', 'priority' => 'أولوية_سرية_٩']);

        $this->actingAs($u)->get('/m/tasks/board')
            ->assertOk()
            ->assertDontSee('أولوية_سرية_٩');
    }

    /** (ب) مستند أمر الشراء القابل للطباعة لا يكشف الإجمالي المحجوب */
    public function test_purchase_print_doc_hides_field_masked_amount(): void
    {
        $this->seedCore();
        $u = $this->restricted('purchases', 'amount');
        $p = Purchase::create(['doc_no' => 'PO-FM', 'title' => 'أمر', 'status' => 'معتمد',
            'amount' => 987654, 'currency' => 'د.ك', 'items' => 'صنف | 3 | 100']);

        $this->actingAs($u)->get("/purchase/{$p->id}/doc")
            ->assertOk()
            ->assertDontSee('987,654')
            ->assertDontSee('987654');
    }

    /** (ج) بطاقة البوابة لا تكشف الراتب المحجوب لصاحبها */
    public function test_portal_card_hides_field_masked_salary(): void
    {
        $this->seedCore();
        $u = $this->restricted('hr', 'salary');
        Employee::create(['name' => 'صاحب البوابة', 'status' => 'نشط',
            'user_id' => $u->id, 'salary' => 654321]);

        $this->actingAs($u)->get('/me')
            ->assertOk()
            ->assertDontSee('654,321')
            ->assertDontSee('654321');
    }
}
