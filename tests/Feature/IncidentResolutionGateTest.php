<?php

namespace Tests\Feature;

use App\Models\Incident;
use Tests\TestCase;

/**
 * (WP-6.1) بوّابةُ الإغلاق (§8.5) — «مغلق بتقرير» لحادثةٍ حرجة/عالية بلا
 * ثلاثيّةِ التقرير (السبب الجذري + خطوات المعالجة + الوقاية) ليست إغلاقاً
 * بل دفناً. تُفرَض عبر مفتاح `requires` في سجلّ الوحدة — محرّكٌ واحد يقرؤه
 * السحبُ والتحديثُ والـAPI والجماعي، لا فرعَ لكل وحدةٍ في متحكّم.
 *
 * والشدّةُ المنخفضة تمرّ بلا تقرير: بوّابةٌ تُثقل ما لا يلزم تُلتَفّ كلُّها.
 */
class IncidentResolutionGateTest extends TestCase
{
    protected function incident(array $extra = []): Incident
    {
        return Incident::create(array_merge([
            'title' => 'انقطاع خدمة الفوترة',
            'severity' => 'حرج',
            'status' => 'مفتوح',
        ], $extra));
    }

    protected function h(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiToken($this->owner)];
    }

    /** السحبُ في الكانبان لا يلتفّ على البوّابة */
    public function test_drag_close_without_a_report_is_refused_for_critical(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->actingAs($this->owner)
            ->post("/m/incidents/{$i->id}/status", ['status' => 'مغلق بتقرير'])
            ->assertStatus(422);
        $this->assertSame('مفتوح', $i->fresh()->status, 'أُغلقت حادثةٌ حرجة بلا تقرير');

        // وبالثلاثيّة كاملةً يمرّ الإغلاق نفسُه
        $i->forceFill(['root_cause' => 'شهادة TLS منتهية', 'steps' => 'جُدّدت الشهادة',
            'prevention' => 'تنبيهٌ قبل الانتهاء بثلاثين يوماً'])->save();
        $this->actingAs($this->owner)
            ->post("/m/incidents/{$i->id}/status", ['status' => 'مغلق بتقرير'])
            ->assertOk();
        $this->assertSame('مغلق بتقرير', $i->fresh()->status);
    }

    /** التحديثُ من النموذج يمرّ بالبوّابة نفسِها */
    public function test_update_close_without_a_report_is_refused_for_critical(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->actingAs($this->owner)->put("/m/incidents/{$i->id}", [
            'title' => 'انقطاع خدمة الفوترة', 'severity' => 'حرج', 'status' => 'مغلق بتقرير',
        ])->assertStatus(422);
        $this->assertSame('مفتوح', $i->fresh()->status);

        $this->actingAs($this->owner)->put("/m/incidents/{$i->id}", [
            'title' => 'انقطاع خدمة الفوترة', 'severity' => 'حرج', 'status' => 'مغلق بتقرير',
            'rootCause' => 'شهادة TLS منتهية', 'steps' => 'جُدّدت الشهادة',
            'prevention' => 'تنبيهٌ قبل الانتهاء بثلاثين يوماً',
        ])->assertRedirect();
        $this->assertSame('مغلق بتقرير', $i->fresh()->status);
    }

    /** والـAPI يرث المحرّك — لا بابَ خلفيّاً للتكاملات */
    public function test_api_close_without_a_report_is_refused_for_critical(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->withHeaders($this->h())->putJson("/api/v1/incidents/{$i->id}", [
            'title' => 'انقطاع خدمة الفوترة', 'severity' => 'حرج', 'status' => 'مغلق بتقرير',
        ])->assertStatus(422);
        $this->assertSame('مفتوح', $i->fresh()->status);

        $this->withHeaders($this->h())->putJson("/api/v1/incidents/{$i->id}", [
            'title' => 'انقطاع خدمة الفوترة', 'severity' => 'حرج', 'status' => 'مغلق بتقرير',
            'rootCause' => 'شهادة TLS منتهية', 'steps' => 'جُدّدت الشهادة',
            'prevention' => 'تنبيهٌ قبل الانتهاء بثلاثين يوماً',
        ])->assertOk();
        $this->assertSame('مغلق بتقرير', $i->fresh()->status);
    }

    /** الشدّةُ المنخفضة تُغلق بلا تقرير — البوّابة لمن تلزمه وحده */
    public function test_a_low_severity_incident_closes_without_a_report(): void
    {
        $this->seedCore();
        $i = $this->incident(['severity' => 'منخفض']);

        $this->actingAs($this->owner)
            ->post("/m/incidents/{$i->id}/status", ['status' => 'مغلق بتقرير'])
            ->assertOk();
        $this->assertSame('مغلق بتقرير', $i->fresh()->status);
    }

    /** الإجراءُ الجماعي لا يلتفّ: الرفضُ يُنسب للسجلّ ولا تُقطع الدفعة */
    public function test_bulk_close_without_a_report_is_refused_per_record(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->actingAs($this->owner)->from('/m/incidents')
            ->post('/m/incidents/bulk', ['do' => 'status', 'ids' => [$i->id], 'status' => 'مغلق بتقرير'])
            ->assertRedirect('/m/incidents');
        $this->assertSame('مفتوح', $i->fresh()->status, 'التفّ الإجراءُ الجماعي على بوّابة الإغلاق');
    }
}
