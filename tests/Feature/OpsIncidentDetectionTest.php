<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Support\AlertEngine;
use App\Support\Health;
use App\Support\HubEvents;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **كشفُ الحوادث التشغيلية** (WP-6.3 · §3.9): بصماتُ `ops:*` تفتح حادثةً واحدةً
 * (تكرارٌ بالبصمة لا بنصّ العنوان) عبر `hub_open_incident` — فتمرّ بالناقل وتعمل
 * المساراتُ المبذورة. والشفاءُ **قيدُ «تعافت الخدمة» لا إغلاقٌ صامت** (§13)،
 * وعدّادُ الحوادث الأمنية لا يبتلع التشغيلية.
 */
class OpsIncidentDetectionTest extends TestCase
{
    protected function tearDown(): void
    {
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    /** صحّةٌ مصطنعة: مجدولةُ الصادر ميتة */
    protected function deadScheduler(): array
    {
        return ['components' => ['scheduler' => ['status' => Health::UNAVAILABLE, 'why' => 'x', 'data' => [
            'jobs' => ['outbox' => ['label' => 'عامل التسليم (كل ٥ دقائق)', 'status' => Health::UNAVAILABLE, 'age_min' => 3000]],
        ]]]];
    }

    protected function liveScheduler(): array
    {
        return ['components' => ['scheduler' => ['status' => Health::HEALTHY, 'why' => 'ok', 'data' => [
            'jobs' => ['outbox' => ['label' => 'عامل التسليم (كل ٥ دقائق)', 'status' => Health::HEALTHY, 'age_min' => 1]],
        ]]]];
    }

    public function test_each_fingerprint_opens_one_incident_and_appends_evidence(): void
    {
        $this->seedCore();
        $engine = new AlertEngine();

        $engine->evaluate($this->deadScheduler());
        $engine->evaluate($this->deadScheduler());

        $rows = Incident::whereNull('deleted_at')->get()
            ->filter(fn ($i) => ($i->meta['fingerprint'] ?? null) === 'ops:scheduler_dead:outbox')->values();
        $this->assertCount(1, $rows, 'البصمةُ الواحدة فتحت أكثرَ من حادثة — التكرارُ بالعنوان لا بالبصمة؟');
        $i = $rows->first();
        $this->assertSame('ops', $i->meta['kind'] ?? null);
        $this->assertSame('ops', (string) $i->kind, 'عمودُ kind لم يُملأ للحوادث التشغيلية');
        $this->assertCount(2, (array) ($i->meta['events'] ?? []), 'التشغيلةُ الثانية لم تُلحِق دليلاً');
        $this->assertSame('مفتوح', $i->status);
    }

    /** بصمتان مختلفتان بعنوانٍ واحد = حادثتان؛ وبصمةٌ واحدة بعنوانين = حادثة */
    public function test_dedup_is_by_fingerprint_not_title(): void
    {
        $this->seedCore();

        hub_open_incident('انقطاع خدمة', 'عالي', 'ops', 'ops:dep_failed:odoo');
        hub_open_incident('انقطاع خدمة', 'عالي', 'ops', 'ops:dep_failed:n8n');
        $this->assertSame(2, Incident::whereNull('deleted_at')->count(),
            'بصمتان مختلفتان دُمجتا لتطابق العنوان');

        hub_open_incident('انقطاع خدمة — تفاصيل أدقّ', 'عالي', 'ops', 'ops:dep_failed:odoo');
        $this->assertSame(2, Incident::whereNull('deleted_at')->count(),
            'البصمةُ الواحدة فتحت ثانيةً لاختلاف العنوان');
    }

    /** الشفاء: قيدُ «تعافت الخدمة» مرّةً واحدة — والحادثةُ تبقى مفتوحةً لقرار الإنسان */
    public function test_recovery_appends_a_note_once_and_never_auto_closes(): void
    {
        $this->seedCore();
        $engine = new AlertEngine();

        $engine->evaluate($this->deadScheduler());
        $engine->evaluate($this->liveScheduler());

        $i = Incident::whereNull('deleted_at')->get()
            ->first(fn ($x) => ($x->meta['fingerprint'] ?? null) === 'ops:scheduler_dead:outbox');
        $this->assertNotNull($i);
        $this->assertSame('مفتوح', $i->status, 'الحادثةُ أُغلقت آلياً — إغلاقُ الأدلّة قرارُ إنسان (§13)');
        $notes = array_values(array_filter((array) $i->meta['events'], fn ($e) => str_contains((string) ($e['note'] ?? ''), 'تعافت الخدمة')));
        $this->assertCount(1, $notes, 'قيدُ الشفاء غائبٌ أو مكرَّر');

        // تقييمٌ سليمٌ ثانٍ: لا قيدَ شفاءٍ ثانياً لنفس التعافي
        $engine->evaluate($this->liveScheduler());
        $i->refresh();
        $this->assertCount(1, array_filter((array) $i->meta['events'], fn ($e) => str_contains((string) ($e['note'] ?? ''), 'تعافت الخدمة')));

        // عاد العطلُ ثم شُفي: قيدُ شفاءٍ جديدٌ للنوبة الجديدة
        $engine->evaluate($this->deadScheduler());
        $engine->evaluate($this->liveScheduler());
        $i->refresh();
        $this->assertCount(2, array_filter((array) $i->meta['events'], fn ($e) => str_contains((string) ($e['note'] ?? ''), 'تعافت الخدمة')),
            'عودةُ العطل بعد الشفاء لم تُعِد سكّةَ قيد التعافي');
    }

    /** عدّادُ الحوادث الأمنية (نموذجُ الصحّة) لا يشمل التشغيلية */
    public function test_security_incident_counter_excludes_operational_kind(): void
    {
        $this->seedCore();

        hub_open_incident('قرصٌ حرج', 'حرج', 'ops', 'ops:disk_critical');
        $sec = Health::check()['components']['security'];
        $this->assertSame(0, (int) $sec['data']['open_security_incidents'],
            'حادثةٌ تشغيلية حُسبت أمنيةً — إنذارٌ كاذب في نموذج الصحّة');

        hub_security_incident('حادثة أمنية حقيقية', 'حرج');
        $sec = Health::check()['components']['security'];
        $this->assertSame(1, (int) $sec['data']['open_security_incidents']);
    }

    /** الحوادثُ الآليّة تمرّ بالناقل — فتعمل المساراتُ المبذورة («🚨 حادث حرج») */
    public function test_auto_incidents_dispatch_through_hub_events(): void
    {
        $this->seedCore();
        $seen = [];
        HubEvents::listen(function (string $e, string $mod) use (&$seen) { $seen[] = "$e:$mod"; });

        hub_open_incident('انقطاع مكشوف', 'حرج', 'ops', 'ops:db_unavailable');
        $this->assertContains('created:incidents', $seen,
            'الحادثةُ الآليّة لم تمرّ بالناقل — المساراتُ المبذورة عمياء عنها');

        // والغلافُ الأمنيّ القديم صار يمرّ به كذلك (كان يتجاوزه)
        $seen = [];
        hub_security_incident('حادثة أمنية عبر الغلاف', 'حرج');
        $this->assertContains('created:incidents', $seen);
    }

    /** فتحُ حادثةٍ من تنبيهٍ في المركز — تُربط ولا تتكرّر */
    public function test_open_incident_from_alert_links_and_does_not_duplicate(): void
    {
        $this->seedCore();
        $rule = \App\Models\AlertRule::create(['name' => 'قفل الطوارئ', 'mod' => '', 'field' => 'x',
            'op' => 'يساوي', 'status' => 'مفعّلة', 'source' => 'security.lockdown', 'window_min' => 5]);
        $this->hubSetting('security.lockdown', '1');
        (new AlertEngine())->evaluate(['components' => []]);
        $i = DB::table('alert_instances')->where('rule_id', $rule->id)->first();

        $this->actingAs($this->owner)->post("/admin/alerts/{$i->id}/incident")->assertRedirect();
        $incId = DB::table('alert_instances')->where('id', $i->id)->value('incident_id');
        $this->assertNotNull($incId, 'الحادثةُ لم تُربط بالتنبيه');
        $this->assertSame('security', (string) Incident::find($incId)->kind);

        // نقرةٌ ثانية لا تفتح ثانية
        $this->actingAs($this->owner)->post("/admin/alerts/{$i->id}/incident")->assertRedirect();
        $this->assertSame(1, Incident::whereNull('deleted_at')->count());
    }
}
