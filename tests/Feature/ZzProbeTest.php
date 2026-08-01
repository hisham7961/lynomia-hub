<?php
namespace Tests\Feature;

use App\Models\Role;
use Tests\TestCase;

class ZzProbeTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); $this->seedCore(); }

    public function test_probe_template_flag_leak(): void
    {
        // admin picks the "tech" template but hand-builds the matrix (ticks one box)
        // and ticks NO flag checkbox at all.
        $res = $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'تجربة',
            'scope' => 'all',
            'template' => 'tech',
            'flags_submitted' => '1',
            'matrix' => ['tasks' => ['v' => '1']],
            // no fr[] at all
        ]);
        $res->assertRedirect();
        $role = Role::where('name', 'تجربة')->first();
        dump(['matrix' => $role->matrix, 'flags' => $role->flags, 'fr' => $role->field_rules]);
    }

    public function test_probe_template_hide_dropped(): void
    {
        $res = $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'مدير مشاريع',
            'scope' => 'proj',
            'template' => 'pm',
            'flags_submitted' => '1',
            // matrix empty -> template matrix applies
            'fr' => ['tasks' => ['title' => 'ro']],   // one unrelated restriction
        ]);
        $res->assertRedirect();
        $role = Role::where('name', 'مدير مشاريع')->first();
        dump(['fr' => $role->field_rules, 'flags' => $role->flags, 'matrixCount' => count($role->matrix)]);
    }

    public function test_probe_form_control_counts(): void
    {
        $r = Role::create(['name' => 'ز', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $html = $this->actingAs($this->owner)->get("/admin/roles/{$r->id}/edit")->getContent();
        dump([
            'bytes' => strlen($html),
            'fr_selects' => substr_count($html, 'name="fr['),
            'mx_boxes' => substr_count($html, 'name="matrix['),
            'flag_boxes' => substr_count($html, 'name="flags['),
        ]);
    }
}
