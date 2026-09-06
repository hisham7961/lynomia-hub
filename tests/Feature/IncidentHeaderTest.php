<?php

namespace Tests\Feature;

use App\Models\Incident;
use Tests\TestCase;

/**
 * (WP-6.1) رأسُ الحادثة — غرفةُ قيادةٍ لا سجلُّ حقول:
 *
 * — **البطاقة** تعرض: الشدّة والحالة والقائد وبدأت وكُشفت وأُقرّت وحُلّت والمدّة.
 * — **المدّة** = `resolved_at - started_at` بالدقائق، وبسقوطٍ إلى `downtime_min`
 *   — **نفسُ وحدة** MTTR في `hub_app_quality` (متوسط `downtime_min` بالدقائق)
 *   كي لا تقول شاشتان رقمين مختلفين عن الحادثة نفسها.
 * — **بلا قائد** تُقال صراحةً: حادثةٌ لا قائد لها لا تتظاهر بأنها مُدارة.
 * — **عمودُ `kind`** مرآةُ `meta.kind`: يُلغي مسحَ `LIKE '%"kind":"security"%'`
 *   المتكرّر، والصفوفُ القديمة (عمودُها فارغ) تبقى معدودةً بسقوطٍ إلى meta.
 */
class IncidentHeaderTest extends TestCase
{
    protected function incident(array $extra = []): Incident
    {
        return Incident::create(array_merge([
            'title' => 'انقطاع بوابة الدفع',
            'severity' => 'حرج',
            'status' => 'مفتوح',
        ], $extra));
    }

    public function test_the_header_card_shows_the_incident_command_fields(): void
    {
        $this->seedCore();
        $i = $this->incident([
            'started_at' => '2026-09-01 10:00:00',
            'detected_at' => '2026-09-01 10:05:00',
            'resolved_at' => '2026-09-01 12:17:00',
            'lead_id' => $this->employee->id,
        ]);

        $res = $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")->assertOk();
        $res->assertSee('بدأت');
        $res->assertSee('كُشفت');
        $res->assertSee('حُلّت');
        // ١٠:٠٠ → ١٢:١٧ = ١٣٧ دقيقة — تُحسب من الطابعين لا من إدخالٍ يدوي
        // (الوسمُ data-ih-dur يمنع تطابقاً عرَضياً مع رقمٍ داخل UUID في الصفحة)
        $res->assertSee('data-ih-dur>137', false);
        // القائد بالاسم لا بالمعرّف
        $res->assertSee('موظفة');
    }

    /** لا طابعَ حلٍّ بعد؟ المدّةُ تسقط إلى `downtime_min` — تعريفُ MTTR نفسُه */
    public function test_the_duration_falls_back_to_the_recorded_downtime(): void
    {
        $this->seedCore();
        $i = $this->incident(['started_at' => '2026-09-01 10:00:00', 'downtime_min' => 45]);

        $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertSee('data-ih-dur>45', false);
    }

    /** حادثةٌ بلا قائدٍ تُقال كما هي — لا «أُقرّت» ولا صمت */
    public function test_an_incident_without_a_lead_is_labelled_honestly(): void
    {
        $this->seedCore();
        $i = $this->incident();

        $this->actingAs($this->owner)->get("/m/incidents/{$i->id}")
            ->assertOk()->assertSee('بلا قائد');
    }

    /** PIR (§8.6) للشدّتين العاليتين وحدهما — المنخفض لا يُثقَل بمراجعةٍ لا تلزمه */
    public function test_the_pir_section_appears_for_high_severities_only(): void
    {
        $this->seedCore();
        $hi = $this->incident(['severity' => 'حرج']);
        $lo = $this->incident(['title' => 'بطءٌ عابر', 'severity' => 'منخفض']);

        $this->actingAs($this->owner)->get("/m/incidents/{$hi->id}")
            ->assertOk()->assertSee('مراجعة ما بعد الحادثة');
        $this->actingAs($this->owner)->get("/m/incidents/{$lo->id}")
            ->assertOk()->assertDontSee('مراجعة ما بعد الحادثة');
    }

    /** عمودُ `kind` يملؤه الكاتب، والصفوفُ القديمة (meta فقط) تبقى معدودة */
    public function test_the_kind_column_is_mirrored_and_legacy_rows_are_still_counted(): void
    {
        $this->seedCore();

        $auto = hub_security_incident('محاولة تسلّل من عنوان محظور', 'حرج');
        $this->assertSame('security', $auto->kind, 'الكاتبُ الجديد لا يملأ عمود المرآة');
        $this->assertNotNull($auto->detected_at, 'حادثةٌ آليّة بلا وقتِ كشف');

        // صفٌّ قديم من قبل الهجرة: الوسمُ في meta وحدها والعمودُ فارغ
        $this->incident(['title' => 'حادثة قديمة قبل الترحيل',
            'kind' => null, 'meta' => ['kind' => 'security']]);

        $sec = (new \ReflectionMethod(\App\Support\Health::class, 'security'))->invoke(null);
        $this->assertSame(2, (int) ($sec['data']['open_security_incidents'] ?? -1),
            'القراءةُ الكسولة (العمود ثم meta) أسقطت صفوفاً — شاشةُ الصحة تكذب عن الحوادث المفتوحة');
    }
}
