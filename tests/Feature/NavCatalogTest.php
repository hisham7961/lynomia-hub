<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\SearchController;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * WP-10.3 (spec §11 · §10) — **قائمةٌ واحدة لا قائمتان**.
 *
 * شريطُ الإدارة كان مكتوباً بيده في `layouts/app.blade.php`، ووِجهاتُ البحث
 * مكتوبةً بيدها في `SearchController::destinations` — فتباعدَتا: البحثُ يفتقد
 * «نشاط الموظفين» ويدلّ على Webhooks بدل «التكاملات»، والشريطُ لا يعرف مركزَي
 * الحوادث والتنبيهات أصلاً. هنا يصيران **مرسومين من `hub_admin_links()`** —
 * الكتالوج الذي بُني في الطور ١ لهذا اليوم بالذات.
 *
 * وما يحرسه هذا الملف: أن الرسمَ من الكتالوج لا يُسقط رابطاً قائماً ولا اسمَ
 * مسار، وأن حارسَ كل رابطٍ يبقى حارسَه هو (لا حارسَ الشريط كلِّه)، وأن الشريطَ
 * قائمةٌ حقيقية بأسماءٍ يقرأها القارئ الشاشيّ (critic #13 · §29).
 */
class NavCatalogTest extends TestCase
{
    /** مجموعاتُ spec §11 الأربع بترتيبها */
    private const GROUPS = ['الأمن والرقابة', 'التشغيل', 'الجودة والحوكمة', 'الإعدادات'];

    /** الروابطُ التي حملها الشريطُ قبل هذه الحزمة — لا يسقط منها واحد */
    private const LEGACY = ['prefs.edit', 'users.index', 'roles.index', 'audit.index',
        'security.index', 'ops.index', 'errors.index', 'activity.index', 'dataroom.index',
        'fields.index', 'flows.index', 'integrations.index', 'quality.index', 'settings.edit',
        'quoteflow'];

    private function persona(string $name, array $flags, array $matrix = []): User
    {
        $role = Role::create(['name' => $name, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => $name . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function bar(User $u, string $path = '/'): string
    {
        $html = $this->actingAs($u)->get($path)->assertOk()->getContent();
        auth()->logout();

        return preg_match('/<nav class="adminbar".*?<\/nav>/s', $html, $m) ? $m[0] : '';
    }

    private function hrefs(string $bar): array
    {
        preg_match_all('/href="([^"]+)"/', $bar, $m);
        $out = array_values(array_unique($m[1] ?? []));
        sort($out);

        return $out;
    }

    /** روابطُ الكتالوج المسموحة لهذا المستخدم، محلولةً ومرتّبة */
    private function allowed(User $u): array
    {
        $out = [];
        foreach (hub_admin_links($u) as $l) {
            if ($l['ok']) $out[] = route($l['route'], $l['args']);
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** وِجهاتُ البحث — الدالّةُ محميّة، فيُفتح لها بابٌ في الاختبار وحدَه */
    private function dests(string $q): array
    {
        $c = new class extends SearchController
        {
            public function open(string $q): array { return $this->destinations($q); }
        };

        return $c->open($q);
    }

    /* ───────────── ١) الشريطُ من الكتالوج، لكل شخصيةٍ بحارسها ───────────── */

    public function test_the_admin_bar_is_drawn_from_the_catalogue_for_every_persona(): void
    {
        $this->seedCore();
        $auditor = $this->persona('مدقق', ['audit' => 1]);
        $userAdmin = $this->persona('مديرحسابات', ['users' => 1]);

        foreach ([$this->owner, $auditor, $userAdmin] as $u) {
            $bar = $this->bar($u);
            $this->assertNotSame('', $bar, "شريطُ الإدارة غائبٌ عن {$u->name}");
            $this->assertSame($this->allowed($u), $this->hrefs($bar),
                "الشريطُ لا يطابق كتالوج hub_admin_links للمستخدم {$u->name}");
        }

        // الموظّفةُ بلا رايةٍ إدارية: لا شريطَ أصلاً — والكتالوجُ يبقى صادقاً في نفسه
        $this->assertSame('', $this->bar($this->employee), 'شريطُ الإدارة ظهر لمن لا إدارةَ له');
    }

    /** المجموعاتُ الأربع (spec §11) بترتيبها في الكتالوج وفي الشريط */
    public function test_the_bar_capsules_are_the_four_spec_groups_in_order(): void
    {
        $this->seedCore();

        $groups = [];
        foreach (hub_admin_links($this->owner) as $l) {
            if (! in_array($l['group'], $groups, true)) $groups[] = $l['group'];
        }
        $this->assertSame(self::GROUPS, $groups, 'مجموعاتُ الكتالوج ليست مجموعاتِ §11');

        $bar = $this->bar($this->owner);
        $at = -1;
        foreach (self::GROUPS as $g) {
            $pos = mb_strpos($bar, $g);
            $this->assertNotFalse($pos, "المجموعة «{$g}» غائبةٌ عن الشريط");
            $this->assertGreaterThan($at, $pos, "المجموعة «{$g}» خارجَ ترتيبها في الشريط");
            $at = $pos;
        }
    }

    /* ───────────── ٢) لا مسارَ قائمٌ يسقط ───────────── */

    public function test_every_route_name_the_bar_carried_before_still_resolves_and_shows(): void
    {
        $this->seedCore();
        $bar = $this->bar($this->owner);

        foreach (self::LEGACY as $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "اسمُ المسار $name اختفى");
            $this->assertStringContainsString('href="' . route($name) . '"', $bar,
                "الرابطُ $name سقط من شريط الإدارة");
        }

        // وكلُّ مدخلٍ في الكتالوج اسمُ مسارٍ حقيقيّ يُحلّ بمعاملاته
        foreach (hub_admin_links($this->owner) as $l) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($l['route']),
                "الكتالوج يحمل اسمَ مسارٍ لا وجودَ له: {$l['route']}");
            $this->assertIsString(route($l['route'], $l['args']));
        }
    }

    /** لا مفتاحَ مكرَّرٌ ولا رابطٌ مكرَّر — لا في الكتالوج ولا في وِجهات البحث */
    public function test_no_duplicate_link_in_the_catalogue_or_in_the_destinations(): void
    {
        $this->seedCore();
        $links = hub_admin_links($this->owner);

        $keys = array_column($links, 'key');
        $this->assertSame(array_values(array_unique($keys)), $keys, 'مفتاحٌ مكرَّرٌ في الكتالوج');

        $urls = array_map(fn ($l) => route($l['route'], $l['args']), $links);
        $this->assertSame(array_values(array_unique($urls)), $urls, 'رابطٌ مكرَّرٌ في الكتالوج');

        $this->actingAs($this->owner);
        $d = array_column($this->dests(''), 'u');
        $this->assertSame(array_values(array_unique($d)), $d, 'وِجهةٌ مكرَّرةٌ في نتائج البحث');
    }

    /* ───────────── ٣) المركزان الغائبان اليوم ───────────── */

    public function test_incidents_and_alerts_centres_join_the_bar_for_the_owner_alone(): void
    {
        $this->seedCore();
        $auditor = $this->persona('مدقق', ['audit' => 1]);
        $userAdmin = $this->persona('مديرحسابات', ['users' => 1]);

        $ownerBar = $this->bar($this->owner);
        $this->assertStringContainsString('href="' . route('m.index', 'incidents') . '"', $ownerBar,
            'مركزُ الحوادث ما زال غائباً عن الشريط');
        $this->assertStringContainsString('href="' . route('alerts.center') . '"', $ownerBar,
            'مركزُ التنبيهات ما زال غائباً عن الشريط');

        // ولكلٍّ حارسُه هو: من لا يملك الوحدةَ ولا المراقبة لا يرى مركزَيهما
        foreach ([$auditor, $userAdmin] as $u) {
            $bar = $this->bar($u);
            $this->assertStringNotContainsString(route('m.index', 'incidents'), $bar,
                "مركزُ الحوادث ظهر لـ{$u->name} بلا صلاحيته");
            $this->assertStringNotContainsString(route('alerts.center'), $bar,
                "مركزُ التنبيهات ظهر لـ{$u->name} بلا صلاحيته");
        }
    }

    /**
     * (§49 · §11 · §32) **نظرةُ التحكّم في الكتالوج**: صفحةٌ لا يدلّ عليها شيءٌ
     * صفحةٌ ميّتة. `control.index` كانت تُبلَغ من مركز التوصيات وحدَه — لا في
     * الشريط ولا في البحث — وهي **مستوى القيادة** الذي بُني الطورُ العاشر له.
     * والكتالوجُ مصدرُ الاثنين، فمدخلٌ واحدٌ فيه يفتح البابين معاً بحارسٍ واحد.
     */
    public function test_the_control_overview_is_reachable_from_the_bar_and_the_search(): void
    {
        $this->seedCore();
        $auditor = $this->persona('مدقق', ['audit' => 1]);
        $monitor = $this->persona('مراقب', ['monitor' => 1, 'users' => 1]);

        $keys = array_column(hub_admin_links($this->owner), 'key');
        $this->assertContains('control', $keys, 'نظرةُ التحكّم ليست في كتالوج روابط الإدارة');

        $this->assertStringContainsString('href="' . route('control.index') . '"', $this->bar($this->owner),
            'نظرةُ التحكّم غائبةٌ عن شريط الإدارة');
        $this->assertStringContainsString('href="' . route('control.index') . '"', $this->bar($monitor),
            'حاملُ راية المراقبة يفتح نظرةَ التحكّم ولا يجد إليها رابطاً');

        // وحارسُها حارسُ متحكّمها حرفياً: من لا يفتحها لا يراها
        $this->assertStringNotContainsString(route('control.index'), $this->bar($auditor),
            'نظرةُ التحكّم ظهرت لمدقّقٍ يُصَدّ عنها بـ٤٠٣');

        $this->actingAs($this->owner);
        $this->assertContains(route('control.index'), array_column($this->dests('التحكّم'), 'u'),
            'البحثُ عن «التحكّم» لا يصل إلى نظرة التحكّم');
        auth()->logout();

        $this->actingAs($this->employee);
        $this->assertNotContains(route('control.index'), array_column($this->dests(''), 'u'),
            'الموظّفةُ تجد نظرةَ التحكّم في البحث وهي تُصَدّ عنها');
    }

    /* ───────────── ٤) البحثُ من الكتالوج نفسِه ───────────── */

    public function test_search_destinations_read_the_very_same_catalogue(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        $urls = array_column($this->dests(''), 'u');
        foreach (hub_admin_links($this->owner) as $l) {
            if (! $l['ok']) continue;
            $this->assertContains(route($l['route'], $l['args']), $urls,
                "وجهةُ «{$l['label']}» في الشريط ولا أثرَ لها في البحث");
        }

        // القائمتان كانتا متباعدتين هنا بالذات
        $this->assertContains(route('activity.index'), $urls, 'البحثُ ما زال يفتقد «نشاط الموظفين»');
        $this->assertContains(route('integrations.index'), $urls, 'البحثُ ما زال يفتقد «التكاملات»');

        // والبحثُ بالاسم يصل: الاسمُ المعروض أو اسمُه القديم في قائمة البحث
        foreach (['نشاط الموظفين' => 'activity.index', 'التكاملات' => 'integrations.index',
                  'سجل التدقيق' => 'audit.index', 'مركز الأمان' => 'security.index',
                  'التنبيهات' => 'alerts.center'] as $term => $route) {
            $this->assertContains(route($route), array_column($this->dests($term), 'u'),
                "البحثُ عن «{$term}» لا يصل إلى $route");
        }

        // وحارسُ كل وجهةٍ محفوظ: الموظّفةُ لا تجد شاشاتِ الإدارة
        auth()->logout();
        $this->actingAs($this->employee);
        $empUrls = array_column($this->dests(''), 'u');
        foreach (['settings.edit', 'security.index', 'ops.index', 'errors.index',
                  'activity.index', 'integrations.index', 'alerts.center'] as $r) {
            $this->assertNotContains(route($r), $empUrls, "الموظّفةُ تجد $r في البحث");
        }
    }

    /* ───────────── ٥) الوصولية (critic #13 · §29) ───────────── */

    public function test_the_bar_is_a_real_list_with_names_and_a_sound_focus_order(): void
    {
        $this->seedCore();

        // معلمٌ باسمٍ + قوائمُ حقيقية، ولكل كبسولةٍ اسمٌ يقرؤه القارئ الشاشيّ
        $bar = $this->bar($this->owner);
        $this->assertStringContainsString('<nav class="adminbar" aria-label="الإدارة والنظام"', $bar);
        $this->assertStringContainsString('<ul class="seg"', $bar);
        $this->assertStringContainsString('<li>', $bar);
        $this->assertSame(count(self::GROUPS), substr_count($bar, '<ul class="seg"'));
        foreach (self::GROUPS as $g) {
            $this->assertStringContainsString('aria-label="' . $g . '"', $bar,
                "الكبسولة «{$g}» قائمةٌ بلا اسم");
        }

        // ترتيبُ التنقّل ترتيبُ المصدر: لا tabindex يقفز بالمستخدم
        $this->assertStringNotContainsString('tabindex', $bar);

        // الصفحةُ الحالية مُعلَنة لا ملوّنةً فقط
        $onAudit = $this->bar($this->owner, '/admin/audit');
        $this->assertStringContainsString('aria-current="page"', $onAudit);
        $this->assertSame(1, substr_count($onAudit, 'aria-current="page"'),
            'أكثرُ من رابطٍ يدّعي أنه الصفحةُ الحالية');

        // ولا زرَّ برمزٍ وحدَه بلا اسم (الجرس كان title بلا اسمٍ صريح)
        $page = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('aria-label="التنبيهات"', $page);
    }
}
