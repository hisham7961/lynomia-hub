<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\DmMessage;
use App\Models\HubNotification;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DmTest extends TestCase
{
    public function test_send_notifies_and_read_receipt_on_open(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'مرحباً'])
            ->assertRedirect(route('dm.thread', $this->employee->id) . '#bottom');

        $m = DmMessage::first();
        $this->assertNull($m->read_at);
        $this->assertSame(1, HubNotification::where('user_id', $this->employee->id)->where('kind', 'dm')->count());

        // الفتح يختم القراءة
        $this->actingAs($this->employee)->get('/dm/' . $this->owner->id)->assertOk()->assertSee('مرحباً');
        $this->assertNotNull($m->fresh()->read_at);
    }

    public function test_thread_is_private_to_its_two_parties(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'سري بيننا']);

        // طرف ثالث يفتح خيطه مع كل طرف — لا يرى رسالتهما
        $this->actingAs($this->viewer)->get('/dm/' . $this->owner->id)->assertOk()->assertDontSee('سري بيننا');
        $this->actingAs($this->viewer)->get('/dm')->assertOk()->assertDontSee('سري بيننا');

        // ولا محادثة مع النفس
        $this->actingAs($this->viewer)->get('/dm/' . $this->viewer->id)->assertNotFound();
    }

    public function test_unread_count_and_same_thread_for_both_directions(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner)->post('/dm/' . $this->employee->id, ['body' => 'أولى']);
        $this->actingAs($this->employee)->post('/dm/' . $this->owner->id, ['body' => 'رد']);

        $this->assertSame(1, DmMessage::distinct('thread_key')->count('thread_key'),
            'الاتجاهان خيط واحد');

        $this->actingAs($this->owner);
        $this->assertSame(1, \App\Http\Controllers\Web\DmController::unreadCount());
        $this->actingAs($this->owner)->get('/dm/' . $this->employee->id);
        $this->assertSame(0, \App\Http\Controllers\Web\DmController::unreadCount());
    }

    public function test_reaction_toggles_notifies_and_rejects_unknown_emoji(): void
    {
        $this->seedCore();
        $c = Comment::create(['module' => 'feed', 'user_id' => $this->owner->id,
            'body' => 'منشور', 'read_by' => [], 'created_at' => now()]);

        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/react', ['emoji' => '👍'])->assertRedirect();
        $this->assertSame(1, DB::table('reactions')->count());
        $this->assertSame(1, HubNotification::where('kind', 'react')->where('user_id', $this->owner->id)->count());

        // نفس الرمز ثانيةً يزيله — وتفاعل المرء مع منشوره لا يُشعره
        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/react', ['emoji' => '👍']);
        $this->assertSame(0, DB::table('reactions')->count());

        $this->actingAs($this->owner)->post('/comments/' . $c->id . '/react', ['emoji' => '👍']);
        $this->assertSame(1, HubNotification::where('kind', 'react')->count(), 'لا إشعار للنفس');

        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/react', ['emoji' => '💀'])->assertStatus(422);
    }

    public function test_reacting_on_record_comment_requires_module_visibility(): void
    {
        $this->seedCore();
        $p = \App\Models\Project::create(['name' => 'مشروع', 'status' => 'قيد التنفيذ']);
        $c = Comment::create(['module' => 'projects', 'record_id' => $p->id,
            'user_id' => $this->owner->id, 'body' => 'تعليق', 'read_by' => [], 'created_at' => now()]);

        $role = $this->viewer->role;
        $m = $role->matrix; $m['projects'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role->update(['matrix' => $m]);

        $this->actingAs($this->viewer)->post('/comments/' . $c->id . '/react', ['emoji' => '👍'])
            ->assertForbidden();
    }
}
