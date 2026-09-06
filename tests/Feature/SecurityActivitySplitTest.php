<?php

namespace Tests\Feature;

use App\Support\Risk;
use App\Support\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-7.1 — فصلُ الأمن عن الإنتاجية (spec §5.1 · §46، الشرط الأخير):
 *  - الدرجةُ الأمنية معزولةٌ في `Risk::activity` ولا تقرؤها أيُّ شاشةِ أداء
 *    (فحصُ مصدرٍ + تأكيدُ استجابة) — الخطرُ الأمنيّ لا يُمزَج بأرقام الأداء أبداً.
 *  - الزياراتُ الليلية لا تُغيّر «أُنجز / في الموعد» بحرفٍ واحد.
 *  - التسميةُ الجديدة «مخاطر النشاط الأمني» ظاهرةٌ ببطاقةٍ منفصلةٍ عن ساعات العمل.
 *  - أثرُ الزيارات الخام (١٢٠ صفاً) مطويٌّ داخل `<details>` لا مسكوبٌ افتراضاً (§5.8).
 *  - حدودُ الليل/خارج الدوام من الإعدادات `sec.hours_start/end` لا من ٠٨–١٦ صلبةٍ في الشيفرة.
 */
class SecurityActivitySplitTest extends TestCase
{
    /** زيارةُ صفحةٍ للمستخدم في ساعةٍ محددةٍ أمس */
    protected function visit(string $uid, int $hour, int $minute = 0, string $path = '/dashboard'): void
    {
        DB::table('page_visits')->insert(['id' => Str::uuid(), 'user_id' => $uid,
            'path' => $path, 'at' => now()->subDay()->setTime($hour, $minute)]);
    }

    /** مدى ١٤ يوماً كما تبنيه شاشةُ النشاط */
    protected function range(): TimeRange
    {
        return TimeRange::fromRequest(new Request(['days' => '14']));
    }

    /* ── ١) لا شاشةَ أداءٍ تقرأ الدرجةَ الأمنية — فحصُ مصدرٍ + تأكيدُ استجابة ── */
    public function test_performance_screen_never_reads_the_security_score(): void
    {
        $this->seedCore();

        // فحصُ المصدر: لوحةُ الأداء (المتحكّم والعرض) لا تمسّ Risk ولا زيارات الصفحات
        $ctrl = file_get_contents(app_path('Http/Controllers/Web/PerformanceController.php'));
        $view = file_get_contents(resource_path('views/performance/index.blade.php'));
        foreach (['Risk::', 'riskProfile', 'page_visits', 'نسبة الشك', 'مخاطر النشاط'] as $needle) {
            $this->assertStringNotContainsString($needle, $ctrl, 'متحكّم الأداء يقرأ الأمن: ' . $needle);
            $this->assertStringNotContainsString($needle, $view, 'عرض الأداء يعرض الأمن: ' . $needle);
        }

        // تأكيدُ الاستجابة: نشاطٌ ليليٌّ كثيف ودخولٌ مريب — ولا أثرَ أمنياً على شاشة الأداء
        for ($i = 0; $i < 6; $i++) $this->visit($this->employee->id, 3, $i * 10);
        hub_audit('دخول مريب', null, null, $this->employee->name, ['user_id' => $this->employee->id]);

        $this->actingAs($this->owner)->get('/performance')->assertOk()
            ->assertDontSee('نسبة الشك')->assertDontSee('مخاطر النشاط الأمني')
            ->assertDontSee('معدل التلاعب');
    }

    /* ── ٢) زياراتٌ ليليةٌ لا تُغيّر «أُنجز / في الموعد» ── */
    public function test_night_visits_do_not_change_completed_or_on_time_numbers(): void
    {
        $this->seedCore();

        // ٣ مهامَ منجزةٍ في موعدها للموظفة — أُنجز = ٣ والالتزام = ١٠٠٪
        // (WP-7.3) وبختمِ الإنجاز `completed_at` الذي يكتبه ModuleController: صار
        // لوحُ الأداء يقرأ الإنجازَ من ختمه لا من آخر تعديل، فبذرةٌ «مكتملة» بلا
        // ختمٍ لا تحاكي ما يكتبه النظام. التوكيدُ نفسُه بلا تخفيف: الأرقامُ ذاتُها
        // قبل النشاط الليليّ وبعده.
        for ($i = 0; $i < 3; $i++) {
            DB::table('tasks')->insert(['id' => Str::uuid(), 'title' => 'مهمة ' . $i,
                'assignee_id' => $this->employee->id, 'status' => 'مكتملة',
                'due' => now()->addDay()->toDateString(), 'completed_at' => now()->subHour(),
                'created_at' => now()->subDays(2), 'updated_at' => now()]);
        }

        $screen = fn () => $this->actingAs($this->owner)->get('/performance')->assertOk()->getContent();
        $before = $screen();
        $this->assertStringContainsString('<b>3</b>', $before, 'أُنجز = ٣ قبل النشاط الليلي');
        $this->assertStringContainsString('100٪', $before, 'الالتزام = ١٠٠٪ قبل النشاط الليلي');

        // نشاطٌ ليليٌّ كثيف (٠٢:٠٠ فجراً) — يجب ألّا يُغيّر أرقامَ الأداء بحرف
        for ($i = 0; $i < 6; $i++) $this->visit($this->employee->id, 2, $i * 10);

        $after = $screen();
        $this->assertStringContainsString('<b>3</b>', $after, 'الزيارات الليلية غيّرت «أُنجز»');
        $this->assertStringContainsString('100٪', $after, 'الزيارات الليلية غيّرت «في الموعد»');
    }

    /* ── ٣) التسميةُ الجديدة ظاهرةٌ — وببطاقةٍ منفصلةٍ عن ساعات العمل ── */
    public function test_security_risk_card_has_new_label_in_a_separate_card(): void
    {
        $this->seedCore();
        $this->visit($this->employee->id, 10);
        $this->visit($this->employee->id, 3);

        $html = $this->actingAs($this->owner)->get('/admin/activity/' . $this->employee->id)
            ->assertOk()->getContent();

        $this->assertStringContainsString('مخاطر النشاط الأمني', $html, 'التسمية الجديدة غائبة');
        $this->assertStringNotContainsString('مؤشرات الشك والسلوك', $html, 'التسمية القديمة ما زالت ظاهرة');

        // بطاقةٌ منفصلة: ما بين عنوان الأمن وبطاقة ساعات العمل لا «ساعات داخل الدوام» فيه
        $sec = substr($html, strpos($html, 'مخاطر النشاط الأمني'));
        $this->assertNotFalse(strpos($sec, 'ساعات العمل الفعلية'), 'بطاقة ساعات العمل غائبة');
        $sec = substr($sec, 0, strpos($sec, 'ساعات العمل الفعلية'));
        $this->assertStringNotContainsString('ساعات داخل الدوام', $sec,
            'ساعات الدوام (عمليّ) ما زالت داخل بطاقة الأمن — الفصل لم يتم');
    }

    /* ── ٤) أثرُ الزيارات الخام مطويٌّ داخل <details> (§5.8) ── */
    public function test_raw_visit_trail_is_folded_inside_details(): void
    {
        $this->seedCore();
        for ($i = 0; $i < 5; $i++) $this->visit($this->employee->id, 10, $i * 10, '/m/tasks');

        $this->actingAs($this->owner)->get('/admin/activity/' . $this->employee->id)
            ->assertOk()->assertSee('مسار التنقل داخل النظام');

        // فحصُ المصدر: حلقةُ $trail داخل <details> في بطاقة المسار لا قبلها
        $blade = file_get_contents(resource_path('views/activity/show.blade.php'));
        $card = substr($blade, strpos($blade, 'مسار التنقل داخل النظام'));
        $details = strpos($card, '<details');
        $loop = strpos($card, '@foreach ($trail');
        $this->assertNotFalse($loop, 'حلقة $trail غائبة من بطاقة المسار');
        $this->assertNotFalse($details, 'لا <details> في بطاقة المسار — الأثر الخام مسكوب افتراضاً');
        $this->assertLessThan($loop, $details, 'أثر الزيارات يُسكب قبل <details> لا داخله');
    }

    /* ── ٥) الليل/خارج الدوام من الإعدادات لا من ٠٨–١٦ صلبة ── */
    public function test_off_hours_follow_settings_not_hardcoded(): void
    {
        $this->seedCore();
        // دوامٌ ٠٦:٠٠–١٤:٠٠ — زيارةُ ١٥:٠٠ خارج الدوام (كانت «داخله» بالثابت القديم ٠٨–١٦)
        $this->hubSetting('sec.hours_start', '06:00');
        $this->hubSetting('sec.hours_end', '14:00');
        $this->visit($this->employee->id, 15);
        $this->visit($this->employee->id, 7);   // داخل الدوام الجديد (كانت «خارجه» بالثابت)

        $r = Risk::activity($this->employee, $this->range());
        $this->assertSame(0.1, $r['out_h'], 'زيارة ١٥:٠٠ تُحسب خارج الدوام بإعداد ٠٦–١٤');
        $this->assertSame(0.0, $r['night_h'], 'لا زيارات في ساعات الليل (٠٠–٠٦)');
        $this->assertArrayHasKey('score', $r);
        $this->assertArrayHasKey('parts', $r);

        // ولا ثابتَ ٠٨–١٦ باقياً في متحكّم النشاط
        $src = file_get_contents(app_path('Http/Controllers/Web/ActivityController.php'));
        $this->assertDoesNotMatchRegularExpression('/h\s*>=\s*8\s*&&\s*\$h\s*<\s*16/', $src,
            'حدود الدوام ما زالت صلبة ٠٨–١٦ في المتحكّم');
    }
}
