<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Quote;
use App\Models\Role;
use App\Models\User;
use App\Support\SalesBoard;
use Tests\TestCase;

/**
 * CPQ المرحلة د — لوحةُ المبيعات وتحليلاتُ العروض وعرض ٣٦٠.
 */
class SalesDashboardTest extends TestCase
{
    private function seedQuotes(): Client
    {
        $c = Client::create(['name' => 'عميل']);
        // مقبولان + مرفوض + مُرسل + مسودة
        Quote::create(['client_id' => $c->id, 'total' => 10000, 'cost' => 6000, 'currency' => 'د.ك', 'status' => 'مقبول', 'sent_at' => now()->subDays(5), 'accepted_at' => now()]);
        Quote::create(['client_id' => $c->id, 'total' => 5000, 'cost' => 2000, 'currency' => 'د.ك', 'status' => 'محوّل', 'meta' => ['project_id' => 'p1']]);
        Quote::create(['client_id' => $c->id, 'total' => 3000, 'currency' => 'د.ك', 'status' => 'مرفوض']);
        Quote::create(['client_id' => $c->id, 'total' => 8000, 'currency' => 'دولار', 'status' => 'مُرسل']);
        Quote::create(['client_id' => $c->id, 'total' => 2000, 'currency' => 'د.ك', 'status' => 'مسودة']);

        return $c;
    }

    public function test_board_computes_meaningful_metrics(): void
    {
        $this->seedCore();
        $this->seedQuotes();
        $d = SalesBoard::data();

        $this->assertSame(2, $d['counts']['accepted']);
        $this->assertSame(1, $d['counts']['lost']);
        $this->assertSame(1, $d['counts']['sent']);
        $this->assertSame(1, $d['counts']['draft']);
        // معدّل الفوز = مقبول ٢ ÷ (مقبول ٢ + خاسر ١) = ٦٧٪
        $this->assertSame(67, $d['winRate']);
        // تحوّل لمشروع: واحدٌ من اثنين = ٥٠٪
        $this->assertSame(50, $d['convRate']);
        // القيمةُ المقبولة مجمَّعةً بالعملة (١٥٠٠٠ د.ك)
        $this->assertSame(15000.0, $d['valueByCur']['accepted']['د.ك']);
    }

    public function test_dashboard_renders_for_owner(): void
    {
        $this->seedCore();
        $this->seedQuotes();
        $this->actingAs($this->owner)->get('/sales?fresh=1')->assertOk()
            ->assertSee('لوحة المبيعات')->assertSee('معدّل الفوز');
    }

    public function test_dashboard_forbidden_without_monitor(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظف', 'scope' => 'proj', 'flags' => [], 'matrix' => ['quotes' => ['v' => 1]]]);
        $u = User::create(['name' => 'عاديّ', 'email' => 'plain@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/sales')->assertForbidden();
    }

    public function test_quote_360_shows_traceability_chain(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميل السلسلة']);
        $q = Quote::create(['client_id' => $c->id, 'total' => 5000, 'status' => 'محوّل',
            'meta' => ['project_id' => 'proj-x', 'engagement_id' => 'eng-x']]);

        $this->actingAs($this->owner)->get('/m/quotes/' . $q->id)->assertOk()
            ->assertSee('سلسلةُ الأثر التجاريّ')->assertSee('المشروع')->assertSee('الارتباط');
    }
}
