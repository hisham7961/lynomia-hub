<?php

namespace Tests\Feature\Ia;

/**
 * **البيوتُ المكرّرة (P2 · المانيفست §IaDuplicateHomeTest · نقد C2 موسَّع).**
 *
 * لا مفتاحَ وحدةٍ أو مركزٍ يكون **بيتاً أساسيّاً** في أكثرَ من موضعٍ واحد — والفحصُ
 * يمرُّ على **السطوح والمجالات معاً** فلا يفلتُ تصادمُ مهامّي‑مقابلَ‑العمل (عيبُ
 * `boards` الذي أصلحه المانيفست: بيتٌ واحدٌ في «مهامّي»، وظهورُه في «العمل» ثانويٌّ
 * موسومٌ `primary_at=mywork`). البيتُ = ظهورٌ **بلا** `perspective` و**بلا** `primary_at`.
 *
 * لا يكتفي بسؤال الخدمة: يُعيد بناءَ الفحص من السجلّ مستقلّاً (كي يُمسِك تصادمَ
 * سطحٍ‑ومجالٍ ولو مرّرته الخدمةُ)، ثمّ يؤكّد أنّ تشخيصَ الخدمة يوافقه.
 */
class IaDuplicateHomeTest extends IaTestCase
{
    /**
     * الهُويّةُ القانونيّةُ لوجهةٍ (مفتاحٌ واحدٌ لكلّ وجهة، ثابتُ الاشتقاق) — تُوازي
     * `identities()` في الخدمة: الوحدةُ أولاً، ثمّ مركزُ الكتالوج، ثمّ الإدارةُ،
     * ثمّ المستقلُّ بمفتاحِه أو مساره. أيُّ نوعٍ آخرَ بلا مُعرِّفٍ = خطأُ بنيةٍ يُكشَف.
     */
    private function canonical(array $dest): string
    {
        if (! empty($dest['module'])) return 'm:' . $dest['module'];
        if (($dest['type'] ?? '') === 'center' && isset($dest['center'])) return 'c:' . $dest['center'];
        if (($dest['type'] ?? '') === 'admin' && isset($dest['admin'])) return 'a:' . $dest['admin'];

        return 'x:' . ($dest['key'] ?? $dest['route'] ?? 'unknown');
    }

    /** يجمعُ بيوتَ كلِّ هُويّةٍ عبر السطوح والمجالات (تجاهلُ المنظوريّ والمُحال) */
    private function collectHomes(): array
    {
        $ia = hub_ia();
        $homes = [];   // id => list of "scope:container/section"
        foreach (['surfaces' => 'surface', 'domains' => 'domain'] as $group => $scope) {
            foreach ($ia[$group] ?? [] as $ck => $node) {
                foreach ($node['sections'] ?? [] as $sk => $sec) {
                    foreach ($sec['destinations'] ?? [] as $dest) {
                        if (isset($dest['perspective']) || isset($dest['primary_at'])) continue;
                        $homes[$this->canonical($dest)][] = "{$scope}:{$ck}/{$sk}";
                    }
                }
            }
        }

        return $homes;
    }

    /** ١) لا هُويّةٌ لها بيتان — الفحصُ المستقلُّ عبر السطوح والمجالات (C2 موسَّع) */
    public function test_no_identity_is_a_primary_home_in_more_than_one_place(): void
    {
        $duplicates = [];
        foreach ($this->collectHomes() as $id => $places) {
            if (count($places) > 1) $duplicates[$id] = $places;
        }

        $msg = collect($duplicates)->map(fn ($p, $id) => "{$id} @ [" . implode(', ', $p) . ']')->implode(' ; ');
        $this->assertSame([], array_keys($duplicates), 'بيوتٌ أساسيّةٌ مكرّرة: ' . $msg);
    }

    /** ٢) تشخيصُ الخدمة يوافق: صفرُ بيتٍ مكرّر (السكّتان تتّفقان) */
    public function test_service_diagnostic_agrees_zero_duplicate_homes(): void
    {
        $this->seedCore();
        $this->assertSame([], array_keys($this->ia()->diagnostic()['duplicate_homes']));
    }

    /**
     * ٣) boards — بيتٌ أساسيٌّ **واحدٌ** في «مهامّي» (C2). ظهورُه في «العمل» موسومٌ
     * ثانويّاً `primary_at=mywork` فلا يُحسَب بيتاً. لو رجعت الازدواجيّةُ لسقط هذا.
     */
    public function test_boards_has_exactly_one_primary_home_in_my_work(): void
    {
        $this->seedCore();
        $ia = hub_ia();

        // كلُّ ظهورات boards عبر السجلّ (بمطابقة المسار boards.index)
        $appearances = [];
        foreach (['surfaces' => 'surface', 'domains' => 'domain'] as $group => $scope) {
            foreach ($ia[$group] ?? [] as $ck => $node) {
                foreach ($node['sections'] ?? [] as $sk => $sec) {
                    foreach ($sec['destinations'] ?? [] as $dest) {
                        if (($dest['route'] ?? null) === 'boards.index') {
                            $appearances[] = ['scope' => $scope, 'container' => $ck, 'section' => $sk, 'dest' => $dest];
                        }
                    }
                }
            }
        }

        // ظهوران بالضبط: بيتٌ في مهامّي + ظهورٌ ثانويٌّ في العمل
        $this->assertCount(2, $appearances, 'عددُ ظهورات boards تغيّر — راجِع C2');

        $homes = array_values(array_filter($appearances, fn ($a) => ! isset($a['dest']['primary_at'])));
        $this->assertCount(1, $homes, 'boards ببيتٍ ليس واحداً — عادت الازدواجيّة');
        $this->assertSame('surface', $homes[0]['scope']);
        $this->assertSame('mywork', $homes[0]['container']);

        // والظهورُ الآخرُ ثانويٌّ مُحالٌ إلى مهامّي
        $secondary = array_values(array_filter($appearances, fn ($a) => isset($a['dest']['primary_at'])));
        $this->assertCount(1, $secondary);
        $this->assertSame('work', $secondary[0]['container']);
        $this->assertSame('mywork', $secondary[0]['dest']['primary_at']);
        $this->assertSame('secondary', $secondary[0]['dest']['importance'] ?? null);

        // والخدمةُ تُرجع البيتَ الواحدَ في «مهامّي»
        $loc = $this->ia()->primaryLocation('boards');
        $this->assertSame(['surface', 'mywork'], [$loc['scope'] ?? null, $loc['surface'] ?? null]);
    }
}
