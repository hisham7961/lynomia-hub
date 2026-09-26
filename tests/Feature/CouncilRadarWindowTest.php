<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Platform\Settings;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مجلسُ الخبراء · N-6 — رادارٌ واحدٌ ونافذتان.**
 *
 * «ينتهي قريباً» شاشةٌ واحدةٌ وشارةٌ واحدة، وخلفَها **رقمان مختلفان**:
 *
 * | ما يُفحَص | إلى الأمام | إلى الخلف |
 * |---|---|---|
 * | حقولُ الوحدات (`hub_expiry`) | **+٣٠** يوماً | −٦٠ |
 * | وثائقُ السجلات (`hub_doc_expiry`) | **+٦٠** يوماً | −٦٠ |
 * | حقولُ صاحبِ الشأن (`hub_expiry_self_scan`) | **+٣٠** يوماً | −٦٠ |
 * | وثائقُ صاحبِ الشأن | **+٦٠** يوماً | −٦٠ |
 *
 * فعلى **السجلِّ الواحد** — موظّفٌ إقامتُه تنتهي بعد خمسةٍ وأربعين يوماً
 * ووثيقةُ إقامتِه تنتهي معها — يعرض الرادارُ **الوثيقةَ ويحجب الحقل**. ولا
 * يقول للقارئِ أيَّ نافذةٍ ينظر منها، فيقرأ «لا شيءَ قريب» وهو نصفُ جواب.
 *
 * وهذا من عائلةِ **«سؤالٌ واحدٌ · تعريفان»** التي يلاحقها المجلس: لا اختبارَ
 * يحمرّ، ولا خطأَ يظهر — الشاشةُ **صادقةٌ داخلياً** وناقصةٌ من حيث لا تدري.
 *
 * **القرار** (أُعلن صراحةً لمراجعةِ المالك): نافذةٌ **واحدة** من إعدادٍ واحد
 * `radar.window_days`، افتراضُها **٦٠** — أوسعُ الرقمين — كي **لا يضيق أيُّ
 * تحذيرٍ قائم**؛ والتضييقُ إن أُريد فبإرادةِ المنشأةِ من الإعدادات لا صدفةً
 * في الشيفرة.
 */
class CouncilRadarWindowTest extends TestCase
{
    /** موظّفٌ بحقلِ انتهاءٍ ووثيقةِ انتهاءٍ في اليومِ نفسِه — على السجلِّ الواحد */
    protected function employeeExpiringIn(int $days, string $name): Employee
    {
        $e = Employee::create(['name' => $name, 'status' => 'نشط',
            'iqama_exp' => now()->addDays($days)->toDateString()]);
        Attachment::create(['module' => 'hr', 'record_id' => $e->id, 'kind' => 'iqama',
            'disk' => 'local', 'path' => 'hub/' . Str::random(8), 'original_name' => 'iqama.pdf',
            'expires_at' => now()->addDays($days)->toDateString()]);

        return $e;
    }

    /** صفوفُ الرادارِ المخصوصةُ بهذا السجل، مفصولةً: حقلٌ أم وثيقة */
    protected function radarFor(User $u, string $id): array
    {
        $rows = collect(hub_expiry(true, $u))->where('module', 'hr')->where('id', (string) $id);

        return [
            'fields' => $rows->filter(fn ($r) => empty($r['doc']))->pluck('flabel')->values()->all(),
            'docs'   => $rows->filter(fn ($r) => ! empty($r['doc']))->pluck('flabel')->values()->all(),
        ];
    }

    // ═══════════ ١ · السجلُّ الواحدُ يُقاس بمسطرتَين ═══════════

    public function test_one_record_is_not_measured_by_two_windows(): void
    {
        $this->seedCore();
        $e = $this->employeeExpiringIn(45, 'سعدُ الدوسري');

        $this->actingAs($this->owner);
        ['fields' => $fields, 'docs' => $docs] = $this->radarFor($this->owner, $e->id);

        $this->assertNotEmpty($docs,
            'تهيئةٌ خاطئة: وثيقةُ الـ٤٥ يوماً لم تدخل الرادارَ أصلاً — فلا مقارنةَ');

        $this->assertNotEmpty($fields,
            'رادارٌ واحدٌ ونافذتان (N-6): على **السجلِّ نفسِه** وفي **اليومِ نفسِه** '
            . 'عُرضت الوثيقةُ (نافذةُ +٦٠) وحُجب الحقلُ (نافذةُ +٣٠). والقارئُ لا '
            . 'يعلم من أيِّ نافذةٍ ينظر، فيقرأ نصفَ جوابٍ ويحسبه كلَّه.');
    }

    // ═══════════ ٢ · النافذةُ مقبضٌ واحدٌ يحكم الاثنين ═══════════

    public function test_narrowing_the_window_narrows_both_halves_together(): void
    {
        $this->seedCore();
        Settings::put('radar.window_days', '20', 'test');

        $far  = $this->employeeExpiringIn(45, 'بعيدُ الأجل');
        $near = $this->employeeExpiringIn(10, 'قريبُ الأجل');

        $this->actingAs($this->owner);

        $farRows = $this->radarFor($this->owner, $far->id);
        $this->assertSame([], $farRows['fields'], 'نافذةُ ٢٠ يوماً لم تُطبَّق على الحقول');
        $this->assertSame([], $farRows['docs'],
            'نافذةُ ٢٠ يوماً لم تُطبَّق على الوثائق — فالمقبضُ يحكم نصفَ الرادارِ لا كلَّه');

        $nearRows = $this->radarFor($this->owner, $near->id);
        $this->assertNotEmpty($nearRows['fields'], 'حقلٌ داخلَ النافذةِ سقط');
        $this->assertNotEmpty($nearRows['docs'], 'وثيقةٌ داخلَ النافذةِ سقطت');
    }

    public function test_widening_the_window_widens_both_halves_together(): void
    {
        $this->seedCore();
        Settings::put('radar.window_days', '90', 'test');
        $e = $this->employeeExpiringIn(80, 'أبعدُ من الافتراض');

        $this->actingAs($this->owner);
        ['fields' => $fields, 'docs' => $docs] = $this->radarFor($this->owner, $e->id);

        $this->assertNotEmpty($fields, 'التوسيعُ لم يبلغ الحقول');
        $this->assertNotEmpty($docs, 'التوسيعُ لم يبلغ الوثائق');
    }

    // ═══════════ ٣ · وصاحبُ الشأنِ ينظر من النافذةِ نفسِها ═══════════

    public function test_the_subject_scan_reads_the_same_window(): void
    {
        $this->seedCore();
        $role = Role::create(['name' => 'موظّف' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'ريمُ الفهد', 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'ريمُ الفهد', 'status' => 'نشط', 'user_id' => $u->id,
            'iqama_exp' => now()->addDays(45)->toDateString()]);

        $this->assertFalse(hub_can($u, 'hr', 'v'), 'تهيئةٌ خاطئة: تملك `hr:v` فتمرّ بالمسحِ العامّ');

        $this->actingAs($u);
        $mine = collect(hub_expiry(true, $u))->where('id', (string) $e->id)
            ->filter(fn ($r) => empty($r['doc']))->values();

        $this->assertNotEmpty($mine,
            'إقامةُ صاحبِ الشأنِ بعد ٤٥ يوماً محجوبةٌ عنه بنافذةِ +٣٠ بينما وثيقتُها '
            . 'تُعرض له بنافذةِ +٦٠ — نصفُ الاستثناءِ على نافذةٍ ونصفُه على أخرى.');
    }
}
