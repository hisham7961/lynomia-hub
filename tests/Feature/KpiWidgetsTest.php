<?php

namespace Tests\Feature;

use App\Models\FinDocument;
use App\Models\KpiDef;
use Tests\TestCase;

class KpiWidgetsTest extends TestCase
{
    protected function kpi(): void
    {
        FinDocument::create(['doc_no' => 'A', 'kind' => 'فاتورة', 'total' => 1000, 'paid' => 450, 'state' => 'جزئي']);
        KpiDef::create(['name' => 'نسبة التحصيل', 'unit' => '٪', 'target' => 90, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'paid'],
                          'b' => ['agg' => 'sum', 'module' => 'fin', 'col' => 'total'], 'combine' => 'ratio_pct'],
            'sort' => 1]);
    }

    public function test_custom_kpi_shows_as_dashboard_widget_for_owner(): void
    {
        $this->seedCore();
        $this->kpi();

        $this->actingAs($this->owner)->get('/')->assertOk()
            ->assertSee('نسبة التحصيل')->assertSee('مؤشر مخصص');
    }

    public function test_widgets_hidden_from_non_monitor_employee(): void
    {
        $this->seedCore();
        $this->kpi();

        $this->actingAs($this->employee)->get('/')->assertOk()->assertDontSee('نسبة التحصيل');
    }

    public function test_widgets_hideable_via_dash_pref(): void
    {
        $this->seedCore();
        $this->kpi();
        $this->owner->update(['prefs' => ['dash' => ['hidden' => ['kpis']]]]);

        $this->actingAs($this->owner->fresh())->get('/')->assertOk()->assertDontSee('نسبة التحصيل');
    }
}
