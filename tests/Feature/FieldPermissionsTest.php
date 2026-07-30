<?php

namespace Tests\Feature;

use Tests\TestCase;

class FieldPermissionsTest extends TestCase
{
    protected function hideSalary(): void
    {
        $this->employee->role->forceFill(['field_rules' => [
            'hr' => ['salary' => 'hide', 'iqama' => 'ro'],
        ]])->save();
    }

    protected function emp(): \App\Models\Employee
    {
        return \App\Models\Employee::create(['name' => 'موظف الاختبار', 'dept' => 'تطوير',
            'salary' => 1500, 'iqama' => 'IQ-777', 'status' => 'على رأس العمل']);
    }

    public function test_hidden_field_invisible_in_pages_but_owner_sees_it(): void
    {
        $this->seedCore();
        $this->hideSalary();
        $e = $this->emp();

        $this->actingAs($this->employee)->get('/m/hr/' . $e->id)->assertOk()->assertDontSee('1,500')->assertDontSee('الراتب');
        $this->actingAs($this->owner)->get('/m/hr/' . $e->id)->assertOk()->assertSee('الراتب');
    }

    public function test_hidden_and_ro_fields_not_writable_even_if_injected(): void
    {
        $this->seedCore();
        $this->hideSalary();
        $e = $this->emp();

        $this->actingAs($this->employee)->put('/m/hr/' . $e->id, [
            'name' => 'موظف الاختبار', 'dept' => 'تصميم',
            'salary' => 99999, 'iqama' => 'HACKED',
        ])->assertRedirect();

        $e->refresh();
        $this->assertSame('تصميم', $e->dept);                 // المسموح تغيّر
        $this->assertEquals(1500, (float) $e->salary);        // المخفي لم يُمس
        $this->assertSame('IQ-777', $e->iqama);               // قراءة فقط لم يُمس
    }

    public function test_api_strips_hidden_fields(): void
    {
        $this->seedCore();
        $this->hideSalary();
        $e = $this->emp();

        $tok = $this->apiToken($this->employee);
        $data = $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->getJson('/api/v1/hr/' . $e->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('salary', $data);
        $this->assertArrayNotHasKey('salary', $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->getJson('/api/v1/hr')->json('data.0') ?? []);

        $full = $this->apiToken($this->owner);
        $this->assertEquals(1500, (float) $this->withHeader('Authorization', 'Bearer ' . $full)
            ->getJson('/api/v1/hr/' . $e->id)->json('data.salary'));
    }

    public function test_hidden_required_field_does_not_block_creation(): void
    {
        $this->seedCore();
        // إخفاء اسم العميل الإلزامي — الدور المقيد يجب ألا يعلق على تحقق حقل لا يراه
        $this->employee->role->forceFill(['field_rules' => ['clients' => ['email' => 'hide']]])->save();
        $this->actingAs($this->employee)->post('/m/clients', ['name' => 'بلا بريد', 'email' => 'x@y.z'])
            ->assertRedirect();
        $c = \App\Models\Client::where('name', 'بلا بريد')->firstOrFail();
        $this->assertNull($c->email);                          // المخفي لم يُكتب رغم حقنه
    }
}
