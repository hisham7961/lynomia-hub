<?php

namespace Tests\Feature;

use App\Support\FeatureRegistry;
use App\Support\InformationArchitecture;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * **تدقيقُ الاكتشافِ على مستوى النظام (§23/§24/§40/§43).**
 *
 * يستعمل **سجلَّ القدرات** (FeatureRegistry · مصدرُ الحقيقة) + **الهندسةَ المعلوماتية** (IA)
 * + كتالوجاتِ التنقّل، ليُثبِت الثوابتَ الختاميّة: صفرُ روابطِ تنقّلٍ ميّتة، وصفرُ قدرةٍ
 * ENABLED مُتاحةٍ للمستخدمِ بلا موقعٍ معروفٍ في IA (يتيمة)، ومركزُ التواصلِ وجهةٌ عاديّة،
 * ومركزُ القدراتِ له مسارٌ إداريٌّ واضح، والعميلُ معزولٌ عن الوجهاتِ الداخليّة.
 */
class DiscoverabilityAuditTest extends TestCase
{
    private function ia(): InformationArchitecture
    {
        return InformationArchitecture::make();
    }

    private function owner(): object
    {
        return (object) ['role' => (object) ['is_owner' => true]];
    }

    /* ═══════════ صفرُ روابطِ تنقّلٍ ميّتة (§40/§43) ═══════════ */

    public function test_no_dead_navigation_links_in_any_catalog(): void
    {
        $owner = $this->owner();
        $dead = [];
        foreach (hub_top_links($owner) as $l) {
            if (! Route::has($l['route'])) $dead[] = "top:{$l['key']}:{$l['route']}";
        }
        foreach (hub_admin_links($owner) as $l) {
            if (! Route::has($l['route'])) $dead[] = "admin:{$l['key']}:{$l['route']}";
        }
        $this->assertSame([], $dead, 'روابطُ تنقّلٍ تُشير لمسارٍ غيرِ موجود: ' . implode(' · ', $dead));
    }

    /* ═══════════ صفرُ قدرةٍ ENABLED مُتاحةٍ للمستخدمِ بلا موقعٍ في IA (§24) ═══════════ */

    public function test_every_enabled_user_facing_capability_route_is_classified_in_ia(): void
    {
        $ia = $this->ia();
        $orphans = [];
        $dead = [];
        foreach (FeatureRegistry::keys() as $k) {
            if ((FeatureRegistry::status($k)['status'] ?? '') !== 'ENABLED') continue;
            $f = FeatureRegistry::get($k);
            foreach ((array) ($f['web_routes'] ?? []) as $rn) {
                // مسارُ ويبٍ مُعلَنٌ لقدرةٍ مفعَّلة يجب أن يوجد (نافذةٌ حقيقيّة لا وهم)
                if (! Route::has($rn)) { $dead[] = "$k:$rn"; continue; }
                $params = str_starts_with($rn, 'm.') ? ['module' => 'tasks'] : [];
                $loc = $ia->routeLocation($rn, $params);
                // موقعٌ معروفٌ في IA (سطح/مجال/إدارة/…) — لا «غيرُ مصنَّف» (يتيمٌ بلا بيت)
                if (($loc['scope'] ?? '') === 'bucket' && ($loc['bucket'] ?? '') === 'unclassified') {
                    $orphans[] = "$k:$rn";
                }
            }
        }
        $this->assertSame([], $dead, 'قدرةٌ ENABLED تُشير لمسارِ ويبٍ غيرِ موجود: ' . implode(' · ', $dead));
        $this->assertSame([], $orphans, 'قدرةٌ ENABLED مُتاحةٌ بلا موقعٍ في IA (يتيمة): ' . implode(' · ', $orphans));
    }

    /* ═══════════ DEFECT A — مركزُ التواصلِ وجهةٌ في التنقّل العاديّ (لا بحثٌ فقط) ═══════════ */

    public function test_collaboration_center_is_a_normal_nav_destination(): void
    {
        // في كتالوج الروابط العلويّة (مصدرُ الشريط الجانبيّ)
        $keys = collect(hub_top_links($this->owner()))->pluck('key');
        $this->assertTrue($keys->contains('collab'), 'مركزُ التواصل ليس في التنقّل العاديّ (DEFECT A)');

        // وله موقعٌ في IA (سطح مهامّي · قسم الرسائل) لا دلوٌ غيرُ مصنَّف
        $loc = $this->ia()->routeLocation('collab.center');
        $this->assertContains($loc['scope'] ?? '', ['surface', 'domain'], 'مركزُ التواصل بلا بيتٍ في IA');
    }

    /* ═══════════ §34 — مركزُ القدراتِ له مسارٌ إداريٌّ واضح (لا بحثٌ فقط) ═══════════ */

    public function test_feature_center_has_a_clear_admin_discovery_path(): void
    {
        $adminKeys = collect(hub_admin_links($this->owner()))->pluck('key');
        $this->assertTrue($adminKeys->contains('features'), 'مركزُ القدرات ليس في كتالوج روابط الإدارة');

        $loc = $this->ia()->routeLocation('features.index');
        $this->assertSame('domain', $loc['scope'] ?? '', 'مركزُ القدرات بلا موقعٍ في IA');
        $this->assertSame('administration', $loc['domain'] ?? '', 'مركزُ القدرات ليس في مجال الإدارة');
    }

    /* ═══════════ محرّكُ تنقّلٍ واحد — مركزُ التواصلِ بيتٌ واحدٌ لا مكرَّر (§27) ═══════════ */

    public function test_collaboration_has_a_single_home_no_duplicate_nav_engine(): void
    {
        // بيتٌ أساسيٌّ واحدٌ في IA (لا ازدواج) — يكشفه التشخيص
        $loc = $this->ia()->primaryLocation('collab');
        $this->assertNotNull($loc, 'مركزُ التواصل بلا بيتٍ أساسيّ في IA');

        // ولا يظهر مرّتين في كتالوج الروابط العلويّة (لا رابطٌ مكرَّر)
        $collabTop = collect(hub_top_links($this->owner()))->where('key', 'collab');
        $this->assertCount(1, $collabTop, 'مركزُ التواصل مكرَّرٌ في كتالوج الروابط');
    }
}
