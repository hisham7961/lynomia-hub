<?php

namespace Tests\Feature;

use App\Models\Idea;
use App\Models\Project;
use Tests\TestCase;

class InnovationTest extends TestCase
{
    public function test_ice_score_and_ranking(): void
    {
        $this->seedCore();
        Idea::create(['title' => 'حجز', 'impact' => 9, 'confidence' => 7, 'ease' => 6, 'status' => 'معتمدة']);   // 378
        Idea::create(['title' => 'أتمتة', 'impact' => 6, 'confidence' => 8, 'ease' => 9, 'status' => 'قيد التقييم']); // 432
        Idea::create(['title' => 'بلا تقييم', 'status' => 'جديدة']);

        $r = $this->actingAs($this->owner)->get('/innovation')->assertOk();
        $ideas = $r->original->getData()['ideas'];

        $this->assertSame(432, $ideas[0]->ice, 'الأعلى درجةً أولاً');
        $this->assertSame(378, $ideas[1]->ice);
        $this->assertNull($ideas[2]->ice, 'غير المقيَّمة تنزل للأسفل بلا درجة');
    }

    public function test_promote_creates_linked_project_and_blocks_double(): void
    {
        $this->seedCore();
        $i = Idea::create(['title' => 'تطبيق جديد', 'problem' => 'بطء الحجز', 'idea' => 'تطبيق ذاتي',
            'impact' => 8, 'confidence' => 7, 'ease' => 7, 'status' => 'معتمدة']);

        $this->actingAs($this->owner)->post('/ideas/' . $i->id . '/promote')
            ->assertRedirect();

        $i->refresh();
        $this->assertNotNull($i->project_id);
        $this->assertSame('قيد التنفيذ', $i->status);
        $p = Project::find($i->project_id);
        $this->assertSame('تطبيق جديد', $p->name);
        $this->assertStringContainsString('بطء الحجز', (string) $p->description);

        // ترقية ثانية تُرفض
        $this->actingAs($this->owner)->post('/ideas/' . $i->id . '/promote')->assertStatus(422);
        $this->assertSame(1, Project::where('name', 'تطبيق جديد')->count());
    }

    public function test_promote_requires_projects_add_permission(): void
    {
        $this->seedCore();
        $i = Idea::create(['title' => 'فكرة', 'status' => 'معتمدة']);

        // المشاهد لا يملك إضافة مشاريع
        $this->actingAs($this->viewer)->post('/ideas/' . $i->id . '/promote')->assertForbidden();
        $this->assertNull($i->fresh()->project_id);
    }

    public function test_board_gated_by_ideas_visibility(): void
    {
        $this->seedCore();
        $role = $this->viewer->role;
        $m = $role->matrix; $m['ideas'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer)->get('/innovation')->assertForbidden();
        $this->actingAs($this->owner)->get('/innovation')->assertOk()->assertSee('مركز الابتكار');
    }
}
