<?php

namespace Tests\Feature;

use App\Support\ActionCenter;
use App\Support\ErrorTaxonomy;
use App\Support\Health;
use App\Support\IssueState;
use App\Support\OpStatus;
use App\Support\SecurityEvents;
use App\Support\Severity;
use Tests\TestCase;

/**
 * **سلّمُ شدّةٍ واحدٌ فوق ستّ مفرداتٍ متنافرة.**
 *
 * المستودعُ يتكلّم الشدّةَ بستّ لهجات: `ErrorTaxonomy` بالإنجليزية الكبيرة،
 * و`SecurityEvents` بالصغيرة، والحوادثُ بالعربية المذكّرة، والمشاكلُ بالمؤنّثة،
 * و`ActionCenter` بثلاثِ كلماتٍ من عندِه. `Severity::normalize` يطوي الكلَّ إلى
 * خمسِ درجاتٍ قياسية — والحارسُ هنا **يجمع القيمَ من الثوابت نفسِها** لا من
 * ذاكرة كاتبِ الاختبار: قيمةٌ جديدةٌ تُضاف لأيّ ثابتٍ تسقط هنا إن نسيَتها الخريطة.
 *
 * وقاعدتان لا تُخترقان: «النبرة» (`ok|wn|bad`) ليست شدّةً فلا تدخل `normalize`
 * (تعيينُها بالرمز لا باللون)، و`OpStatus` طبقةُ عرضٍ فوق `Health` لا إعادةُ
 * تسميةٍ لثوابته — عقدُ `/healthz` محميٌّ.
 */
class SeverityMapTest extends TestCase
{
    /** كلُّ القيم الحرفية للشدّة في المستودع — تُجمَع من الثوابت لا تُكتَب يدوياً */
    private function repoSeverityValues(): array
    {
        $vals = [];
        // ErrorTaxonomy: الرموزُ الكبيرة وتسمياتُها العربية (تشمل قيمَ hub_schedule_failed: ERROR|HIGH)
        foreach (ErrorTaxonomy::SEVERITIES as $s) {
            $vals[] = $s;
            $vals[] = ErrorTaxonomy::LABELS[$s];
        }
        // SecurityEvents: مفاتيحُ خريطة النبرة هي مفرداتُ الشدّة
        foreach (array_keys(SecurityEvents::SEVERITY_TONE) as $s) $vals[] = $s;
        // ActionCenter: مفرداتُ ترتيب الإشارات
        foreach (array_keys(ActionCenter::RANK) as $s) $vals[] = $s;
        // config/hub.php: خياراتُ الحوادث (مذكّر) والمشاكل (مؤنّث) من تعريف الحقول
        foreach (['incidents', 'issues'] as $m) {
            foreach (config("hub.modules.$m.fields", []) as $f) {
                if (($f['key'] ?? '') === 'severity') foreach ($f['options'] as $o) $vals[] = $o;
            }
        }

        return array_values(array_unique($vals));
    }

    public function test_every_repo_severity_value_normalizes_to_one_of_five_levels(): void
    {
        $vals = $this->repoSeverityValues();
        $this->assertGreaterThanOrEqual(20, count($vals), 'الجمعُ من الثوابت انكسر — القائمة فارغةٌ تقريباً');
        foreach ($vals as $v) {
            $l = Severity::normalize($v);
            $this->assertContains($l, Severity::LEVELS, "«{$v}» طُبّعت إلى «{$l}» خارج السلّم");
            // ما يهبط إلى info من قيم المستودع ثلاثُ مفرداتٍ معلوماتية بالقصد — غيرُها سقوطُ مجهولٍ يعني ثغرةً في الخريطة
            if ($l === 'info') {
                $this->assertContains($v, ['INFO', 'info', 'معلومة', 'اطّلاع'], "«{$v}» سقطت إلى info سقوطَ المجهول لا تطبيعاً مقصوداً");
            }
        }
        // السلّمُ نفسُه ثابتٌ على قيمِه — يطبّع كلُّ مستوى إلى نفسِه
        foreach (Severity::LEVELS as $l) $this->assertSame($l, Severity::normalize($l));
    }

    public function test_normalization_preserves_meaning_per_vocabulary(): void
    {
        // ErrorTaxonomy — رتابةُ الترتيب محفوظة (RANK تصاعديّ ⇒ rank التطبيع لا يتناقص)
        $prev = -1;
        foreach (ErrorTaxonomy::SEVERITIES as $s) {
            $r = Severity::rank(Severity::normalize($s));
            $this->assertGreaterThanOrEqual($prev, $r, "ترتيبُ «{$s}» انقلب بعد التطبيع");
            $prev = $r;
        }
        $this->assertSame('critical', Severity::normalize('CRITICAL'));
        // SecurityEvents — النبرةُ بعد التطبيع تُطابق نبرتَه الأصلية
        foreach (SecurityEvents::SEVERITY_TONE as $s => $tone) {
            $this->assertSame($tone, Severity::tone(Severity::normalize($s)), "نبرةُ «{$s}» تغيّرت بعد التطبيع");
        }
        // العربيّتان — التذكيرُ والتأنيث يلتقيان في الدرجة نفسِها
        foreach ([['حرج', 'حرجة', 'critical'], ['عالي', 'عالية', 'high'], ['متوسط', 'متوسطة', 'medium'], ['منخفض', 'منخفضة', 'low']] as [$m, $f, $l]) {
            $this->assertSame($l, Severity::normalize($m));
            $this->assertSame($l, Severity::normalize($f));
        }
        // ActionCenter — «حرج» أعلى من «مهم» أعلى من «اطّلاع» بعد التطبيع أيضاً
        $this->assertTrue(Severity::atLeast(Severity::normalize('حرج'), Severity::normalize('مهم')));
        $this->assertTrue(Severity::atLeast(Severity::normalize('مهم'), Severity::normalize('اطّلاع')));
        $this->assertSame('info', Severity::normalize('اطّلاع'));
    }

    public function test_unknown_and_tones_fall_to_info_without_exception(): void
    {
        // المجهول ⇒ info بلا استثناءٍ ولا تحذير
        foreach ([null, '', '  ', 'nonsense', 'شديد جداً', '42', 'SEV1'] as $u) {
            $this->assertSame('info', Severity::normalize($u), 'المجهول «' . var_export($u, true) . '» لم يسقط إلى info');
        }
        // النبراتُ ليست شدّةً (ok|wn|bad تعيينُها بالرمز لا هنا) ⇒ تُعامَل مجهولاً
        foreach (['ok', 'wn', 'bad', 'g', 'OK', 'BAD'] as $tone) {
            $this->assertSame('info', Severity::normalize($tone), "النبرة «{$tone}» تسلّلت إلى normalize");
        }
    }

    public function test_labels_tones_and_ranks_cover_exactly_the_five_levels(): void
    {
        $this->assertSame(['info', 'low', 'medium', 'high', 'critical'], Severity::LEVELS);
        $this->assertSame(['معلوماتي', 'منخفض', 'متوسط', 'مرتفع', 'حرج'],
            array_map(fn ($l) => Severity::label($l), Severity::LEVELS));
        $this->assertSame(['g', 'g', 'wn', 'bad', 'bad'],
            array_map(fn ($l) => Severity::tone($l), Severity::LEVELS));
        $this->assertSame([0, 1, 2, 3, 4], array_map(fn ($l) => Severity::rank($l), Severity::LEVELS));
        $this->assertTrue(Severity::atLeast('high', 'medium'));
        $this->assertTrue(Severity::atLeast('high', 'high'));
        $this->assertFalse(Severity::atLeast('low', 'critical'));
    }

    public function test_opstatus_is_a_display_layer_and_health_constants_are_untouched(): void
    {
        // عقدُ /healthz: الثوابتُ الخمسة بأسمائها الحرفية — لا إعادةَ تسمية
        $this->assertSame('HEALTHY', Health::HEALTHY);
        $this->assertSame('DEGRADED', Health::DEGRADED);
        $this->assertSame('UNAVAILABLE', Health::UNAVAILABLE);
        $this->assertSame('MAINTENANCE', Health::MAINTENANCE);
        $this->assertSame('UNKNOWN', Health::UNKNOWN);
        // كلُّ حالة Health تعود نفسَها — OpStatus لا يخترع مفرداتٍ سادسة
        foreach ([Health::HEALTHY, Health::DEGRADED, Health::UNAVAILABLE, Health::MAINTENANCE, Health::UNKNOWN] as $h) {
            $this->assertSame($h, OpStatus::fromHealth($h));
            $this->assertSame(Health::LABELS[$h], OpStatus::label($h), "تسميةُ «{$h}» ليست من Health::LABELS");
            $this->assertSame(Health::TONE[$h], OpStatus::tone($h), "نبرةُ «{$h}» ليست من Health::TONE");
        }
        // مرادفاتُ الـspec للعرض فقط: warning≈DEGRADED و critical≈UNAVAILABLE — والمجهول UNKNOWN
        $this->assertSame(Health::DEGRADED, OpStatus::fromHealth('warning'));
        $this->assertSame(Health::UNAVAILABLE, OpStatus::fromHealth('critical'));
        $this->assertSame(Health::HEALTHY, OpStatus::fromHealth('operational'));
        $this->assertSame(Health::UNKNOWN, OpStatus::fromHealth('whatever'));
        // وملفُ عقد /healthz ما زال يذكر الحالاتِ الخمس حرفياً (حارسُ مصدرٍ لا ادّعاء)
        $src = file_get_contents(base_path('tests/Feature/HealthModelTest.php'));
        foreach (['HEALTHY', 'DEGRADED', 'UNAVAILABLE', 'MAINTENANCE', 'UNKNOWN'] as $c) {
            $this->assertStringContainsString('Health::' . $c, $src, "عقدُ /healthz فقد الحالة {$c}");
        }
    }

    public function test_issue_state_map_is_the_five_states_with_arabic_labels(): void
    {
        $this->assertSame([
            'new' => 'جديد', 'investigating' => 'قيد التحقيق', 'in_progress' => 'قيد المعالجة',
            'resolved' => 'محلول', 'ignored' => 'متجاهَل',
        ], IssueState::MAP);
        $this->assertSame('قيد التحقيق', IssueState::label('investigating'));
        $this->assertSame('غريب', IssueState::label('غريب'), 'المجهول يُعرَض كما هو لا يُخفى');
    }

    public function test_info_badge_tokens_and_class_exist_in_both_themes(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        // التوكن في الجذر وفي الليلي — لونٌ خامسٌ للدلالة المعلوماتية
        preg_match('/:root\{.*?\n\}/s', $css, $root);
        // كتلةُ الليلي الحقيقية تبدأ سطراً (كتلةُ الطباعة `:root,html[...]` ليست إياها)
        preg_match('/^html\[data-theme="dark"\]\{[^}]*\}/ms', $css, $dark);
        foreach (['--info:', '--infobg:'] as $tok) {
            $this->assertStringContainsString($tok, $root[0] ?? '', "{$tok} غائب عن :root");
            $this->assertStringContainsString($tok, $dark[0] ?? '', "{$tok} غائب عن الوضع الليلي");
        }
        $this->assertStringContainsString('.bdg.i{background:var(--infobg);color:var(--info)}', $css, 'صنفُ الشارة .bdg.i غائب');
    }
}
