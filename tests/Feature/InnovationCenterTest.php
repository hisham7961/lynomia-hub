<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Idea;
use App\Support\Innovation;
use Tests\TestCase;

/**
 * الابتكار — الفكرةُ **أثرٌ في ملف صاحبها** لا سطرٌ في قائمة.
 *
 * المركز كان يرتّب الأفكار بدرجة ICE ثم يقف: لا يُعرف من يقترح، ولا كم رُقّيت
 * له فكرة، ولا تظهر أفكار الموظف في ملفه، ولا يُحتسب الاقتراح إسهاماً — فمن
 * يفكّر لا يُرى، ومن لا يفكّر لا يُفتقَد.
 */
class InnovationCenterTest extends TestCase
{
    protected function scene(): void
    {
        $this->seedCore();

        Idea::create(['title' => 'أتمتة الفواتير', 'by_id' => $this->employee->id, 'cat' => 'تشغيل',
            'impact' => 9, 'confidence' => 8, 'ease' => 7, 'status' => 'معتمدة', 'problem' => 'إدخالٌ يدوي']);
        Idea::create(['title' => 'بوابة العملاء', 'by_id' => $this->employee->id, 'cat' => 'منتج',
            'impact' => 8, 'confidence' => 7, 'ease' => 6, 'status' => 'قيد التنفيذ',
            'project_id' => \App\Models\Project::create(['name' => 'بوابة', 'status' => 'تخطيط'])->id]);
        Idea::create(['title' => 'فكرةٌ يتيمة', 'status' => 'جديدة']);
        Idea::create(['title' => 'فكرة المشاهد', 'by_id' => $this->viewer->id, 'status' => 'جديدة']);
    }

    public function test_contributors_are_ranked_by_real_contribution(): void
    {
        $this->scene();
        $c = Innovation::contributors();

        $this->assertSame($this->employee->id, $c[0]['id'], 'من رُقّيت له فكرةٌ لا يتصدّر');
        $this->assertSame(2, $c[0]['n']);
        $this->assertSame(1, $c[0]['promoted'], 'ما وصل إلى التنفيذ لا يُحتسب');
        $this->assertGreaterThan($c[1]['score'], $c[0]['score']);
    }

    public function test_an_employee_file_shows_his_ideas(): void
    {
        $this->scene();
        $e = Employee::create(['name' => 'موظفة مبتكرة', 'status' => 'نشط', 'user_id' => $this->employee->id]);

        $html = $this->actingAs($this->owner)->get("/m/hr/{$e->id}")->assertOk()->getContent();

        $this->assertStringContainsString('أفكاره ومساهمته في الابتكار', $html,
            'أفكار الموظف لا تظهر في ملفه — فالاقتراح يُنسى');
        $this->assertStringContainsString('أتمتة الفواتير', $html);
        $this->assertStringContainsString('درجة الإسهام', $html);
    }

    public function test_an_employee_without_a_user_account_does_not_break_the_page(): void
    {
        $this->scene();
        $e = Employee::create(['name' => 'موظفٌ بلا حساب', 'status' => 'نشط']);

        $this->actingAs($this->owner)->get("/m/hr/{$e->id}")->assertOk()
            ->assertDontSee('أفكاره ومساهمته في الابتكار');
    }

    public function test_the_center_shows_cards_a_pulse_and_the_proposer(): void
    {
        $this->scene();
        $html = $this->actingAs($this->owner)->get('/innovation')->assertOk()->getContent();

        $this->assertStringContainsString('مساهمو الابتكار', $html);
        $this->assertStringContainsString('اقترحها', $html, 'صاحب الفكرة لا يُذكر على بطاقتها');
        $this->assertStringContainsString('صارت مشاريع', $html);
        $this->assertStringContainsString('504', $html, 'درجة ICE لا تظهر (9×8×7)');
    }

    public function test_the_pulse_counts_what_matters(): void
    {
        $this->scene();
        $p = Innovation::pulse();

        $this->assertSame(4, $p['n']);
        $this->assertSame(2, $p['scored']);
        $this->assertSame(1, $p['promoted']);
        $this->assertSame(2, $p['people']);
    }

    public function test_ideas_of_a_person_are_listed_with_their_score(): void
    {
        $this->scene();
        $mine = Innovation::byPerson($this->employee->id);

        $this->assertCount(2, $mine);
        $this->assertSame(504, collect($mine)->firstWhere('title', 'أتمتة الفواتير')['ice']);
        $this->assertTrue(collect($mine)->firstWhere('title', 'بوابة العملاء')['promoted']);
        $this->assertSame([], Innovation::byPerson(null));
    }
}
