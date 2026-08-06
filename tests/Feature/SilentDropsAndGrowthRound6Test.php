<?php

namespace Tests\Feature;

use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ١٢: **ما يسقط صامتاً، وما ينمو بلا حدّ.**
 *
 *  ١) `SupportController:20` — `whereNotIn('status', …)` يُقيَّم `NULL NOT IN`
 *     = **مجهول**، فتذكرةٌ بلا حالة تسقط من لوحة الدعم صامتةً: لا أحد يردّ
 *     عليها ولا شيء يقول لماذا. (نفسُ صنف عيب الرواتب في v2.315.)
 *  ٢) `SysMonitor::memory():67` — عاملُ الاتحاد `+` **لا يدهس** المفتاح
 *     القائم، و`$out` يحمل `'ok' => false` — فبطاقةُ الذاكرة تُعلن الفشلَ
 *     دائماً مهما كانت القراءةُ ناجحة.
 *  ٣) `Auditable:53` — `_reason` مصفوفةً يُسقط قيدَ التدقيق (‏`hub_fit` تتوقّع
 *     نصّاً) **ويُبقي الكتابة**: تعديلٌ يقع بلا أثرٍ في السجل.
 *  ٤) `bootstrap/app.php:23` — وضعُ الصيانة لا يسري على سطح API: الكتابةُ
 *     تستمرّ أثناء الترحيل من الباب الخلفيّ.
 *  ٥) `routes/web.php:246` — الفحصُ الحيّ بلا حدِّ معدّل: كلُّ ضغطةٍ تحجز
 *     عاملاً ثوانيَ طويلة، بلا سقف.
 *  ٦) `InboundHookController:45` — سجلُّ الويبهوك الوارد بلا تقليم: نموٌّ غيرُ
 *     محدود من سطحٍ عامّ.
 */
class SilentDropsAndGrowthRound6Test extends TestCase
{
    /* ── ١) تذكرةٌ بلا حالة لا تسقط من اللوحة ── */

    public function test_a_ticket_with_null_status_still_appears_on_the_support_board(): void
    {
        $this->seedCore();

        $t = Ticket::create(['subject' => 'تذكرةٌ بلا حالة', 'status' => 'مفتوحة']);
        $t->forceFill(['status' => null])->saveQuietly();

        $html = $this->actingAs($this->owner)->get('/support')->assertOk()->getContent();
        $this->assertStringContainsString('تذكرةٌ بلا حالة', $html,
            'تذكرةٌ بلا حالة سقطت من لوحة الدعم صامتةً — NULL NOT IN يُقيَّم «مجهولاً» لا «صحيحاً»');
    }

    /* ── ٢) بطاقةُ الذاكرة تُعلن نجاحاً حين تنجح ── */

    public function test_memory_probe_reports_success_when_it_reads(): void
    {
        if (! is_readable('/proc/meminfo')) {
            $this->assertTrue(true, 'لا /proc/meminfo على هذا النظام');

            return;
        }

        $m = \App\Support\SysMonitor::memory();
        $this->assertTrue((bool) ($m['ok'] ?? false),
            'قراءةُ الذاكرة نجحت والبطاقة تُعلن الفشل — عامل الاتحاد لا يدهس المفتاح القائم');
        $this->assertGreaterThan(0, (int) ($m['total'] ?? 0));
    }

    /* ── ٣) سببٌ مصفوفةً لا يُسقط قيدَ التدقيق ── */

    public function test_an_array_change_reason_does_not_kill_the_audit_entry(): void
    {
        $this->seedCore();

        $c = \App\Models\Client::create(['name' => 'عميلُ التدقيق']);
        $before = \App\Models\AuditEntry::count();

        $this->actingAs($this->owner)->put('/m/clients/' . $c->id, [
            'name' => 'اسمٌ جديد', '_reason' => ['سببٌ', 'مصفوفةً'],
        ]);

        $after = \App\Models\AuditEntry::count();
        if ((string) $c->fresh()->name === 'اسمٌ جديد') {
            $this->assertGreaterThan($before, $after,
                'وقع التعديلُ ولم يُكتب قيدُ تدقيقه — سببٌ مصفوفةً يُسقط القيد ويُبقي الكتابة');
        }
    }

    /* ── ٤) وضعُ الصيانة يسري على API ── */

    public function test_maintenance_mode_also_blocks_the_api_surface(): void
    {
        $this->seedCore();
        $this->hubSetting('maintenance.on', '1');

        $token = $this->apiToken($this->owner);
        $res = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/clients', ['name' => 'كتابةٌ أثناء الصيانة']);

        $this->assertNotSame(201, $res->getStatusCode(),
            'الكتابةُ عبر API تستمرّ أثناء الصيانة — بابٌ خلفيّ يلتفّ على الترحيل');
        $this->assertSame(0, \App\Models\Client::where('name', 'كتابةٌ أثناء الصيانة')->count());
    }

    /* ── ٥) الفحصُ الحيّ محدودُ المعدّل ── */

    public function test_the_live_check_route_is_rate_limited(): void
    {
        $src = file_get_contents(base_path('routes/web.php'));
        $this->assertMatchesRegularExpression(
            "/monitor\/\{module\}\/\{id\}\/check'[^;]*throttle:/s", $src,
            'الفحصُ الحيّ بلا حدِّ معدّل — كلُّ ضغطةٍ تحجز عاملاً ثوانيَ طويلة بلا سقف');
    }

    /* ── ٦) سجلُّ الويبهوك الوارد يُقلَّم ── */

    public function test_the_inbound_hook_log_is_pruned(): void
    {
        $this->seedCore();

        $ep = \App\Models\InboundHook::create(['name' => 'نقطة', 'token' => Str::random(48),
            'module' => 'clients', 'status' => 'مفعّلة', 'created_by' => $this->owner->id]);

        for ($i = 0; $i < 60; $i++) {
            DB::table('inbound_hook_events')->insert([
                'hook_id' => $ep->id, 'status' => 200,
                'payload' => '{}', 'created_at' => now()->subDays(400)->addSeconds($i),
            ]);
        }

        $this->artisan('hub:automation')->run();

        $this->assertLessThan(60, DB::table('inbound_hook_events')->count(),
            'سجلُّ الويبهوك الوارد بلا تقليم — نموٌّ غيرُ محدود من سطحٍ عامّ');
    }
}
