<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\HubNotification;
use App\Support\AlertEngine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **استخراجُ محرّك التنبيه بلا تغيير سلوك** (WP-6.3).
 *
 * جوهرُ `HubAutomation::alertRules` انتقل إلى `App\Support\AlertEngine::daily`
 * حرفياً — والاختباراتُ القائمة الخمسة (AlertEscalationTest ·
 * AutomationReachAndScopeTest · NotifyAndRulesRound6Test · NotifyBoundsRound8Test
 * · ScopeGuardRound6Test) تحرس السلوكَ الدقيق (تنطيقٌ لكل مستلم، ترقيمٌ بمؤشّر
 * المعرّف، تصعيد). هنا يُحرَس **مفصلُ** الاستخراج نفسُه: الأمرُ اليوميّ يمرّ
 * بالمحرّك، والقواعدُ النافذية لا تتسرّب إلى السكّة اليومية ولا العكس.
 */
class AlertEngineTest extends TestCase
{
    /** الأمرُ اليوميّ ما زال يُطلق قواعدَ الحقول — عبر المحرّك المستخرج */
    public function test_daily_field_rules_still_fire_through_the_engine(): void
    {
        $this->seedCore();
        \App\Models\Task::create(['title' => 'مهمة متأخرة', 'due' => now()->subDays(3)->toDateString(), 'status' => 'جديدة']);
        $rule = AlertRule::create(['name' => 'مهامّ متأخرة', 'mod' => 'tasks', 'field' => 'due',
            'op' => 'أيام مضت أكثر من', 'val' => '1', 'status' => 'مفعّلة', 'chan' => 'داخل النظام']);

        Artisan::call('hub:automation');

        $this->assertGreaterThanOrEqual(1,
            HubNotification::where('kind', 'rule:' . $rule->id)->count(),
            'قاعدةُ حقلٍ قائمة كفّت عن الإطلاق بعد الاستخراج — تغيّر سلوك');
    }

    /** قاعدةٌ نافذية (لها source) لا تدخل السكّةَ اليومية — سكّتُها evaluate */
    public function test_windowed_rules_are_skipped_by_the_daily_lane(): void
    {
        $this->seedCore();
        $rule = AlertRule::create(['name' => 'قفل الطوارئ', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'status' => 'مفعّلة', 'source' => 'security.lockdown', 'window_min' => 5]);
        $this->hubSetting('security.lockdown', '1');

        (new AlertEngine())->daily();
        $this->assertSame(0, HubNotification::where('kind', 'rule:' . $rule->id)->count(),
            'السكّةُ اليومية قيّمت قاعدةً نافذية — ازدواجُ إطلاق');

        (new AlertEngine())->evaluate(['components' => []]);
        $this->assertSame(1, DB::table('alert_instances')->where('rule_id', $rule->id)->count(),
            'السكّةُ النافذية لم تكتب ذاكرةَ التنبيه');
        $this->assertGreaterThanOrEqual(1, HubNotification::where('kind', 'rule:' . $rule->id)->count());
    }

    /** مصدرٌ مجهول يُتخطّى بتبليغٍ — لا يرمي ولا يحلّ صفوفَ غيره */
    public function test_unknown_source_is_skipped_not_fatal(): void
    {
        $this->seedCore();
        AlertRule::create(['name' => 'مصدر غريب', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'status' => 'مفعّلة', 'source' => 'nope.unknown', 'window_min' => 5]);

        $r = (new AlertEngine())->evaluate(['components' => []]);

        $this->assertSame(0, $r['fired']);
        $this->assertSame(0, DB::table('alert_instances')->count());
    }

    /** شكلُ عائد daily كما كان (hits/rules/outbox/esc) — يقرؤه سطرُ hub:automation */
    public function test_daily_return_shape_is_unchanged(): void
    {
        $this->seedCore();
        $r = (new AlertEngine())->daily();

        $this->assertSame(['hits', 'rules', 'outbox', 'esc'], array_keys($r));
    }
}
