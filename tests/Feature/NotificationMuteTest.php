<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\HubNotification;
use Tests\TestCase;

class NotificationMuteTest extends TestCase
{
    public function test_muted_kind_is_not_created_for_that_recipient(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['mute' => ['react']]]);

        // منشور للموظفة يتفاعل معه المالك — النوع react مكتوم عندها
        $c = Comment::create(['module' => 'feed', 'user_id' => $this->employee->id,
            'body' => 'منشور', 'read_by' => [], 'created_at' => now()]);
        $this->actingAs($this->owner)->post('/comments/' . $c->id . '/react', ['emoji' => '👍'])->assertRedirect();

        $this->assertSame(0, HubNotification::where('user_id', $this->employee->id)->where('kind', 'react')->count());
        // لكن الفعل نفسه جرى — التفاعل مسجَّل
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('reactions')->count());
    }

    public function test_unmuted_recipient_still_notified(): void
    {
        $this->seedCore();
        // المالك بلا كتم
        $c = Comment::create(['module' => 'feed', 'user_id' => $this->owner->id,
            'body' => 'منشور المالك', 'read_by' => [], 'created_at' => now()]);
        $this->actingAs($this->employee)->post('/comments/' . $c->id . '/react', ['emoji' => '🎉']);

        $this->assertSame(1, HubNotification::where('user_id', $this->owner->id)->where('kind', 'react')->count());
    }

    public function test_important_kinds_are_never_muteable(): void
    {
        $this->seedCore();
        // محاولة كتم «الإسناد» و«الموافقة» تُتجاهل — ليست ضمن القابل للكتم
        $this->actingAs($this->employee)->post('/personalize', [
            'home' => 'dashboard', 'mute' => ['assign', 'approval', 'react'],
        ])->assertRedirect();

        $mute = $this->employee->fresh()->prefs['mute'];
        $this->assertSame(['react'], $mute, 'يُقبل القابل للكتم فقط');

        // إشعار إسناد يصل رغم محاولة الكتم
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assign',
            'text' => 'أُسندت إليك مهمة', 'read' => false, 'created_at' => now()]);
        $this->assertSame(1, HubNotification::where('kind', 'assign')->count());
    }

    public function test_mute_is_per_user(): void
    {
        $this->seedCore();
        $this->employee->update(['prefs' => ['mute' => ['flow']]]);

        // إشعار flow للموظفة مكتوم، وللمشاهد يصل
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'flow', 'text' => 'ت', 'read' => false, 'created_at' => now()]);
        HubNotification::create(['user_id' => $this->viewer->id, 'kind' => 'flow', 'text' => 'ت', 'read' => false, 'created_at' => now()]);

        $this->assertSame(0, HubNotification::where('user_id', $this->employee->id)->count());
        $this->assertSame(1, HubNotification::where('user_id', $this->viewer->id)->count());
    }
}
