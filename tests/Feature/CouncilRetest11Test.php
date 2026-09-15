<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\DailyWorkCompliance;
use App\Support\Workday;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * التحقّقُ المستقلّ الحادي عشر — **الجوابُ الواحدُ نفسُه كان قرعة**.
 *
 * `openRow()` بُني على `Workday::today()` الذي يقرأ **صفّاً واحداً** من اليوم
 * بـ`->orderBy('id')->first()` — و`attendance.id` هو `char(36)` **عشوائيّ**.
 * فما إن يحمل اليومُ صفَّين حتى ينهار «الجوابُ الواحد» إلى جوابَين: الفرديُّ
 * يقول «لا ورديّة» والجماعيُّ يقول «مفتوحة»، ويعود **الضررُ الأصليُّ** — ورديّةٌ
 * تُهجَر بلا انصرافٍ أبداً — قابلاً للإنتاجِ بضغطةٍ واحدة.
 *
 * ومعه `$primary` في `compose()` — **على بُعدِ سطرين** من `$outRow` الذي طُهّر
 * في v2.515 — يقرّر بالقرعةِ نفسِها حالةَ اليومِ ووسمَ «مدّةٌ غير صالحة» في
 * **الكشفِ الذي يغذّي الرواتب**.
 *
 * وثالثةٌ من صنعي: استثناءُ `custody` في v2.515 اختار **وحدةً لا وجودَ لها**
 * (‏`hub_mod('custody') = null`؛ والحارسُ الحقيقيُّ `assets`)، فصار البابُ
 * **مغلقاً بلا سبيلِ منحٍ** — نزعُ قدرةٍ لا إصلاح.
 */
class CouncilRetest11Test extends TestCase
{
    private function member(string $email): array
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => [],
            'matrix' => ['attend' => ['v' => 1, 'a' => 1, 'e' => 1]], 'companies' => null]);
        $u = User::create(['name' => 'موظّف', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => 'موظّفُ ' . $email, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    /** صفوفٌ بمعرّفاتٍ مضبوطةِ الترتيب — النموذجُ يسكُّ معرّفَه فلا يصلح هنا */
    private function rawRow(string $empId, string $prefix, string $date, string $in, ?string $out,
                            bool $ov = false, string $status = 'حاضر'): void
    {
        DB::table('attendance')->insert([
            'id' => $prefix . substr((string) Str::uuid(), strlen($prefix)),
            'emp_id' => $empId, 'date' => $date, 'time_in' => $in, 'time_out' => $out,
            'overnight' => $ov, 'status' => $status,
            'in_at' => $date . ' ' . $in,
            'out_at' => $out ? (($ov ? Carbon::parse($date)->addDay()->toDateString() : $date) . ' ' . $out) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ ١ — الفرديُّ والجماعيُّ يتّفقان مهما كان ترتيبُ المعرّفات ═══════════ */

    public function test_open_row_agrees_with_the_batch_answer_whatever_the_uuid_order(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));

        foreach ([['0000', 'ffff'], ['ffff', '0000']] as $i => [$closedPrefix, $openPrefix]) {
            [$u, $e] = $this->member("pair{$i}@test.local");
            // يومُ أمسٍ بصفَّين: نهاريٌّ **مغلق** وليليٌّ **مفتوح**
            $this->rawRow($e->id, $closedPrefix, '2026-09-14', '08:00:00', '17:00:00');
            $this->rawRow($e->id, $openPrefix, '2026-09-14', '23:15:00', null);

            $single = Workday::openRow($e->id);
            $batch = Workday::openCrossingByEmp([$e->id]);

            $this->assertNotNull($single,
                'الجوابُ الفرديُّ لا يرى الورديّةَ المفتوحةَ — قرعةُ معرّفٍ قرّرت');
            $this->assertSame('23:15:00', (string) $single->time_in);
            $this->assertArrayHasKey((string) $e->id, $batch);
            $this->assertSame((string) $single->id, (string) $batch[(string) $e->id]->id,
                'الفرديُّ والجماعيُّ يشيران إلى صفَّين مختلفَين — تعريفان لا واحد');
        }
    }

    public function test_check_in_is_refused_when_a_second_row_hides_the_open_shift(): void
    {
        $this->seedCore();
        [$u, $e] = $this->member('hidden-shift@test.local');
        $this->rawRow($e->id, '0000', '2026-09-14', '08:00:00', '17:00:00');
        $this->rawRow($e->id, 'ffff', '2026-09-14', '23:15:00', null);

        Carbon::setTestNow(Carbon::parse('2026-09-15 03:48:00'));
        $in = Workday::checkIn($u, ['mode' => 'مكتب']);

        $this->assertFalse($in['ok'] ?? true,
            'فُتح صفٌّ ثالثٌ والورديّةُ مفتوحة — تُهجَر بلا انصرافٍ أبداً');
        $this->assertSame(2, Attendance::where('emp_id', $e->id)->count());
    }

    /* ═══════════ ٢ — حالةُ اليومِ لا تتقرّر بقرعةِ معرّف ═══════════ */

    public function test_the_day_state_does_not_flip_with_the_uuid_prefix(): void
    {
        $this->seedCore();
        $seen = [];

        foreach ([['aaaa', 'zzzz'], ['zzzz', 'aaaa']] as $i => [$dayPrefix, $nightPrefix]) {
            [$u, $e] = $this->member("state{$i}@test.local");
            // نهاريّةٌ **في وقتها** (حاضر) وليليّةٌ **متأخّرة** — فالقرعةُ تُغيّر الوسم
            $this->rawRow($e->id, $dayPrefix, '2026-09-14', '08:00:00', '17:00:00');
            $this->rawRow($e->id, $nightPrefix, '2026-09-14', '23:00:00', '03:48:00', true, 'متأخر');

            $c = DailyWorkCompliance::resolve($e, '2026-09-14');
            $seen[] = [(string) $c['physical'], (string) ($c['labels']['effective'] ?? '')];
        }

        $this->assertSame($seen[0], $seen[1],
            'حالةُ اليومِ تنقلب بتغيّرِ بادئةِ UUID وحدَها — وهذا الكشفُ يغذّي الرواتب. '
            . 'الأوّل: ' . implode(' · ', $seen[0]) . ' | الثاني: ' . implode(' · ', $seen[1]));
    }

    /* ═══════════ ٣ — مفتاحُ العهدة يفتح بابَه فعلاً ═══════════ */

    public function test_the_custody_night_exemption_names_a_module_that_exists(): void
    {
        $ref = new \ReflectionClass(\App\Http\Middleware\WorkHours::class);
        $exempt = (array) $ref->getConstant('NIGHT_EXEMPT');
        $catalog = (array) (config('hub_permissions.exportNight.modules') ?? []);

        foreach (['custody.label', 'custody.spec', 'custody.permit.doc'] as $name) {
            $mod = $exempt[$name] ?? null;
            $this->assertNotNull($mod, "«{$name}» محروسٌ بلا خريطةِ استثناء");
            $this->assertNotNull(hub_mod($mod),
                "خريطةُ «{$name}» تشير إلى وحدةٍ «{$mod}» لا وجودَ لها — مفتاحٌ لا يُمنَح، "
                . 'فالبابُ مغلقٌ بلا سبيل: نزعُ قدرةٍ لا إصلاح');
            $this->assertContains($mod, $catalog,
                "وحدةُ «{$mod}» غيرُ معروضةٍ في كتالوجِ exportNight فلا تُمنَح من شاشةِ الأدوار");
        }
    }
}
