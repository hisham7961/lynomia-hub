<?php

namespace Tests\Feature\Ia;

/**
 * **الصلاحيّاتُ (P2 · المانيفست §IaPermissionsTest).**
 *
 * لموظفٍ مُنطَّقٍ غيرِ مالك: الوجهاتُ غيرُ المصرَّح بها **غائبةٌ** عن
 * visibleDomains / visibleSections / systemMap / searchDestinations — لكنّ المسارَ
 * تحته **يبقى** يردُّ ٤٠٣/٤٠٤ بحرّاسه القائمة. إخفاءُ IA زينةٌ لا حارس: لو اعتمد
 * أحدٌ على الإخفاء وحدَه لَظهر هنا (المسارُ المباشرُ ما زال محروساً).
 */
class IaPermissionsTest extends IaTestCase
{
    /** الوحيدُ المرئيُّ للمُنطَّق: tasks. أُثبِت غيابُ مجالاتِ النظام والمالية والموارد… */
    public function test_unauthorized_domains_absent_from_visible_domains(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks']);

        $keys = array_keys($ia->visibleDomains($scoped));

        // مرئيّةٌ بحقّ: «العمل» (tasks + اللوحاتُ لأيّ داخليّ)
        $this->assertContains('work', $keys, 'مجالُ العمل غائبٌ رغم رؤية tasks');

        // غائبةٌ صراحةً: النظامُ والمالُ والمواردُ والقانونُ والتقنيةُ والميدانُ والمعرفة
        foreach (['administration', 'finance', 'hr', 'legalws', 'digital', 'fieldops', 'knowledge'] as $d) {
            $this->assertNotContains($d, $keys, "المجالُ غيرُ المصرَّح به ظاهرٌ للمُنطَّق: {$d}");
        }

        // المالكُ يرى كلَّ التسعة + النظام (مقابلةٌ تُثبت أنّ الغيابَ صلاحيّةٌ لا خطأُ سجلّ)
        $ownerKeys = array_keys($ia->visibleDomains($this->owner));
        foreach (['entities', 'work', 'finance', 'hr', 'digital', 'legalws', 'fieldops', 'knowledge', 'administration'] as $d) {
            $this->assertContains($d, $ownerKeys, "المجالُ {$d} غائبٌ عن المالك");
        }
    }

    /** الأقسامُ والوجهاتُ غيرُ المصرَّح بها غائبةٌ داخل مجالٍ مرئيّ */
    public function test_unauthorized_sections_and_destinations_absent(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks']);

        $sections = $ia->visibleSections($scoped, 'work');
        $this->assertArrayHasKey('exec', $sections, 'قسمُ المهام والتنفيذ غائبٌ رغم tasks');
        foreach (['support', 'goals', 'requests', 'meetings'] as $s) {
            $this->assertArrayNotHasKey($s, $sections, "قسمٌ غيرُ مصرَّحٍ به ظاهر: {$s}");
        }

        // داخلَ exec: tasks مرئيّةٌ، وزملاؤها غيرُ المصرَّح بها (updates/designs/issues) غائبون
        $modules = collect($sections['exec']['destinations'])
            ->where('type', 'module')->pluck('module')->all();
        $this->assertContains('tasks', $modules);
        foreach (['updates', 'designs', 'issues'] as $m) {
            $this->assertNotContains($m, $modules, "وحدةٌ غيرُ مصرَّحٍ بها في exec: {$m}");
        }
    }

    /** systemMap مُنطَّقةٌ: مفاتيحُها = المجالاتُ المرئيّة، بلا لوحةِ تشخيصٍ لغير المالك */
    public function test_system_map_matches_visible_domains_and_hides_diagnostic(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks']);

        $map = $ia->systemMap($scoped);
        $this->assertSame(array_keys($ia->visibleDomains($scoped)), array_keys($map['domains']),
            'شجرةُ خريطةِ النظام لا تطابق المجالاتِ المرئيّة');
        $this->assertArrayNotHasKey('administration', $map['domains'], 'النظامُ ظاهرٌ في خريطةِ المُنطَّق');
        $this->assertArrayNotHasKey('diagnostic', $map, 'لوحةُ التشخيص (للمالك) تسرّبت لغير المالك');

        // المالك: لوحةُ التشخيص حاضرة
        $this->assertArrayHasKey('diagnostic', $ia->systemMap($this->owner));
    }

    /** البحثُ مُنطَّق: وجهةٌ غيرُ مصرَّحٍ بها لا تظهر، والمصرَّحُ بها يظهر */
    public function test_search_is_permission_filtered(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks']);

        // الموردون (finance) غيرُ مرئيّةٍ للمُنطَّق → لا نتيجةَ بحثٍ لها
        $suppliers = $ia->searchDestinations($scoped, 'suppliers');
        $this->assertSame([], $suppliers, 'ظهرت وجهةٌ غيرُ مصرَّحٍ بها في بحثِ المُنطَّق (suppliers)');

        // المهامُّ مرئيّةٌ → تظهر
        $tasks = $ia->searchDestinations($scoped, 'tasks');
        $this->assertNotEmpty($tasks, 'المهامُّ المرئيّةُ لا تظهر في البحث');
        $this->assertContains('tasks', collect($tasks)->pluck('args')->flatten()->all(),
            'نتيجةُ المهامّ لا تشير إلى وحدة tasks');

        // وللمالك تظهر الموردون (البحثُ ليس معطوباً، بل مُنطَّق)
        $this->assertNotEmpty($ia->searchDestinations($this->owner, 'suppliers'));
    }

    /** الإخفاءُ ليس حارساً: المسارُ المباشرُ يبقى ٤٠٣ رغم غيابه عن التنقّل */
    public function test_hidden_destinations_are_still_enforced_by_the_route(): void
    {
        $this->seedCore();
        $scoped = $this->scopedUser(['tasks']);

        // suppliers مخفيّةٌ عن IA للمُنطَّق — لكنّ فهرسَها يردُّ ٤٠٣ (hub_can)
        $this->assertNull(collect($this->ia()->visibleSections($scoped, 'finance'))->get('procurement'),
            'قسمُ المشتريات ظاهرٌ للمُنطَّق');
        $this->actingAs($scoped)->get('/m/suppliers')->assertForbidden();

        // الإعداداتُ (النظام) مخفيّةٌ — ومسارُها يردُّ ٤٠٣ (hub_is_owner) لا يعتمد الإخفاء
        $this->actingAs($scoped)->get(route('settings.edit'))->assertForbidden();
    }
}
