<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * **العدّادُ الحيّ لمدّة العمل (بطاقةُ «يومي»).**
 *
 * عند تسجيل الحضور يبدأ مؤقّتٌ حيٌّ يعدُّ صعوداً منذ لحظةِ البداية. الأساسُ (الثواني
 * المنقضية) يُحسَبُ **خادميّاً** لحظةَ العرض فيعدُّ المتصفّحُ منه — لا حسابَ منطقةٍ زمنيّةٍ
 * على العميل. يظهرُ ما دام حاضراً ولم ينصرف؛ عند الانصراف تحلُّ «ساعاتُ اليوم» محلَّه.
 */
class WorkdayLiveTimerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function render(Attendance $att, array $extra = []): string
    {
        return view('partials.widgets.checkin', ['data' => array_merge([
            'att' => $att, 'entries' => 0, 'hours' => 0, 'projects' => [], 'clients' => [],
        ], $extra)])->render();
    }

    public function test_live_timer_seeds_server_elapsed_seconds_while_checked_in(): void
    {
        $this->seedCore();
        // الآنُ مثبَّتٌ 12:00، والحضورُ 08:00 ⇒ أربعُ ساعاتٍ = 14400 ثانية بالضبط
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00', config('app.timezone')));
        $att = Attendance::make(['date' => '2026-09-10', 'time_in' => '08:00', 'status' => 'حاضر', 'mode' => 'مكتب']);

        $html = $this->render($att);
        $this->assertStringContainsString('id="wd-live-timer"', $html, 'عنصرُ العدّادِ الحيّ حاضر');
        $this->assertStringContainsString('data-elapsed="14400"', $html, 'الأساسُ = الثواني المنقضيةُ خادميّاً (4 ساعات)');
    }

    public function test_live_timer_absent_after_checkout(): void
    {
        $this->seedCore();
        Carbon::setTestNow(Carbon::parse('2026-09-10 17:00:00', config('app.timezone')));
        $att = Attendance::make(['date' => '2026-09-10', 'time_in' => '08:00', 'time_out' => '16:00',
            'status' => 'حاضر', 'hours' => 8]);

        $html = $this->render($att);
        $this->assertStringNotContainsString('wd-live-timer', $html, 'لا عدّادَ حيّاً بعد الانصراف');
        $this->assertStringContainsString('ساعات اليوم', $html, 'تحلُّ ساعاتُ اليوم محلَّه');
    }

    public function test_live_timer_absent_before_check_in(): void
    {
        $this->seedCore();
        // لا صفَّ حضورٍ بعد ⇒ نموذجُ التسجيل، بلا عدّاد
        $att = Attendance::make(['date' => '2026-09-10', 'status' => 'حاضر']);   // بلا time_in
        $html = $this->render($att);
        $this->assertStringNotContainsString('wd-live-timer', $html);
        $this->assertStringContainsString('تسجيل الحضور', $html, 'يظهرُ زرُّ بدءِ العمل');
    }
}
