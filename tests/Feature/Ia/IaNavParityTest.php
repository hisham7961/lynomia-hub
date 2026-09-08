<?php

namespace Tests\Feature\Ia;

use App\Models\Role;
use App\Models\User;
use App\Support\Workspaces;

/**
 * **IA الطور 3 — مطابقةُ المحوّلات (Adapters Parity)**: يُثبت أنّ IA مصدرُ الحقيقة
 * لتنظيمِ المساحات في أقسام **دون أن يصير مصدراً ثانياً للوحدات** — فالقائمةُ المسطّحة
 * تبقى من `hub_nav` (لا ازدواج، لا إعادةَ ترتيب، لا حقنَ يتيم)، وIA يوزّعها فقط.
 *
 * ويحرسُ صفرَ الفقدان (spec): كلُّ وحدةٍ ومركزٍ في المساحة يبقى معروضاً بعد التقسيم،
 * وتخصيصُ المستخدم (إخفاء/تسمية/ترتيب) لا يُمَسّ (Critic C4 — hub_nav/hub_top_groups لم تُغيَّرا).
 */
class IaNavParityTest extends IaTestCase
{
    /** خريطةُ اسمِ مجموعة hub_nav → مفتاحُ مجالِ IA (عبر nav_group) */
    private function navGroupToDomain(): array
    {
        $out = [];
        foreach (config('hub_ia.domains', []) as $key => $d) {
            if (isset($d['nav_group'])) $out[$d['nav_group']] = $key;
        }

        return $out;
    }

    /** مفاتيحُ وحداتِ مجالٍ في IA (كلُّ وجهةٍ type=module في أقسامه) */
    private function domainModuleKeys(string $domainKey): array
    {
        $out = [];
        foreach (config("hub_ia.domains.$domainKey.sections", []) as $s) {
            foreach ($s['destinations'] ?? [] as $dest) {
                if (($dest['type'] ?? '') === 'module' && isset($dest['module'])) $out[] = $dest['module'];
            }
        }

        return array_values(array_unique($out));
    }

    /** كلُّ وحدةٍ في مجموعة hub_nav لها بيتٌ في مجالِ IA الذي تُشير إليه المجموعة (⊆) */
    public function test_every_hub_nav_group_module_is_homed_in_its_ia_domain(): void
    {
        $map = $this->navGroupToDomain();

        foreach (config('hub_nav', []) as $g) {
            $domain = $map[$g['g']] ?? null;
            $this->assertNotNull($domain, "مجموعةُ التنقّل «{$g['g']}» بلا مجالِ IA مُناظر (nav_group)");
            $iaModules = $this->domainModuleKeys($domain);
            foreach ($g['items'] as $mk) {
                $this->assertContains($mk, $iaModules,
                    "الوحدةُ «{$mk}» في مجموعة «{$g['g']}» بلا بيتٍ في مجال IA «{$domain}» — انحرافُ مصدر");
            }
        }
    }

    /** كلُّ مساحةٍ في hub_workspaces لها مجالٌ في IA بنفس المفتاح، والعكسُ لِمَن يُصرّح workspace */
    public function test_workspace_keys_and_ia_domains_are_linked_both_ways(): void
    {
        $domains = config('hub_ia.domains', []);

        foreach (array_keys(config('hub_workspaces', [])) as $wsKey) {
            $this->assertArrayHasKey($wsKey, $domains, "المساحة «{$wsKey}» بلا مجالِ IA بنفس المفتاح");
            $this->assertSame($wsKey, $domains[$wsKey]['workspace'] ?? null,
                "مجالُ «{$wsKey}» لا يُشير إلى مساحته عبر workspace");
        }

        // كلُّ مجالٍ يُصرّح workspace/nav_group يُشير لموجودٍ فعلاً (لا رابطَ ميّت)
        $navGroups = collect(config('hub_nav', []))->pluck('g')->all();
        foreach ($domains as $key => $d) {
            if (isset($d['workspace'])) {
                $this->assertArrayHasKey($d['workspace'], config('hub_workspaces', []),
                    "مجالُ «{$key}» يُشير لمساحةٍ غيرِ موجودة «{$d['workspace']}»");
            }
            if (isset($d['nav_group'])) {
                $this->assertContains($d['nav_group'], $navGroups,
                    "مجالُ «{$key}» يُشير لمجموعةِ تنقّلٍ غيرِ موجودة «{$d['nav_group']}»");
            }
        }
    }

    /** التخطيطُ يحفظ **نفسَ** مجموعةِ الوحدات والمراكز (لا زيادةَ ولا نقص) موزّعةً */
    public function test_workspace_layout_preserves_the_exact_module_and_center_set(): void
    {
        $this->seedCore();

        foreach (array_keys(config('hub_workspaces', [])) as $wsKey) {
            $ws = Workspaces::find($wsKey, $this->owner);
            $this->assertNotNull($ws, "المالكُ لا يرى المساحة «{$wsKey}»");

            $layout = $this->ia()->workspaceLayout($this->owner, $wsKey);

            // اتّحادُ وحداتِ الأقسام + غيرِ المصنّف = وحداتُ المساحة تماماً (ترتيباً وعدداً)
            $laidOutModules = [];
            foreach ($layout['sections'] as $sec) {
                foreach ($sec['modules'] as $mk) $laidOutModules[] = $mk;
            }
            foreach ($layout['ungrouped_modules'] as $mk) $laidOutModules[] = $mk;

            sort($laidOutModules);
            $expected = $ws['modules'];
            sort($expected);
            $this->assertSame($expected, $laidOutModules,
                "تخطيطُ «{$wsKey}» فقَدَ أو كرّر وحدةً — صفرُ فقدان مخروق");

            // ولا يُصنَّفُ لِمجالٍ ذي nav_group وحدةٌ خارجَ أقسامه (تغطيةٌ كاملة)
            $this->assertEmpty($layout['ungrouped_modules'],
                "تخطيطُ «{$wsKey}» ترك وحداتٍ بلا قسم: " . implode(',', $layout['ungrouped_modules']));

            // المراكز: الاتّحادُ محفوظٌ (بعضُها قد يقع في «غير المصنّف» لأن بيتَه مجالٌ آخر)
            $laidOutCenters = [];
            foreach ($layout['sections'] as $sec) {
                foreach ($sec['centers'] as $c) $laidOutCenters[] = $c['key'];
            }
            foreach ($layout['ungrouped_centers'] as $c) $laidOutCenters[] = $c['key'];
            sort($laidOutCenters);
            $expectedC = collect($ws['centerLinks'] ?? [])->pluck('key')->all();
            sort($expectedC);
            $this->assertSame($expectedC, $laidOutCenters,
                "تخطيطُ «{$wsKey}» فقَدَ أو كرّر مركزاً");
        }
    }

    /** الأقسامُ مرتّبةٌ بـorder، وكلُّ قسمٍ فيه وحدةٌ أو مركزٌ واحدٌ على الأقلّ */
    public function test_workspace_layout_sections_are_ordered_and_non_empty(): void
    {
        $this->seedCore();
        $layout = $this->ia()->workspaceLayout($this->owner, 'entities');

        $this->assertNotEmpty($layout['sections']);
        $orders = array_column($layout['sections'], 'order');
        $sorted = $orders; sort($sorted);
        $this->assertSame($sorted, $orders, 'أقسامُ التخطيط ليست مرتّبةً بـorder');

        foreach ($layout['sections'] as $sec) {
            $this->assertTrue(count($sec['modules']) > 0 || count($sec['centers']) > 0,
                "قسمٌ «{$sec['key']}» ظهر فارغاً");
        }
    }

    /** مستخدمٌ لا يرى وحداتِ مالية: تخطيطُ finance فارغٌ — لا تسريبَ لأسماء وحداتٍ محجوبة */
    public function test_layout_leaks_no_hidden_module_for_a_scoped_user(): void
    {
        $this->seedCore();
        // يرى الكياناتِ فقط، بلا أيِّ وحدةٍ مالية
        $u = $this->scopedUser(['companies', 'projects', 'clients']);

        $finance = $this->ia()->workspaceLayout($u, 'finance');
        $this->assertEmpty($finance['sections'], 'تخطيطُ مساحةٍ لا يراها المستخدمُ سرّب أقساماً');
        $this->assertEmpty($finance['ungrouped_modules']);

        // وما يراه صحيحٌ: الكياناتُ تُقسَّم، ولا تحمل وحدةً خارجَ صلاحيته
        $entities = $this->ia()->workspaceLayout($u, 'entities');
        $seen = [];
        foreach ($entities['sections'] as $sec) foreach ($sec['modules'] as $mk) $seen[] = $mk;
        $this->assertContains('companies', $seen);
        $this->assertNotContains('services', $seen, 'وحدةٌ خارجَ صلاحية المستخدم ظهرت في التخطيط');
    }

    /* ═════════ Critic C4 — تخصيصُ المستخدم للتنقّل يبقى محترَماً (لم نمسّ hub_nav/hub_top_groups) ═════════ */

    public function test_user_nav_hidden_named_and_ordered_still_honored(): void
    {
        $this->seedCore();

        // يخفي tasks، ويعيد تسمية issues، ويرتّب «العمل» أوّلاً
        $this->owner->prefs = ['nav' => [
            'hidden' => ['tasks'],
            'names'  => ['issues' => 'مشاكلي الخاصّة'],
            'order'  => ['العمل'],
        ]];
        $this->owner->save();

        $nav = hub_nav($this->owner->fresh());
        $work = collect($nav)->firstWhere('g', 'العمل');
        $this->assertNotNull($work);

        $keys = collect($work['items'])->pluck('key');
        $this->assertFalse($keys->contains('tasks'), 'وحدةٌ مُخفاةٌ (nav.hidden) بقيت في التنقّل');

        $issues = collect($work['items'])->firstWhere('key', 'issues');
        $this->assertSame('مشاكلي الخاصّة', $issues['label'], 'التسميةُ البديلةُ (nav.names) لم تُطبَّق');

        $this->assertSame('العمل', $nav[0]['g'], 'ترتيبُ المستخدم (nav.order) لم يُقدّم المجموعة');
    }

    public function test_user_hidden_top_link_still_honored(): void
    {
        $this->seedCore();
        $this->owner->prefs = ['nav' => ['hidden_top' => ['ceo']]];
        $this->owner->save();

        $keys = collect(hub_top_groups($this->owner->fresh()))
            ->flatMap(fn ($g) => collect($g['items'])->pluck('key'));
        $this->assertFalse($keys->contains('ceo'), 'رابطٌ علويٌّ مُخفى (nav.hidden_top) بقي');
    }

    /* ═════════ عرضُ الصفحة المُقسَّمة (WP-3.2/3.3) ═════════ */

    public function test_workspace_page_renders_ia_sections_and_keeps_all_cards(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get('/w/entities')->assertOk()
            ->assertSee('وحدات المساحة')                 // الترويسةُ العليا باقية
            ->assertSee('الشركات والمشاريع')             // عنوانُ قسم IA
            ->assertSee('العملاء والـCRM')               // عنوانُ قسمٍ آخر
            ->getContent();

        // بطاقاتُ الوحدات (class=stat) ما زالت تُعرَض — لم يتغيّر ترميزُ البطاقة
        $this->assertStringContainsString('class="stat"', $html);
    }

    public function test_workspace_page_still_shows_a_center_under_its_section(): void
    {
        $this->seedCore();
        \App\Models\Contract::create(['title' => 'عقد', 'type' => 'عقد عميل', 'status' => 'ساري']);

        // مركزُ «القانوني» بيتُه قسمُ العقود في legalws — يبقى معروضاً بعد التقسيم
        $this->actingAs($this->owner)->get('/w/legalws')->assertOk()
            ->assertSee('وحدات المساحة')
            ->assertSee('العقود والتوقيع')     // عنوانُ قسم IA
            ->assertSee('⚖️ القانوني');        // مركزٌ تحت قسمه (لا فقدان)
    }
}
