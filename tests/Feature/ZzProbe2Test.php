<?php
namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Role;
use Tests\TestCase;

class ZzProbe2Test extends TestCase
{
    protected function setUp(): void { parent::setUp(); $this->seedCore(); }

    public function test_probe_role_changes_are_audited(): void
    {
        $before = AuditEntry::count();
        $this->actingAs($this->owner)->post('/admin/roles', [
            'name' => 'دور جديد', 'scope' => 'all', 'flags_submitted' => '1',
            'matrix' => ['vault' => ['v' => '1', 'e' => '1']],
        ])->assertRedirect();
        $role = Role::where('name', 'دور جديد')->first();
        $this->actingAs($this->owner)->put("/admin/roles/{$role->id}", [
            'name' => 'دور جديد', 'scope' => 'all', 'flags_submitted' => '1',
            'flags' => ['secrets' => '1', 'users' => '1'],
            'matrix' => ['vault' => ['v' => '1', 'e' => '1', 'd' => '1']],
        ])->assertRedirect();
        dump(['audit_before' => $before, 'audit_after' => AuditEntry::count(),
              'roles_module_entries' => AuditEntry::where('module', 'roles')->count(),
              'all_modules' => AuditEntry::pluck('module')->unique()->values()->all()]);
    }

    public function test_probe_fr_document_order(): void
    {
        // reproduce document order of fr[] inputs exactly as blade emits them
        $groups = \App\Http\Controllers\Web\RoleController::groupedModules();
        $order = []; $n = 0;
        foreach ($groups as $gl => $g) {
            foreach ($g['items'] as $mk => $md) {
                foreach ($md['fields'] as $f) { $n++; $order[$mk] = $n; }
            }
        }
        // prefix vars: _token,_method,name,scope,flags_submitted = 5
        $budget = 1000 - 5;
        $dropped = [];
        $lastPos = 0;
        foreach ($order as $mk => $endPos) {
            if ($endPos > $budget) $dropped[] = $mk;
        }
        dump(['total_fr' => $n, 'budget_with_zero_matrix_checked' => $budget,
              'modules_fully_or_partly_dropped' => $dropped,
              'hr_last_field_pos' => $order['hr'] ?? null,
              'payroll_pos' => $order['payroll'] ?? null,
              'users_pos' => $order['users'] ?? null]);
    }
}
