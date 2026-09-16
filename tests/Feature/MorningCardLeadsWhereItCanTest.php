<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

/**
 * **بطاقةُ الصباحِ تقود إلى بابٍ يُفتَح لقارئها** (محاكاةُ الشهر · M-F5).
 *
 * بطاقةُ «📉 مشاريع متعثرة» تُعرَض بشرطِ `hub_can($u,'projects','v')`، وكلُّ
 * روابطها — الصفوفُ و«عرض الكل ←» — كانت تقصد `costs.index` الذي يشترط رايةَ
 * `finAnalytics`. **فالشرطان من عالمَين**، و٢٧ من ٣٠ موظّفاً في محاكاةِ الشهر
 * يحملون الأوّلَ دون الثاني.
 *
 * وأُثبت حيّاً على خالدٍ العتيبي في الأسبوع الثالث، يومَ بلغت الأزمةُ مشروعَ
 * «نظام مواعيد مستشفى السلام» (٨١٪ → ٥٣٪): البطاقةُ ظهرت، والرابطان ٤٠٣.
 *
 * والإصلاحُ **لا يحذف وجهةً**: حاملُ الرايةِ يبقى يُقاد إلى تحليلِ التكلفةِ
 * كما كان، ومن لا يحملها يُقاد إلى **سجلِّ المشروعِ نفسِه** — وهو ما يملكه
 * أصلاً بـ`projects:v`، وفيه صحّةُ المشروعِ التي استدعته البطاقةُ لأجلها.
 */
class MorningCardLeadsWhereItCanTest extends TestCase
{
    /**
     * مشروعٌ متعثّرٌ فعلاً: عاجلٌ فات إطلاقُه المتوقَّعُ بعشرين يوماً وكلُّ مهامّه متأخّرة.
     *
     * والتأخيرُ يُقاس من `launch_exp` لا من `end` (`hub_project_pl`) — أوّلُ صياغةٍ
     * لهذا التجهيزِ ملأت `end` فقرأ العاملُ «ضمن الموعد» وبقيت الصحّةُ ٧٥٪.
     */
    private function stalledProject(): Project
    {
        $p = Project::create(['name' => 'مشروعٌ متعثّر', 'status' => 'نشط',
            'priority' => 'عاجلة', 'end' => today()->subDays(20)->toDateString(),
            'launch_exp' => today()->subDays(20)->toDateString()]);

        foreach (range(1, 6) as $i) {
            Task::create(['title' => "مهمّةٌ فائتة {$i}", 'project_id' => $p->id,
                'status' => 'جديدة', 'due' => today()->subDays(10)->toDateString()]);
        }

        return $p;
    }

    private function projectReader(): User
    {
        $role = Role::create(['name' => 'قارئُ مشاريع', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1], 'tasks' => ['v' => 1]]]);

        $u = User::create(['name' => 'خالدٌ القارئ', 'email' => 'khaled@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        Employee::create(['name' => 'ملفُّ خالد', 'user_id' => $u->id,
            'status' => 'نشط', 'email' => 'khaled@test.local']);

        return $u;
    }


    /** مساراتُ الصفحةِ الداخليّةُ الفريدة — مطلقةً كانت أو نسبيّة */
    private function internalLinks(string $html): array
    {
        preg_match_all('~href="([^"\#]+)"~', $html, $m);
        $base = rtrim(config('app.url'), '/');
        $out = [];

        foreach ($m[1] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);
            if (str_starts_with($href, $base)) $href = substr($href, strlen($base));
            if (! str_starts_with($href, '/')) continue;              // خارجيٌّ أو mailto:
            if (str_starts_with($href, '/logout')) continue;          // لا نُنهي الجلسةَ أثناء المسح
            $out[$href] = true;
        }

        return array_keys($out);
    }

    public function test_the_stalled_projects_card_never_points_a_reader_at_a_door_that_refuses_them(): void
    {
        $this->seedCore();
        $p = $this->stalledProject();

        // شرطُ صحّةِ الاختبارِ نفسِه: البطاقةُ لا تظهر إلّا دون ٥٥٪، فإن لم يتعثّر
        // المشروعُ فالاختبارُ يقيس لا شيءَ ويخضرّ كاذباً.
        $h = hub_project_health($p->id);
        $this->assertLessThan(55, $h['score'],
            'لم يتعثّر المشروعُ المُعَدُّ للاختبار — فالبطاقةُ لن تظهر ولن يُقاس شيء');

        $reader = $this->projectReader();
        $res = $this->actingAs($reader)->get(route('morning'));
        $res->assertOk();

        // كلُّ رابطٍ داخليٍّ ترسمه الصفحةُ لهذا القارئ يجب أن يُفتح له.
        //
        // **والاستخراجُ يقبل المطلقَ والنسبيَّ معاً**: القوالبُ تُخرج `route()` كاملاً
        // (`http://127.0.0.1:8000/costs?p=…`)، وأوّلُ صياغةٍ لهذا المسحِ طلبت
        // `href="/…"` وحدَه فوجدت **صفرَ** روابطَ واخضرَّ الاختبارُ وهو لا يقيس شيئاً.
        $links = $this->internalLinks($res->getContent());
        $this->assertNotEmpty($links, 'لم يُستخرج رابطٌ واحد — المسحُ نفسُه معطوب');

        $refused = [];
        foreach ($links as $href) {
            if ($this->actingAs($reader)->get($href)->getStatusCode() === 403) $refused[] = $href;
        }

        $this->assertSame([], $refused,
            'صفحةُ الصباحِ رسمت لهذا القارئِ روابطَ يردُّها المنتجُ ٤٠٣');
    }

    /** ولا قدرةَ تُحذف: حاملُ رايةِ الماليّةِ يبقى يُقاد إلى تحليلِ التكلفة */
    public function test_the_finance_flag_holder_is_still_led_to_the_cost_analysis(): void
    {
        $this->seedCore();
        $p = $this->stalledProject();

        $res = $this->actingAs($this->owner)->get(route('morning'));
        $res->assertOk();
        $res->assertSee(route('costs.index', ['p' => $p->id]), false);
    }
}
