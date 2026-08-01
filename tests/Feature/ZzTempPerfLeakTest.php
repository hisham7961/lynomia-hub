<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ZzTempPerfLeakTest extends TestCase
{
    public function test_perf_leak(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركتي', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة أخرى', 'status' => 'نشطة']);

        // ثلاثة عملاء تحت الشركة الأجنبية
        foreach (['ألف', 'باء', 'جيم'] as $n) {
            Client::create(['name' => 'عميل ' . $n, 'company_id' => $coB->id]);
        }

        $role = Role::create(['name' => 'مراقب', 'scope' => 'all',
            'flags' => ['monitor' => 1], 'matrix' => []]);
        $u = User::create(['name' => 'مراقب معزول', 'email' => 'mon@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$coA->id]]);

        // الثلاثة الأشقاء
        $this->actingAs($u)->get('/capacity')->assertForbidden();
        $this->actingAs($u)->get('/service-costs')->assertForbidden();
        $this->actingAs($u)->get('/supplier-scores')->assertForbidden();

        $r = $this->actingAs($u)->get('/performance');
        fwrite(STDERR, "\nPERF STATUS: " . $r->getStatusCode() . "\n");
        if ($r->getStatusCode() === 200) {
            $co = $r->viewData('company');
            fwrite(STDERR, 'newClients=' . var_export($co['newClients'], true)
                . ' clients=' . var_export($co['clients'], true) . "\n");
            $this->assertTrue(str_contains($r->getContent(), 'عملاء جدد هذا الشهر'));
        }
        $this->assertTrue(true);
    }
}
