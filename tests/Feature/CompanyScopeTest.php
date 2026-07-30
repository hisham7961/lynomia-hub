<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use Tests\TestCase;

class CompanyScopeTest extends TestCase
{
    public function test_switcher_filters_module_lists_and_reset_shows_all(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        $b = Company::create(['name_ar' => 'شركة باء']);
        Client::create(['name' => 'عميل ألف', 'company_id' => $a->id]);
        Client::create(['name' => 'عميل باء', 'company_id' => $b->id]);

        $this->actingAs($this->owner)->post('/company-switch', ['company' => $a->id])->assertRedirect();
        $this->actingAs($this->owner)->get('/m/clients')->assertSee('عميل ألف')->assertDontSee('عميل باء');

        $this->actingAs($this->owner)->post('/company-switch', ['company' => ''])->assertRedirect();
        $this->actingAs($this->owner)->get('/m/clients')->assertSee('عميل ألف')->assertSee('عميل باء');
    }

    public function test_switch_validates_permission_and_existence(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);

        // دور بلا رؤية للشركات لا يبدل
        $m = $this->viewer->role->matrix;
        $m['companies'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $this->viewer->role->forceFill(['matrix' => $m])->save();
        $this->actingAs($this->viewer)->post('/company-switch', ['company' => $a->id])->assertForbidden();

        $this->actingAs($this->owner)
            ->post('/company-switch', ['company' => '00000000-0000-0000-0000-000000000000'])
            ->assertNotFound();
    }

    public function test_scope_ignores_modules_without_company_column(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة ألف']);
        \App\Models\Task::create(['title' => 'مهمة عامة', 'status' => 'جديدة']);

        $this->actingAs($this->owner)->post('/company-switch', ['company' => $a->id]);
        $this->actingAs($this->owner)->get('/m/tasks')->assertOk()->assertSee('مهمة عامة');
    }
}
