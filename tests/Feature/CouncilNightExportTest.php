<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مجلسُ الخبراء · بابانِ لملفٍّ واحد — «سؤالٌ واحدٌ · تعريفان» على حظرِ التصديرِ الليليّ.
 *
 * حظرُ نقلِ الملفاتِ خارجَ الدوام (`sec.strict_files`) يحرسُ بابَ تصديرِ الوحدات
 * (`m.export` — في `FILE_ROUTES` وفي `exportBelt` معاً)، ويتركُ بابَ CSV الشهريِّ
 * (`reports.monthly.export`) مفتوحاً على مصراعيه: تعليقُ الدالّةِ نفسُه يعدّ ثلاثةً
 * من أحزمةِ التصدير (تجميدُ الطوارئ · تصعيدُ الحجم · بصمةُ التدقيق) ويسكتُ عن الرابع.
 *
 * وقد أُثبِت حيّاً على بيئةِ العرضِ الساعةَ 03:19 بتوقيت الكويت (خارجَ الدوام):
 *   GET /m/hr/export                                → 403 «نقل الملفات ممنوع خارج وقت العمل»
 *   GET /reports/monthly/export?month=2026-08       → 200 · text/csv · 7346 بايت
 *   GET /reports/monthly/export?…&mode=payroll      → 200 · text/csv · وفيه عمودُ «الراتب الأساسي»
 *
 * فالضابطُ الذي وُجد ليمنعَ سحبَ البياناتِ ليلاً يمنعُ كشفَ وحدةٍ ويُسلّمُ كشفَ الرواتب.
 */
class CouncilNightExportTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => $matrix, 'companies' => null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** تثبيتُ الساعةِ خارجَ الدوام (بعد strict_from الافتراضيّ 17:00) */
    private function night(): void
    {
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 22:30:00'));
    }

    /** تثبيتُ الساعةِ داخلَ الدوام */
    private function day(): void
    {
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /* ═══════════ 1 — البابانِ يتّفقان: كلاهما مغلقٌ ليلاً ═══════════ */

    public function test_monthly_csv_is_blocked_at_night_exactly_like_module_export(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->night();

        // بابُ الوحدات — القرارُ القائمُ الذي نقيسُ عليه
        $modExporter = $this->user('mod@test.local', ['hr' => ['v' => 1, 'export' => 1]]);
        $this->actingAs($modExporter)->get(route('m.export', ['module' => 'hr']))
            ->assertStatus(403);

        // بابُ CSV الشهريّ — نفسُ الساعةِ ونفسُ المفتاح
        $acc = $this->user('acc@test.local', ['attend' => ['v' => 1], 'hr' => ['v' => 1]]);
        $res = $this->actingAs($acc)->get(route('reports.monthly.export'));
        $res->assertStatus(403);
        $this->assertStringContainsString('خارج وقت العمل', $res->exception?->getMessage() ?? '',
            'الردُّ يقولُ للمحاسبِ سببَه — لا ٤٠٣ عارياً');

        // ووضعُ كشفِ الرواتب (وفيه الراتبُ الأساسيّ) مغلقٌ كذلك
        $this->actingAs($acc)->get(route('reports.monthly.export', ['mode' => 'payroll']))
            ->assertStatus(403);
    }

    /* ═══════════ 2 — مفتاحُ exportNight يفتحُ البابَ ويُوسَم ═══════════ */

    public function test_exportnight_key_exempts_the_monthly_csv_and_tags_the_audit(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->night();

        $night = $this->user('n-attend@test.local', ['attend' => ['v' => 1, 'exportNight' => 1]]);
        $this->actingAs($night)->get(route('reports.monthly.export'))->assertOk();

        $this->assertTrue(
            DB::table('audits')->where('module', 'attend')->where('action', 'like', '%خارج الدوام%')->exists(),
            'استعمالُ استثناءِ exportNight على الكشفِ الشهريِّ بلا قيدِ تدقيق'
        );

        // والمفتاحُ على `hr` يفتحُ البابَ نفسَه — بوّابةُ العرضِ تقبلُ الاثنين فكذلك الاستثناء
        $nightHr = $this->user('n-hr@test.local', ['hr' => ['v' => 1, 'exportNight' => 1]]);
        $this->actingAs($nightHr)->get(route('reports.monthly.export'))->assertOk();
    }

    /* ═══════════ 3 — لا قدرةَ تُنتزع: المالكُ ومفاتيحُ الإطفاء ═══════════ */

    public function test_owner_and_the_off_switches_keep_the_monthly_csv_open_at_night(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->night();

        // المالكُ مستثنًى في الوسيطِ فيُستثنى هنا — لا يفترقُ القراران
        $this->actingAs($this->owner)->get(route('reports.monthly.export'))->assertOk();

        $acc = $this->user('acc3@test.local', ['attend' => ['v' => 1]]);

        // إطفاءُ نظامِ الساعاتِ كلِّه
        $this->hubSetting('sec.hours_on', '0');
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk();

        // أو إطفاءُ حظرِ الملفاتِ وحدَه
        $this->hubSetting('sec.hours_on', '1');
        $this->hubSetting('sec.strict_files', '0');
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk();
    }

    /* ═══════════ 4 — النهارُ كما كان، بلا وسمٍ ليليّ ═══════════ */

    public function test_daytime_monthly_csv_is_unchanged(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->day();

        $acc = $this->user('acc4@test.local', ['attend' => ['v' => 1]]);
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk();

        $this->assertFalse(
            DB::table('audits')->where('action', 'like', '%خارج الدوام%')->exists(),
            'تصديرٌ نهاريٌّ لا يُوسَم «خارج الدوام»'
        );
    }

    /* ═══════════ 4٫٥ — قائمةُ المساراتِ المحروسةِ لا تصدأ ═══════════ */

    public function test_every_guarded_file_route_still_exists(): void
    {
        // قائمةٌ يدويّةٌ باسمِ مسار: يومَ يُعاد تسميةُ مسارٍ تصيرُ الحراسةُ اسماً
        // لا يحرسُ شيئاً — **بصمتٍ تامّ**. هذا الاختبارُ يجعلُ الصدأَ مرئيّاً.
        $ref = new \ReflectionClass(\App\Http\Middleware\WorkHours::class);
        $names = $ref->getConstant('FILE_ROUTES');
        $this->assertNotEmpty($names, 'قائمةُ المساراتِ المحروسةِ فارغة');

        $router = app('router')->getRoutes();
        foreach ($names as $n) {
            $this->assertNotNull($router->getByName($n),
                "المسارُ «{$n}» في قائمةِ حظرِ نقلِ الملفات لم يعد موجوداً — حراسةٌ على اسمٍ ميّت");
        }
    }

    /* ═══════════ 4٫٦ — الشاشةُ تقولُ السببَ قبل النقر، لا بعده ═══════════ */

    public function test_the_screen_and_the_guard_give_the_same_answer(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->night();

        // ممنوعٌ: الشاشةُ تُنذرُ قبل النقر، والحارسُ يردُّ بعده — جوابٌ واحد
        $acc = $this->user('scr-acc@test.local', ['attend' => ['v' => 1]]);
        $html = $this->actingAs($acc)->get(route('reports.monthly'))->assertOk()->getContent();
        $this->assertStringContainsString('نقل الملفات ممنوع خارج وقت العمل', $html,
            'زرُّ التصديرِ الممنوعُ ليلاً بلا سببٍ مكتوبٍ — نقرةٌ تنتهي بـ٤٠٣ عارٍ');
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertStatus(403);

        // ومسموحٌ: لا إنذارَ على الشاشة، ولا منعَ عند الحارس
        $night = $this->user('scr-night@test.local', ['attend' => ['v' => 1, 'exportNight' => 1]]);
        $html2 = $this->actingAs($night)->get(route('reports.monthly'))->assertOk()->getContent();
        $this->assertStringNotContainsString('نقل الملفات ممنوع خارج وقت العمل', $html2,
            'حاملُ المفتاحِ يُنذَرُ بمنعٍ لا يقعُ عليه');
        $this->actingAs($night)->get(route('reports.monthly.export'))->assertOk();

        // وفي النهارِ لا إنذارَ لأحد
        $this->day();
        $html3 = $this->actingAs($acc)->get(route('reports.monthly'))->assertOk()->getContent();
        $this->assertStringNotContainsString('نقل الملفات ممنوع خارج وقت العمل', $html3);
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk();
    }

    /* ═══════════ 5 — تعريفٌ واحدٌ للّيل: البابانِ يتحرّكانِ معاً على الحدّ ═══════════ */

    public function test_both_csv_doors_share_one_definition_of_night(): void
    {
        $this->seedCore();
        $this->hubSetting('sec.hours_on', '1');
        $this->hubSetting('sec.strict_from', '20:00');
        $this->hubSetting('sec.hours_start', '08:00');

        $mod = $this->user('p-mod@test.local', ['hr' => ['v' => 1, 'export' => 1]]);
        $acc = $this->user('p-acc@test.local', ['attend' => ['v' => 1]]);

        // دقيقةٌ قبل الحدّ: البابانِ مفتوحان
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 19:59:00'));
        $this->actingAs($mod)->get(route('m.export', ['module' => 'hr']))->assertOk();
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk();

        // وعلى الحدِّ تماماً: البابانِ مغلقان
        Carbon::setTestNow(Carbon::parse(now()->toDateString() . ' 20:00:00'));
        $this->actingAs($mod)->get(route('m.export', ['module' => 'hr']))->assertStatus(403);
        $this->actingAs($acc)->get(route('reports.monthly.export'))->assertStatus(403);
    }
}
