<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Severity;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * عدّة مركز التحكّم (WP-1.5) — ستّةُ مكوّنات `partials/cc/*` وكتالوجُ روابط الإدارة.
 *
 * الحرّاس الأربعة: كلُّ صنفٍ تكتبه المكوّناتُ تعرفه الورقة (نمط `StyleVocabularyTest`
 * — الصنفُ المفقود لا يُخطئ بل يُعرَض عارياً بصمت)؛ و`cc/trend` بلا نقاطٍ يصارح
 * «سيبدأ القياس من الآن» ولا يرسم صفراً كاذباً؛ ورأسُ الفرز `cc/th` يحمل
 * `scope="col"` و`aria-sort` (الاكتشاف قاس ٥٠١ رأسَ جدولٍ بلا scope — الجداولُ
 * الجديدة لا تُكرّر الدَّين)؛ و`hub_admin_links` يطابق شريطَ الإدارة المرسوم اليوم
 * حرفاً بحرف — حارسُ انحدارٍ قبل أن يرسمه الطورُ ١٠ من الكتالوج.
 */
class ControlCenterUiKitTest extends TestCase
{
    private const PARTIALS = ['kpis', 'trend', 'findings', 'freshness', 'tabs', 'th'];

    /** كلُّ صنفٍ حرفيّ تكتبه مكوّناتُ cc/* معرَّفٌ في app.css — لا عرضَ عارياً بصمت */
    public function test_every_class_written_by_the_cc_partials_is_defined_in_the_stylesheet(): void
    {
        $css = file_get_contents(public_path('css/app.css'));
        preg_match_all('/\.([a-zA-Z][\w-]*)/', $css, $m);
        $known = array_flip($m[1] ?? []);

        $orphans = [];
        foreach (self::PARTIALS as $p) {
            $f = resource_path("views/partials/cc/{$p}.blade.php");
            $this->assertFileExists($f, "المكوّن cc/{$p} غير موجود");
            // تعابيرُ Blade تُنزَع ويُفحَص الحرفيُّ الباقي — نمطُ StyleVocabularyTest نفسُه
            $src = (string) preg_replace('/\{\{--.*?--\}\}/s', ' ', file_get_contents($f));
            preg_match_all('/class="([^"]*)"/', $src, $mm);
            foreach ($mm[1] ?? [] as $attr) {
                $attr = (string) preg_replace(
                    ['/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/@[a-zA-Z]+\s*\([^)]*\)/', '/@[a-zA-Z]+/'],
                    ' ', $attr);
                foreach (preg_split('/\s+/', trim($attr), -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    if (! preg_match('/^[a-zA-Z][\w-]*$/', $c)) continue;
                    if (! isset($known[$c])) $orphans[$c][] = $p;
                }
            }
        }

        $this->assertSame([], array_keys($orphans),
            'أصنافٌ تكتبها مكوّنات cc/* ولا تعرفها الورقة: ' . json_encode($orphans, JSON_UNESCAPED_UNICODE));
    }

    /** بلا نقاطٍ لا خطَّ صفرٍ كاذب — حالةٌ فارغةٌ صادقة عبر partials.empty */
    public function test_trend_with_no_points_prints_the_honest_empty_state_and_no_zero_line(): void
    {
        $html = view('partials.cc.trend', ['series' => []])->render();

        $this->assertStringContainsString('سيبدأ القياس من الآن', $html);
        $this->assertStringContainsString('class="empty"', $html);
        // لا أعمدةَ ولا ارتفاعات — الصفرُ المرسوم ادّعاءُ قياسٍ لم يقع
        $this->assertStringNotContainsString('height:', $html);

        $series = [
            ['at' => now()->subDay(), 'value' => 3.0],
            ['at' => now(), 'value' => 5.0],
        ];
        $full = view('partials.cc.trend', ['series' => $series, 'unit' => 'ms'])->render();
        $this->assertStringNotContainsString('سيبدأ القياس من الآن', $full);
        $this->assertStringContainsString('height:100%', $full);   // عمودُ الذروة (pct=100)
        $this->assertStringContainsString('ms', $full);            // الوحدةُ في التلميح
    }

    /** النتائجُ تمرّ بسلّم الشدّة الواحد (Severity) وتُظهر التوصية — والفراغُ فراغٌ صادق */
    public function test_findings_maps_severity_to_the_unified_badge_scale_and_shows_empty_state(): void
    {
        $html = view('partials.cc.findings', ['rows' => [
            ['sev' => 'critical', 'title' => 'عطل حرج', 'why' => 'السبب الجذري هنا', 'fix' => 'أصلِح كذا', 'n' => 3],
            ['sev' => 'ERROR', 'title' => 'خطأ من التصنيف القائم'],   // مفردةُ ErrorTaxonomy تُطبَّع
        ]])->render();

        $this->assertStringContainsString('bdg bad', $html);
        $this->assertStringContainsString(Severity::label('critical'), $html);
        $this->assertStringContainsString(Severity::label('ERROR'), $html);
        $this->assertStringContainsString('أصلِح كذا', $html);
        $this->assertStringContainsString('tblwrap', $html);
        $this->assertStringContainsString('scope="col"', $html);

        $empty = view('partials.cc.findings', ['rows' => []])->render();
        $this->assertStringContainsString('class="empty"', $empty);
    }

    /** «آخر حساب · مخبّأ · تحديث» — ورابطُ التحديث يحمل fresh=1 ويحفظ باقي المعاملات */
    public function test_freshness_shows_the_stamp_and_a_fresh_link_preserving_query(): void
    {
        $this->app->instance('request', Request::create('/admin/ops?tab=queue'));

        $html = view('partials.cc.freshness', ['at' => now()->subMinutes(3), 'ttl' => 300])->render();
        $this->assertStringContainsString('آخر حساب', $html);
        $this->assertStringContainsString('fresh=1', $html);
        $this->assertStringContainsString('tab=queue', $html);
        $this->assertStringContainsString('تحديث', $html);

        // بلا ختمٍ بعدُ — لا يُدّعى وقتُ حسابٍ لم يقع
        $none = view('partials.cc.freshness', ['at' => null, 'ttl' => 300])->render();
        $this->assertStringContainsString('لم يُحسب بعد', $none);
    }

    /** تبويبات ?tab= تحفظ باقي المعاملات وتُسقط الترقيم — والنشطُ يحمل aria-current */
    public function test_tabs_preserve_query_params_and_mark_the_active_tab(): void
    {
        $this->app->instance('request', Request::create('/admin/security?range=7d&page=4'));

        $html = view('partials.cc.tabs', [
            'tabs' => [
                ['key' => 'overview', 'label' => 'نظرة عامة'],
                ['key' => 'findings', 'label' => 'النتائج'],
            ],
            'active' => 'findings',
        ])->render();

        $this->assertStringContainsString('tab=overview', $html);
        $this->assertStringContainsString('range=7d', $html);
        $this->assertStringNotContainsString('page=4', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('tab on', $html);
    }

    /** رأسُ الفرز: scope="col" دائماً + aria-sort صادق + رابطٌ يعكس الاتجاه ويحفظ المعاملات */
    public function test_sortable_th_emits_scope_and_aria_sort_and_preserves_query(): void
    {
        $this->app->instance('request', Request::create('/admin/users?sort=name&dir=asc&q=x&page=3'));

        $on = view('partials.cc.th', ['col' => 'name', 'label' => 'الاسم'])->render();
        $this->assertStringContainsString('scope="col"', $on);
        $this->assertStringContainsString('aria-sort="ascending"', $on);
        $this->assertStringContainsString('dir=desc', $on);    // الضغطةُ التالية تعكس الاتجاه
        $this->assertStringContainsString('q=x', $on);         // باقي المعاملات محفوظة
        $this->assertStringNotContainsString('page=3', $on);   // والترقيمُ يسقط — الفرزُ صفحةٌ أولى

        $off = view('partials.cc.th', ['col' => 'email', 'label' => 'البريد'])->render();
        $this->assertStringContainsString('aria-sort="none"', $off);
        $this->assertStringContainsString('sort=email', $off);
    }

    /** الكتالوج يطابق شريطَ الإدارة المرسوم اليوم ١:١ — لمالكٍ ولحامل رايةٍ واحدة ولموظفٍ بلا رايات */
    public function test_admin_links_catalogue_matches_the_admin_bar_rendered_today(): void
    {
        $this->seedCore();
        $auditRole = Role::create(['name' => 'مدقق', 'scope' => 'all', 'flags' => ['audit' => 1], 'matrix' => []]);
        $auditor = User::create(['name' => 'مدقق', 'email' => 'auditor@test.local',
            'password' => 'Secret!2026x', 'role_id' => $auditRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        foreach ([$this->owner, $auditor, $this->employee] as $u) {
            $html = $this->actingAs($u)->get('/')->assertOk()->getContent();
            $rendered = $this->adminbarHrefs($html);
            $catalogue = collect(hub_admin_links($u));

            // البنية المعلنة: {key, label, route, group, ok} لا غير
            $catalogue->each(fn ($l) => $this->assertSame(
                ['key', 'label', 'route', 'group', 'ok'], array_keys($l)));

            $expected = $catalogue->filter(fn ($l) => $l['ok'])
                ->map(fn ($l) => route($l['route']))->sort()->values()->all();
            sort($rendered);
            $this->assertSame($expected, $rendered,
                "كتالوج hub_admin_links لا يطابق شريطَ الإدارة المرسوم للمستخدم {$u->name}");
            auth()->logout();
        }

        // للمالك: كلُّ التسميات والمجموعات الخمس حاضرةٌ في الشريط نفسِه
        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        preg_match('/<nav class="adminbar".*?<\/nav>/s', $html, $m);
        foreach (hub_admin_links($this->owner) as $l) {
            $this->assertStringContainsString($l['label'], $m[0], "التسمية {$l['label']} غائبة عن الشريط");
        }
        $this->assertSame(['شخصي', 'الفريق', 'الرقابة', 'البناء', 'النظام'],
            collect(hub_admin_links($this->owner))->pluck('group')->unique()->values()->all());
    }

    /** hub_screen: الافتراضيُّ كما هو حرفياً — وبراية stamped يعود {at, data} لتغذية cc/freshness */
    public function test_hub_screen_stamped_returns_at_plus_data_and_default_stays_identical(): void
    {
        $calls = 0;
        $fn = function () use (&$calls) { $calls++; return ['v' => 42]; };

        $plain = hub_screen('cctest.plain', 60, $fn);
        $this->assertSame(['v' => 42], $plain);                       // لا غلافَ في السلوك الافتراضي
        $this->assertSame(['v' => 42], hub_screen('cctest.plain', 60, $fn));
        $this->assertSame(1, $calls);                                  // مخبّأةٌ كما كانت

        $st = hub_screen('cctest.stamped', 60, $fn, [], true);
        $this->assertSame(['at', 'data'], array_keys($st));
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $st['at']);
        $this->assertSame(['v' => 42], $st['data']);
        $this->assertSame(2, $calls);

        $again = hub_screen('cctest.stamped', 60, $fn, [], true);
        $this->assertEquals($st['at'], $again['at']);                  // الختمُ من الخبيئة لا من الساعة
        $this->assertSame(2, $calls);
    }

    /** @return string[] كل href داخل شريط الإدارة — وخارجَه لا شيء */
    private function adminbarHrefs(string $html): array
    {
        if (! preg_match('/<nav class="adminbar".*?<\/nav>/s', $html, $m)) return [];
        preg_match_all('/href="([^"]+)"/', $m[0], $h);

        return array_values(array_unique($h[1] ?? []));
    }
}
