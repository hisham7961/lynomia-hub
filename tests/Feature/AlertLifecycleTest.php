<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\AuditEntry;
use App\Models\HubNotification;
use App\Models\SignalState;
use App\Support\AlertEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **دورةُ حياة التنبيه** (WP-6.3 · §9.3): triggered → acknowledged → resolved.
 * الإقرارُ سكّةٌ واحدة (ق٣ + critic #5): يعيش في `alert_instances` وحدَه —
 * يُسكِت الجرسَ ولا يُسكِت العدّاد — والذاكرةُ تنجو من تقليم الإشعارات.
 */
class AlertLifecycleTest extends TestCase
{
    protected function windowedRule(int $cooldown = 1): AlertRule
    {
        return AlertRule::create(['name' => 'فشل الدخول', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => '3', 'status' => 'مفعّلة', 'source' => 'security.failed_logins',
            'window_min' => 240, 'cooldown_min' => $cooldown]);
    }

    protected function fire(AlertRule $rule): object
    {
        foreach (range(1, 3) as $i) {
            AuditEntry::create(['action' => 'دخول فاشل', 'name' => "u{$i}@x.local", 'created_at' => now()]);
        }
        (new AlertEngine())->evaluate(['components' => []]);

        return DB::table('alert_instances')->where('rule_id', $rule->id)->whereNull('subject')->first();
    }

    /** الإقرارُ يمنع تكرارَ الإشعار — والعدّادُ ينمو لأن الشرطَ لم يزل */
    public function test_ack_stops_repeat_notification_while_the_counter_grows(): void
    {
        $this->seedCore();
        $rule = $this->windowedRule(cooldown: 1);
        $i = $this->fire($rule);
        $this->assertNotNull($i);
        $notifs = fn () => HubNotification::where('kind', 'rule:' . $rule->id)->count();
        $before = $notifs();

        // الإقرارُ من مركز التنبيهات — مالكٌ + قيدُ تدقيق
        $this->actingAs($this->owner)->post("/admin/alerts/{$i->id}/ack")->assertRedirect();
        $i = DB::table('alert_instances')->where('id', $i->id)->first();
        $this->assertSame('acknowledged', $i->status);
        $this->assertSame((string) $this->owner->id, (string) $i->acknowledged_by);
        $this->assertNotNull($i->acknowledged_at);
        $this->assertSame(1, AuditEntry::where('action', 'إقرار تنبيه')->count(), 'الإقرارُ بلا أثرِ تدقيق');

        // التبريدُ دقيقةٌ واحدة وقد انقضت — ومع ذلك لا إشعارَ لأن التنبيه مُقَرٌّ به
        $this->travel(5)->minutes();
        (new AlertEngine())->evaluate(['components' => []]);
        $after = DB::table('alert_instances')->where('id', $i->id)->first();
        $this->assertSame($before, $notifs(), 'الإقرارُ لم يُسكِت الجرس');
        $this->assertGreaterThan((int) $i->count, (int) $after->count, 'العدّادُ توقّف — الإقرارُ سكوتُ جرسٍ لا تجميدُ رصد');
        $this->assertSame('acknowledged', $after->status, 'الإقرارُ ضاع بالتقييم التالي');
    }

    /** **ولا سكّةَ إقرارٍ ثانية** (critic #5): لا صفَّ signal_states للتنبيه أبداً */
    public function test_ack_lives_in_alert_instances_only_no_second_rail(): void
    {
        $this->seedCore();
        $rule = $this->windowedRule();
        $i = $this->fire($rule);

        $this->actingAs($this->owner);
        // الإشارةُ تظهر في مركز الفعل بمفتاح dedup_key — بلا تصرّفٍ محليّ
        $signals = \App\Support\ActionCenter::signals(true);
        $keys = array_column($signals['visible'], 'key');
        $this->assertContains($i->dedup_key, $keys, 'التنبيهُ المفتوح غائبٌ عن مركز الفعل (ق٣)');
        $sig = collect($signals['visible'])->firstWhere('key', $i->dedup_key);
        $this->assertFalse($sig['can_act'], 'إشارةُ التنبيه قابلةٌ للتصرّف محلياً — سكّةُ إقرارٍ ثانية');

        // ومحاولةُ التصرّف عبر signal_states تُرفَض (المفتاحُ ليس في صفّ hub_recommendations)
        $this->assertFalse(\App\Support\ActionCenter::disposition($i->dedup_key, 'ack'));
        $this->assertSame(0, SignalState::where('skey', $i->dedup_key)->count(),
            'كُتب صفُّ signal_states لتنبيهٍ — الإقرارُ الدائم في alert_instances وحدَه');

        // والموظّفُ (لا مالكَ ولا monitor) لا يرى إشارةَ التنبيه ولا المركز
        $this->actingAs($this->employee);
        $keysEmp = array_column(\App\Support\ActionCenter::signals(true)['visible'], 'key');
        $this->assertNotContains($i->dedup_key, $keysEmp, 'إشارةُ التنبيه تسرّبت لغير المالك/المراقب');
        $this->actingAs($this->employee)->get('/admin/alerts')->assertForbidden();
        $this->actingAs($this->employee)->post("/admin/alerts/{$i->id}/ack")->assertForbidden();
    }

    /** الذاكرةُ تنجو من تقليم الإشعارات — علّةُ وجود الجدول (خطّة WP-6.3) */
    public function test_instance_survives_notification_pruning(): void
    {
        $this->seedCore();
        $rule = $this->windowedRule();
        $i = $this->fire($rule);
        $this->assertGreaterThanOrEqual(1, HubNotification::where('kind', 'rule:' . $rule->id)->count());

        // بعد أكثر من سنة يقلّم hub:automation الإشعاراتِ كلَّها (٩٠/٣٦٥ يوماً)
        $this->travel(400)->days();
        Artisan::call('hub:automation');

        $this->assertSame(0, HubNotification::where('kind', 'rule:' . $rule->id)->count(),
            'التقليمُ لم يعمل — الاختبارُ لا يختبر شيئاً');
        $row = DB::table('alert_instances')->where('id', $i->id)->first();
        $this->assertNotNull($row, 'ذاكرةُ التنبيه قُلّمت مع الإشعارات — عادت فقدانُ الذاكرة الصامت');
        $this->assertSame($i->dedup_key, $row->dedup_key);
    }

    /** عودةُ الشرط بعد التعافي نوبةٌ جديدة: الإقرارُ القديم لا يُسكِتها */
    public function test_reopen_after_recovery_clears_the_old_ack(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'قفل الطوارئ', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'status' => 'مفعّلة', 'source' => 'security.lockdown', 'window_min' => 5, 'cooldown_min' => 1]);

        $this->hubSetting('security.lockdown', '1');
        (new AlertEngine())->evaluate(['components' => []]);
        $i = DB::table('alert_instances')->where('rule_id', $rule->id)->first();
        $this->actingAs($this->owner)->post("/admin/alerts/{$i->id}/ack")->assertRedirect();

        $this->hubSetting('security.lockdown', '0');
        (new AlertEngine())->evaluate(['components' => []]);
        $this->assertSame('resolved', DB::table('alert_instances')->where('id', $i->id)->value('status'));

        $this->hubSetting('security.lockdown', '1');
        (new AlertEngine())->evaluate(['components' => []]);
        $row = DB::table('alert_instances')->where('id', $i->id)->first();
        $this->assertSame('triggered', $row->status, 'الشرطُ عاد ولم تُفتح النوبة');
        $this->assertNull($row->acknowledged_by, 'إقرارُ النوبة الماضية أسكت الجديدة');
    }

    /** مركزُ التنبيهات يقرأ للمالك والمراقب، والحالةُ الفارغة صادقة */
    public function test_center_screen_reads_and_empty_state_is_honest(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->owner)->get('/admin/alerts')->assertOk()->getContent();
        $this->assertStringContainsString('لا تنبيهات نافذية بعد', $html);
        $this->assertStringContainsString('tblwrap', $html);

        $rule = $this->windowedRule();
        $i = $this->fire($rule);
        $html = $this->actingAs($this->owner)->get('/admin/alerts')->assertOk()->getContent();
        $this->assertStringContainsString('مُطلق', $html);
        $this->assertStringContainsString(route('alerts.ack', $i->id), $html);
    }
}
