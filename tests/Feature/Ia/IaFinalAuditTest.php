<?php

namespace Tests\Feature\Ia;

use Illuminate\Support\Facades\Route;

/**
 * **IA الطور 10 — التدقيقُ الختاميُّ (§84) والبوّابة (§87)**: النسخةُ الحيّةُ الآليّةُ
 * من `06-final-audit.md`. يُثبت **صفرَ فقدان** رقميّاً: كلُّ وحدةٍ مُبيَّتةٌ أو مؤرشفة،
 * كلُّ مسارٍ مُصنَّف، لا بيتَ مكرّراً، والمسارات **مجموعةٌ فوقيّة** (لا حذف — إضافةٌ فقط).
 */
class IaFinalAuditTest extends IaTestCase
{
    /** §84 — يتيم = 0، بيتٌ مكرّر = 0، وكلُّ وحدةٍ مُصنَّفة (مُبيَّتة ∪ مؤرشفة) */
    public function test_zero_orphans_zero_duplicates_every_module_classified(): void
    {
        $this->seedCore();
        $dg = $this->ia()->diagnostic();

        $this->assertSame([], $dg['orphans'], 'وحداتٌ يتيمة: ' . implode('، ', $dg['orphans']));
        $this->assertSame([], array_keys($dg['duplicate_homes']),
            'بيوتٌ مكرّرة: ' . implode('، ', array_keys($dg['duplicate_homes'])));
        $this->assertSame($dg['module_count'], $dg['homed'] + count($dg['deprecated']),
            'وحداتٌ لا مُبيَّتةٌ ولا مؤرشفة (صفرُ فقدان مخروق)');
        // autos وحدَها مؤرشفةٌ ومسارُها m.index حيّ (صفر فقدان بالحذف)
        $this->assertSame(['autos'], $dg['deprecated']);
    }

    /** §84 — كلُّ مسارِ GET مُسمّى يُصنَّف (غيرُ مصنَّف = 0) — نظيرُ IaRouteCoverage، بوّابةٌ ختاميّة */
    public function test_zero_unclassified_named_get_routes(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $unclassified = [];
        $count = 0;
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true)) continue;
            $name = $r->getName();
            if (! $name) continue;
            $count++;
            $params = str_starts_with($name, 'm.') ? ['module' => 'tasks'] : ($name === 'workspace' ? ['key' => 'entities'] : []);
            $loc = $ia->routeLocation($name, $params);
            if (($loc['scope'] ?? '') === 'bucket' && ($loc['bucket'] ?? '') === 'unclassified') {
                $unclassified[] = $name;
            }
        }
        $this->assertSame([], $unclassified, 'مساراتٌ غيرُ مصنَّفة: ' . implode('، ', $unclassified));
        $this->assertGreaterThanOrEqual(216, $count, 'عددُ مسارات GET أقلُّ من خطِّ الأساس (216) — احتمالُ حذف');
    }

    /** صفرُ حذفٍ للمسار: المسارانِ المُضافانِ فقط جديدان، وكلُّ مسارٍ قديمٍ باقٍ */
    public function test_new_routes_present_and_nothing_removed(): void
    {
        $this->seedCore();
        // المسارانِ الجديدانِ الوحيدانِ في البرنامج
        $this->assertNotNull(Route::getRoutes()->getByName('system-map'), 'system-map غائب');
        $this->assertNotNull(Route::getRoutes()->getByName('mobile.navigation'), 'mobile.navigation غائب');

        // عيّناتٌ من كلِّ صنفٍ قديمٍ ما زالت مُسجَّلة (لا حذفَ عقدٍ ولا رابط)
        foreach (['dashboard', 'search', 'workspace', 'm.index', 'm.show', 'audit.index',
                  'settings.edit', 'boards.index', 'mobile.bootstrap', 'mobile.sync'] as $n) {
            $this->assertNotNull(Route::getRoutes()->getByName($n), "مسارٌ قديمٌ اختفى: {$n}");
        }
    }

    /** §87 — كلُّ مساحةٍ عملٍ يراها المالكُ لها تخطيطٌ بلا وحدةٍ خارجَ قسم (تغطيةٌ كاملة) */
    public function test_every_workspace_layout_is_fully_covered(): void
    {
        $this->seedCore();
        foreach (array_keys(config('hub_workspaces', [])) as $wsKey) {
            $layout = $this->ia()->workspaceLayout($this->owner, $wsKey);
            $this->assertEmpty($layout['ungrouped_modules'],
                "مساحةُ «{$wsKey}» تركت وحداتٍ بلا قسم: " . implode('،', $layout['ungrouped_modules']));
        }
    }
}
