<?php

namespace Tests\Feature\Ia;

use App\Models\User;

/**
 * **قبولُ شخصيّةِ «المراقب» (persona Monitor) — تطابقُ التنقّلِ مع التفويضِ الخادميّ (§31).**
 *
 * المراقبُ هو الدورُ القائمُ في المستودع: **رايةُ `monitor` فقط، بلا مصفوفةِ صلاحيّات**
 * (`hub_monitor`=true · `hub_can`=false لكلِّ وحدة). لا تُوسَّع صلاحيّتُه لِيُنجِحَ اختبار —
 * يُختبَر ما يقوله الحارسُ فعلاً.
 *
 * يُثبِت: لوحاتُ المراقبةِ مُكتشَفة · التحكّم (owner||monitor) مبلوغٌ ومُكتشَفٌ بالبحث/اللوحة ·
 * الكتابةُ والإدارةُ المقيَّدةُ محجوبة · مركزُ القدراتِ للمالكِ حصراً · وحداتُ الموظّف/العميل/
 * الأمن/المطوّر لا تُسرَّب · البحثُ ولوحةُ الأوامر مُنطَّقان · المسارُ المباشرُ محميٌّ بالحارس القائم.
 */
class MonitorPersonaNavigationTest extends IaTestCase
{
    /** مساراتٌ للمالكِ حصراً — محجوبةٌ عن المراقب (تنقّلاً وبحثاً ومباشرة) */
    private const OWNER_ONLY_ROUTES = ['features.index', 'settings.edit', 'security.index',
        'ops.index', 'errors.index', 'roles.index', 'integrations.index', 'activity.index'];

    private function catalogRouteKeys(User $u): array
    {
        $out = [];
        foreach ($this->ia()->catalogDestinations($u) as $d) {
            if (empty($d['route'])) continue;
            $key = $d['route'];
            if ($d['route'] === 'm.index' && ! empty($d['args'])) $key .= ':' . implode(',', $d['args']);
            $out[] = $key;
        }

        return array_values(array_unique($out));
    }

    private function searchRouteKeys(User $u, string $q): array
    {
        $out = [];
        foreach ($this->ia()->searchDestinations($u, $q) as $d) {
            if (! empty($d['route'])) $out[] = $d['route'];
        }

        return array_values(array_unique($out));
    }

    /* ═══════════ لوحاتُ المراقبةِ مُكتشَفة ═══════════ */

    public function test_monitor_monitoring_surfaces_are_discoverable(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        // لوحاتُ الأداء/التكلفة في الشريط (اللوحات والمراكز · حارس $mon)
        $keys = collect(hub_top_links($mon))->pluck('key');
        $this->assertTrue($keys->contains('perf'), 'لوحةُ الأداءِ محجوبةٌ عن المراقب');
        $this->actingAs($mon)->get(route('performance'))->assertOk();

        // نظرةُ التحكّم (owner||monitor) — مُصرَّحةٌ خادميّاً ومُكتشَفةٌ بالبحث/اللوحة
        $controlAdmin = collect(hub_admin_links($mon))->firstWhere('key', 'control');
        $this->assertTrue((bool) ($controlAdmin['ok'] ?? false), 'نظرةُ التحكّمِ محجوبةٌ عن المراقب');
        $this->actingAs($mon)->get(route('control.index'))->assertOk();
        $this->assertContains('control.index', $this->catalogRouteKeys($mon),
            'نظرةُ التحكّمِ غيرُ مُكتشَفةٍ للمراقب في الكتالوج (بحث/لوحة)');
    }

    /* ═══════════ مركزُ التواصلِ يتبع قواعدَ الدور (داخليٌّ ⇒ مرئيّ) ═══════════ */

    public function test_monitor_collaboration_visibility_follows_role_rules(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        $keys = collect(hub_top_links($mon))->pluck('key');
        $this->assertTrue($keys->contains('collab'), 'مركزُ التواصلِ محجوبٌ عن المراقب الداخليّ');
        $this->actingAs($mon)->get(route('collab.center'))->assertOk();
    }

    /* ═══════════ الكتابةُ والإدارةُ المقيَّدةُ محجوبة ═══════════ */

    public function test_monitor_restricted_write_and_admin_actions_stay_unavailable(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        // بلا مصفوفة: لا تعديلَ على أيِّ وحدة
        $this->assertFalse(hub_can($mon, 'tasks', 'e'), 'المراقبُ نال تعديلاً بلا مصفوفة');
        $this->assertFalse(hub_can($mon, 'hr', 'v'), 'المراقبُ نال عرضَ وحدةٍ بلا مصفوفة');

        // الإعداداتُ والإدارةُ للمالكِ حصراً — يُصَدُّ خادميّاً
        $this->assertNotSame(200, $this->actingAs($mon)->get(route('settings.edit'))->status(),
            'المراقبُ بلغ الإعدادات (٢٠٠)');
    }

    /* ═══════════ مركزُ القدراتِ للمالكِ حصراً ═══════════ */

    public function test_monitor_feature_center_remains_owner_only(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        $adminOk = collect(hub_admin_links($mon))->firstWhere('key', 'features');
        $this->assertFalse((bool) ($adminOk['ok'] ?? false), 'مركزُ القدراتِ ظهر للمراقب');
        $this->assertNotContains('features.index', $this->catalogRouteKeys($mon), 'مركزُ القدراتِ يُكتشَف للمراقب');
        $this->assertNotSame(200, $this->actingAs($mon)->get(route('features.index'))->status(),
            'المراقبُ بلغ مركزَ القدرات (٢٠٠) — للمالك حصراً');
    }

    /* ═══════════ وحداتُ الموظّف/الأمن/المطوّر لا تُسرَّب ═══════════ */

    public function test_monitor_unauthorized_employee_security_developer_surfaces_not_leaked(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        // مجالُ الإدارةِ محجوبٌ (حارسُ admin_bar لا يشمل المراقب)
        $domains = array_keys($this->ia()->visibleDomains($mon));
        $this->assertNotContains('administration', $domains, 'مجالُ الإدارةِ ظهر للمراقب');

        // النموذجُ المقصود: المراقبُ قد يرى مجالاً تشغيليّاً (كالموارد البشريّة) **لكن**
        // بأقسامِ المراقبةِ وحدَها (نظرةُ القوى/الأداء/القدرات — كلُّها مُصرَّحةٌ له)، ولا تُسرَّب
        // **وحدةُ** الموظّفين (البياناتُ التشغيليّة) قطّ — فالتسريبُ يُقاس على الوحدةِ لا المجال.
        $hrLeaked = false;
        foreach ($this->ia()->visibleSections($mon, 'hr') as $s) {
            foreach ($s['destinations'] as $d) {
                if (($d['type'] ?? '') === 'module' && ($d['module'] ?? '') === 'hr') $hrLeaked = true;
            }
        }
        $this->assertFalse($hrLeaked, 'وحدةُ الموظّفين (بياناتٌ تشغيليّة) سُرِّبت للمراقب في مجال الموارد البشريّة');

        // ومساراتُ الوحدةِ/الأمنِ تُصَدُّ خادميّاً (الحسمُ في المتحكّم لا في التنقّل)
        $this->assertNotSame(200, $this->actingAs($mon)->get(route('m.index', 'hr'))->status(),
            'المراقبُ بلغ وحدةَ الموارد البشريّة (٢٠٠)');
        $this->assertNotSame(200, $this->actingAs($mon)->get(route('security.index'))->status(),
            'المراقبُ بلغ مركزَ الأمان (٢٠٠)');
    }

    /* ═══════════ البحثُ العامُّ مُنطَّق ═══════════ */

    public function test_monitor_global_search_is_permission_filtered(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        $this->assertNotContains('settings.edit', $this->searchRouteKeys($mon, 'الإعدادات'));
        $this->assertNotContains('features.index', $this->searchRouteKeys($mon, 'القدرات'));
        // لوحةُ الأداءِ المُصرَّحةُ تظهر (لا حجبٌ خاطئ)
        $this->assertContains('performance', $this->searchRouteKeys($mon, 'الأداء'), 'لوحةُ الأداءِ لم تظهر في بحث المراقب');
    }

    /* ═══════════ لوحةُ الأوامر مُنطَّقة ═══════════ */

    public function test_monitor_command_palette_is_permission_filtered(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        $catalog = $this->catalogRouteKeys($mon);
        foreach (['features.index', 'settings.edit', 'security.index'] as $r) {
            $this->assertNotContains($r, $catalog, "مسارٌ للمالكِ «{$r}» سُرِّب في لوحةِ المراقب");
        }
        // ووحدةٌ لا يراها (hr) لا تُكتشَف
        $this->assertNotContains('m.index:hr', $catalog, 'وحدةٌ غيرُ مُصرَّحةٍ سُرِّبت في لوحةِ المراقب');
        // ومركزُ التواصلِ والتحكّمُ يُكتشَفان
        $this->assertContains('collab.center', $catalog);
    }

    /* ═══════════ المسارُ المباشرُ محميٌّ بالحارس القائم ═══════════ */

    public function test_monitor_direct_urls_stay_protected_by_existing_guards(): void
    {
        $this->seedCore();
        $mon = $this->monitorUser();

        foreach (self::OWNER_ONLY_ROUTES as $r) {
            $this->assertNotSame(200, $this->actingAs($mon)->get(route($r))->status(),
                "المراقبُ بلغ «{$r}» (٢٠٠) — مسارٌ مقيَّد");
        }
    }
}
