<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function test_viewer_can_list_but_not_create(): void
    {
        $this->seedCore();
        $this->actingAs($this->viewer)->get('/m/clients')->assertOk();
        $this->actingAs($this->viewer)->post('/m/clients', ['name' => 'ممنوع'])->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'ممنوع']);
    }

    public function test_employee_creates_but_cannot_delete(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->post('/m/clients', ['name' => 'عميل الموظفة'])->assertRedirect();
        $c = Client::where('name', 'عميل الموظفة')->firstOrFail();

        $this->actingAs($this->employee)->delete('/m/clients/' . $c->id)->assertForbidden();
        $this->assertNull($c->fresh()->deleted_at);
    }

    public function test_owner_soft_deletes_and_restores(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'سيُحذف']);
        $this->actingAs($this->owner)->delete('/m/clients/' . $c->id)->assertRedirect();
        $this->assertSoftDeleted('clients', ['id' => $c->id]);

        $this->actingAs($this->owner)->post('/m/clients/' . $c->id . '/restore')->assertRedirect();
        $this->assertNull($c->fresh()->deleted_at);
    }

    public function test_admin_centers_are_owner_only(): void
    {
        $this->seedCore();
        foreach (['/admin/ops', '/admin/errors', '/admin/webhooks', '/admin/quality', '/admin/security'] as $p) {
            $this->actingAs($this->employee)->get($p)->assertForbidden();
            $this->actingAs($this->owner)->get($p)->assertOk();
        }
    }

    public function test_edit_updates_and_audits(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'قبل التعديل']);
        $this->actingAs($this->employee)
            ->put('/m/clients/' . $c->id, ['name' => 'بعد التعديل', '_reason' => 'تصحيح الاسم'])
            ->assertRedirect();
        $this->assertSame('بعد التعديل', $c->fresh()->name);
        $this->assertDatabaseHas('audits', ['record_id' => $c->id, 'action' => 'تعديل']);
    }
}
