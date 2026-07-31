<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Incident;
use Tests\TestCase;

/**
 * المرحلة ٦ — إكمال دورة التشغيل:
 *  1) الحوادث كانت بلا حدثٍ دلاليّ واحد بينما لنظيراتها أحداثها، وخارج درجة
 *     «البنية التحتية» التي تعدّ `issues` بدلاً منها.
 *  2) زمن التعطل المسجَّل لم يكن يُحوَّل إلى مؤشر (MTTR).
 *  3) وحدة «الأتمتة» واجهةٌ تعِد بأتمتةٍ لا تقع — لا سطر ينفّذ قواعدها.
 */
class OpsClosureRound6Test extends TestCase
{
    public function test_incident_and_change_events_are_declared(): void
    {
        $events = config('hub.events');
        $emits = fn (string $m) => array_column($events[$m] ?? [], 'emit');

        foreach (['incident.opened', 'incident.contained', 'incident.resolved', 'incident.closed'] as $e) {
            $this->assertContains($e, $emits('incidents'), "الحدث {$e} غير معرَّف");
        }
        foreach (['change.approved', 'change.executed', 'change.rolled_back'] as $e) {
            $this->assertContains($e, $emits('changes'), "الحدث {$e} غير معرَّف");
        }
        $this->assertContains('deploy.rolled_back', $emits('deploys'),
            'التراجع عن نشرٍ كان بلا حدثٍ بينما لفشله حدثه — تناقض داخلي');

        // وكل حالةٍ في محفّزات هذه الأحداث عضوٌ في خيارات وحدتها
        foreach (['incidents', 'changes', 'deploys'] as $m) {
            $opts = collect(hub_mod($m)['fields'])->firstWhere('key', 'status')['options'] ?? [];
            foreach ($events[$m] as $ev) {
                foreach ((array) ($ev['to'] ?? []) as $state) {
                    $this->assertContains($state, $opts, "الحالة «{$state}» في حدث {$m} خارج خيارات الوحدة");
                }
            }
        }
    }

    public function test_open_incidents_lower_infrastructure_health(): void
    {
        $this->seedCore();
        $before = hub_health(true)['البنية التحتية']['score'];

        Incident::create(['title' => 'انقطاع الخدمة', 'severity' => 'حرج', 'status' => 'مفتوح',
            'started_at' => now()->subHours(2)]);

        $after = hub_health(true)['البنية التحتية'];
        $this->assertLessThan($before, $after['score'],
            'الحادثة المفتوحة تخفض درجة البنية التحتية — كانت الوحدة كلها خارج التقييم');
        $this->assertStringContainsString('حادثة مفتوحة', $after['note']);
    }

    public function test_app_quality_reports_mttr(): void
    {
        $this->seedCore();
        $app = Application::create(['name' => 'تطبيق المؤشر', 'status' => 'منشور']);
        Incident::create(['title' => 'عطل ١', 'app_id' => $app->id, 'status' => 'مغلق بتقرير',
            'severity' => 'عالٍ', 'downtime_min' => 30]);
        Incident::create(['title' => 'عطل ٢', 'app_id' => $app->id, 'status' => 'مغلق بتقرير',
            'severity' => 'عالٍ', 'downtime_min' => 90]);

        $q = collect(hub_app_quality(true))->firstWhere('id', $app->id);
        $this->assertSame(2, $q['incidents']);
        $this->assertSame(60, $q['mttr'], 'متوسط زمن التعافي يُحسب من التعطل المسجَّل');

        $this->actingAs($this->owner)->get('/app-quality')
            ->assertOk()->assertSee('متوسط التعافي');
    }

    public function test_archived_automations_module_warns_and_leaves_nav(): void
    {
        $this->seedCore();

        // اللافتة تقول الحقيقة: القواعد هنا لا تُنفَّذ
        $this->actingAs($this->owner)->get('/m/autos')
            ->assertOk()->assertSee('مؤرشفة')->assertSee('لا تُنفَّذ قواعدها');

        // وخرجت من التنقل فلا يبني أحدٌ قواعد ميتة جديدة
        $inNav = collect(config('hub_nav'))->contains(fn ($g) => in_array('autos', $g['items'], true));
        $this->assertFalse($inNav, 'الوحدة المؤرشفة لا تُعرض في التنقل');

        // لكنها تبقى متاحة بالرابط وسجلاتها سليمة (الإضافة لا الكسر)
        $this->assertNotNull(hub_mod('autos'));
    }
}
