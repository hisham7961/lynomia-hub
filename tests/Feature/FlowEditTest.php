<?php

namespace Tests\Feature;

use App\Models\Flow;
use Tests\TestCase;

/**
 * تعديل المسارات: كان المسار يُنشأ ويُحذف ولا يُعدَّل — فتصحيح كلمةٍ في نصّ
 * إشعار يعني حذفه وإعادة بنائه من الصفر، وتضيع معه عدّادات تشغيله وتاريخه.
 */
class FlowEditTest extends TestCase
{
    protected function flow(array $extra = []): Flow
    {
        return Flow::create(array_merge([
            'name' => 'مسار للتعديل', 'module' => 'tasks', 'event' => 'created',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'النص الأصلي']],
            'enabled' => true, 'runs' => 7,
        ], $extra));
    }

    public function test_edit_form_is_prefilled_with_current_values(): void
    {
        $this->seedCore();
        $f = $this->flow([
            'event' => 'status', 'status_to' => 'منجزة',
            'cond_field' => 'priority', 'cond_op' => 'has', 'cond_value' => 'عاجلة',
            'actions' => [['type' => 'notify', 'to' => 'owners', 'text' => 'نصٌّ محفوظ'],
                          ['type' => 'task', 'text' => 'مهمة متابعة', 'assignee' => null]],
        ]);

        $html = $this->actingAs($this->owner)->get('/admin/flows/' . $f->id . '/edit')
            ->assertOk()->getContent();

        $this->assertStringContainsString('نصٌّ محفوظ', $html, 'نص الإشعار يُعبَّأ مسبقاً');
        $this->assertStringContainsString('مهمة متابعة', $html, 'وعنوان المهمة كذلك');
        $this->assertStringContainsString('عاجلة', $html, 'وقيمة الشرط');
        $this->assertStringContainsString('7', $html, 'وعدّاد التشغيل معروض');
    }

    public function test_update_saves_changes_and_keeps_run_counter(): void
    {
        $this->seedCore();
        $f = $this->flow();

        $this->actingAs($this->owner)->put('/admin/flows/' . $f->id, [
            'name' => 'اسمٌ جديد', 'm' => 'tasks', 'event' => 'status', 'status_to' => 'منجزة',
            'cond_field' => 'priority', 'cond_op' => 'eq', 'cond_value' => 'عالية',
            'a_notify' => 1, 'a_notify_to' => 'owners', 'a_notify_text' => 'نصٌّ معدّل',
            'a_task' => 1, 'a_task_title' => 'مهمة مضافة',
        ])->assertRedirect();

        $f->refresh();
        $this->assertSame('اسمٌ جديد', $f->name);
        $this->assertSame('status', $f->event);
        $this->assertSame('منجزة', $f->status_to);
        $this->assertSame('عالية', $f->cond_value);
        $this->assertSame(7, (int) $f->runs, 'التعديل يحفظ عدّاد التشغيل — لا يبدأ من الصفر');

        $types = array_column((array) $f->actions, 'type');
        $this->assertSame(['notify', 'task'], $types, 'الإجراءات تُستبدل بما اختير في النموذج');
        $this->assertSame('نصٌّ معدّل', $f->actions[0]['text']);

        // ويُدقَّق التعديل
        $this->assertDatabaseHas('audits', ['action' => 'تعديل مسار', 'record_id' => $f->id]);
    }

    public function test_update_refuses_empty_actions_and_unknown_event(): void
    {
        $this->seedCore();
        $f = $this->flow();

        // بلا إجراء: يُرفض ويبقى المسار كما هو
        $this->actingAs($this->owner)->from('/admin/flows/' . $f->id . '/edit')
            ->put('/admin/flows/' . $f->id, ['name' => 'بلا إجراءات', 'm' => 'tasks', 'event' => 'created'])
            ->assertRedirect('/admin/flows/' . $f->id . '/edit')->assertSessionHasErrors('actions');
        $this->assertSame('مسار للتعديل', $f->fresh()->name);

        // حدث غير معرَّف: يُرفض
        $this->actingAs($this->owner)->from('/admin/flows/' . $f->id . '/edit')
            ->put('/admin/flows/' . $f->id, [
                'name' => 'حدث وهمي', 'm' => 'tasks', 'event' => 'حدث.غير.موجود',
                'a_notify' => 1, 'a_notify_text' => 'س',
            ])->assertSessionHasErrors('event');
    }

    public function test_duplicate_copies_disabled_with_fresh_counters(): void
    {
        $this->seedCore();
        $f = $this->flow(['last_run_at' => now()]);

        $this->actingAs($this->owner)->post('/admin/flows/' . $f->id . '/duplicate')->assertRedirect();

        $copy = Flow::where('id', '!=', $f->id)->firstOrFail();
        $this->assertStringContainsString('نسخة', $copy->name);
        $this->assertFalse((bool) $copy->enabled, 'النسخة تولد معطّلة حتى تُراجَع');
        $this->assertSame(0, (int) $copy->runs, 'بعدّادٍ نظيف');
        $this->assertNull($copy->last_run_at);
        // assertEquals لا assertSame: كائنُ JSON **غير مرتَّب بتعريفه**، وMySQL 8
        // يُخزّنه بنوعٍ أصليّ يُعيد ترتيب مفاتيحه — فالترتيبُ ليس جزءاً من العقد
        $this->assertEquals($f->actions, $copy->actions, 'وبالإجراءات نفسها');
    }

    public function test_edit_is_owner_only(): void
    {
        $this->seedCore();
        $f = $this->flow();

        $this->actingAs($this->employee)->get('/admin/flows/' . $f->id . '/edit')->assertForbidden();
        $this->actingAs($this->employee)->put('/admin/flows/' . $f->id, [
            'name' => 'تسلل', 'm' => 'tasks', 'event' => 'created', 'a_notify' => 1,
        ])->assertForbidden();
        $this->actingAs($this->employee)->post('/admin/flows/' . $f->id . '/duplicate')->assertForbidden();
        $this->assertSame('مسار للتعديل', $f->fresh()->name);
    }
}
