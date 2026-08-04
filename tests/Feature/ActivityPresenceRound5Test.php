<?php

namespace Tests\Feature;

use App\Models\SessionLog;
use Tests\TestCase;

/**
 * مركز النشاط كان أعمى عن المستخدمين النشطين: يشتقّ «آخر ظهور» من `page_visits`
 * وحدها، وهي لا تُسجَّل إلا للصفحات الكاملة (لا الملفات ولا أجزاء htmx) — فمستخدمٌ
 * يعمل في الملفات والتفاصيل يظهر فارغاً تماماً، ولا يرى المالكُ إلا نشاطَه هو.
 *
 * الإصلاح: «آخر ظهور» من **نبضة الجلسة** (`sessions_log.last_seen_at`، تُحدَّث مع
 * كل طلبٍ عبر SessionSentry) فيبين الحضورُ الحقيقيّ لكل مستخدم، مع مؤشّر «متصل الآن».
 */
class ActivityPresenceRound5Test extends TestCase
{
    public function test_activity_index_shows_presence_from_session_heartbeat(): void
    {
        $this->seedCore();

        // موظفةٌ نشطةٌ لها نبضةُ جلسةٍ حديثة (منذ دقيقتين) بلا أي page_visits اليوم
        SessionLog::create([
            'user_id'      => $this->employee->id,
            'device'       => 'جهاز',
            'ip'           => '1.2.3.4',
            'user_agent'   => 'ua',
            'started_at'   => now()->subHours(2),
            'last_seen_at' => now()->subMinutes(2),
        ]);

        $rows = $this->actingAs($this->owner)->get('/admin/activity')->assertOk()->viewData('rows');
        $emp = collect($rows)->firstWhere(fn ($r) => $r->u->id === $this->employee->id);

        $this->assertNotNull($emp, 'الموظفة غائبة من مركز النشاط');
        $this->assertNotNull($emp->last, 'آخر ظهور الموظفة فارغٌ رغم نبضة جلستها الحيّة — المركز أعمى عن نشاطها');
        $this->assertTrue((bool) $emp->online, 'الموظفة نشطةٌ الآن ولا تظهر «متصل»');
    }
}
