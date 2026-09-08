<?php

namespace Tests\Feature\Ia;

/**
 * **تغطيةُ الوحدات (P2 · المانيفست §IaModuleCoverageTest).**
 *
 * كلُّ مفتاحٍ في `hub_modules()` له **بيتٌ أساسيٌّ واحدٌ بالضبط** أو تصنيفٌ صريح
 * (ADMIN_ONLY / SYSTEM_ONLY / DEPRECATED). الوحداتُ المرئيّةُ بلا موقعِ IA = ٠.
 * الجرثومةُ التي يمنعها: وحدةٌ تُشحن بلا بيتٍ في التنقّل (يتيمة) أو ببيتَين (ازدواج).
 *
 * لا يُكرّر سجلَّ الوحدات: يقرأ `hub_modules()` (المصدر) ويسأل الخدمةَ عن الموقع.
 */
class IaModuleCoverageTest extends IaTestCase
{
    /** ١) الجردُ الكامل: ٨٥ وحدةً، صفرُ يتيمٍ، صفرُ بيتٍ مكرّر، والمؤرشفُ مصنَّف */
    public function test_diagnostic_reports_full_coverage_zero_orphans_zero_duplicates(): void
    {
        $this->seedCore();
        $d = $this->ia()->diagnostic();

        $this->assertSame(count(hub_modules()), $d['module_count'], 'عددُ الوحدات لا يطابق السجلّ الحيّ');
        $this->assertSame([], $d['orphans'], 'وحداتٌ يتيمةٌ بلا بيتٍ ولا تصنيف: ' . implode(',', $d['orphans']));
        $this->assertSame([], array_keys($d['duplicate_homes']),
            'بيوتٌ أساسيّةٌ مكرّرة: ' . implode(',', array_keys($d['duplicate_homes'])));
        $this->assertContains('autos', $d['deprecated'], 'autos ليست مصنَّفةً مؤرشفةً');
        // المُبيَّتُ = العددُ الكلّيُّ ناقصَ المؤرشف (كلُّ ما ليس مؤرشفاً له بيت)
        $this->assertSame($d['module_count'] - count($d['deprecated']), $d['homed'],
            'وحداتٌ غيرُ مؤرشفةٍ بلا بيت');
    }

    /** ٢) كلُّ وحدةٍ إمّا مُبيَّتةٌ (primaryLocation) وإمّا مؤرشفة — لا ثالثَ لهما */
    public function test_every_module_key_resolves_to_one_home_or_deprecated(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $deprecated = array_keys(hub_ia()['deprecated'] ?? []);

        $without = [];
        foreach (array_keys(hub_modules()) as $mk) {
            if (in_array($mk, $deprecated, true)) {
                $this->assertNull($ia->primaryLocation($mk), "وحدةٌ مؤرشفةٌ لها بيتُ تنقّل: {$mk}");

                continue;
            }
            $loc = $ia->primaryLocation($mk);
            if ($loc === null) { $without[] = $mk; continue; }
            $this->assertContains($loc['scope'], ['surface', 'domain'], "بيتُ {$mk} في نطاقٍ غيرِ متوقَّع");
            $this->assertArrayHasKey('section', $loc, "بيتُ {$mk} بلا قسم");
            $this->assertArrayNotHasKey('_dup', $loc, "بيتُ {$mk} يحمل ازدواجاً");
        }
        $this->assertSame([], $without, 'وحداتٌ بلا موقعِ IA: ' . implode(',', $without));
    }

    /** ٣) لا وحدةٌ **مرئيّةٌ** للمالك (يرى الكلّ) بلا موقعِ IA — صفرٌ حرفيّاً */
    public function test_no_visible_module_lacks_an_ia_location_for_owner(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $deprecated = array_keys(hub_ia()['deprecated'] ?? []);

        $visibleWithout = [];
        foreach (array_keys(hub_modules()) as $mk) {
            if (! hub_can($this->owner, $mk, 'v')) continue;            // ليست مرئيّة → خارج الحساب
            if (in_array($mk, $deprecated, true)) continue;            // مؤرشفةٌ مصنَّفةٌ صراحةً
            if ($ia->primaryLocation($mk) === null) $visibleWithout[] = $mk;
        }
        $this->assertSame([], $visibleWithout, 'وحداتٌ مرئيّةٌ بلا موقع: ' . implode(',', $visibleWithout));
    }

    /** ٤) موظفٌ مُنطَّقٌ: كلُّ وحدةٍ يراها لها موقعٌ (لا نقصَ تنقّلٍ للمُنطَّق) */
    public function test_scoped_user_visible_modules_all_have_a_location(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks', 'fin', 'hr', 'assets']);

        foreach (['tasks', 'fin', 'hr', 'assets'] as $mk) {
            $this->assertTrue(hub_can($scoped, $mk, 'v'), "الوحدةُ {$mk} ليست مرئيّةً للمُنطَّق");
            $this->assertNotNull($ia->primaryLocation($mk), "الوحدةُ المرئيّةُ {$mk} بلا موقعِ IA");
        }
    }

    /** ٥) الأيتامُ الخمسةُ مُسنَدةٌ صراحةً ببيتٍ + وسمٍ حيث يلزم (المانيفست §orphans) */
    public function test_five_orphans_are_explicitly_homed_with_tags(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        // users → الإدارة/المستخدمون + وسم ADMIN_ONLY
        $this->assertSame(['administration', 'users'],
            [$ia->primaryLocation('users')['domain'] ?? null, $ia->primaryLocation('users')['section'] ?? null]);
        $this->assertSame('ADMIN_ONLY',
            hub_ia()['domains']['administration']['sections']['users']['destinations'][0]['tag'] ?? null);

        // restores → الإدارة/التشغيل + وسم SYSTEM_ONLY
        $this->assertSame(['administration', 'ops'],
            [$ia->primaryLocation('restores')['domain'] ?? null, $ia->primaryLocation('restores')['section'] ?? null]);
        $restoresTag = collect(hub_ia()['domains']['administration']['sections']['ops']['destinations'])
            ->firstWhere('module', 'restores')['tag'] ?? null;
        $this->assertSame('SYSTEM_ONLY', $restoresTag);

        // endpoints, stations → التقنية/البنية التحتية
        foreach (['endpoints', 'stations'] as $mk) {
            $this->assertSame(['digital', 'infra'],
                [$ia->primaryLocation($mk)['domain'] ?? null, $ia->primaryLocation($mk)['section'] ?? null],
                "اليتيمُ {$mk} ليس في التقنية/البنية التحتية");
        }

        // autos → مؤرشفٌ بلا بيتٍ، والمسارُ m.index[autos] يبقى يعمل (صفر فقدان)
        $this->assertNull($ia->primaryLocation('autos'));
        $loc = $ia->routeLocation('m.index', ['module' => 'autos']);
        $this->assertSame('deprecated', $loc['scope']);
        $this->assertSame('DEPRECATED_CONFIRMED', $loc['status'] ?? null);
    }
}
