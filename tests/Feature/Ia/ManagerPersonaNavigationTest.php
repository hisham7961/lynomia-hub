<?php

namespace Tests\Feature\Ia;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * **قبولُ شخصيّةِ «المدير» (persona Manager) — تطابقُ التنقّلِ مع التفويضِ الخادميّ (§31).**
 *
 * لا رايةَ «مدير» مُدمَجةٌ في النموذج؛ فالمديرُ هنا **دورٌ تشغيليٌّ حقيقيّ**: صلاحيّةُ عملٍ
 * واسعةٌ على وحداتِ التشغيل + رايةُ الاعتماد (`approve`)، بلا مالكٍ ولا رايةِ إدارة/أمن/مراقبة
 * (`users`/`audit`/`secrets`/`monitor`). لا يُمنَح امتيازٌ لِيُنجِحَ اختباراً — يُختبَر النموذجُ
 * القائمُ كما هو: **الرؤيةُ من الحارس، لا الحارسُ من الرؤية** (Critic C1).
 *
 * يُثبِت: التنقّلُ التشغيليُّ مُكتشَف · مركزُ التواصلِ مُكتشَف · السياقيُّ المُصرَّحُ مبلوغ ·
 * مركزُ القدراتِ (للمالك) محجوب · الإدارة/الأمن/المطوّر لا تُسرَّب · البحثُ ولوحةُ الأوامر
 * مُنطَّقان · المسارُ المباشرُ خادميُّ الحسم · لا يُعامَل معاملةَ عميل.
 */
class ManagerPersonaNavigationTest extends IaTestCase
{
    /** الوحداتُ التشغيليّةُ التي يديرها المديرُ (عرض/إضافة/تعديل — لا حذف) */
    private const OPS_MODULES = ['tasks', 'projects', 'clients', 'hr', 'assets', 'fin',
        'tickets', 'requests', 'approvals', 'meetings', 'decisions'];

    /** وحدةٌ **خارجَ** نطاقِ المدير — لإثباتِ أنّ غيرَ المُصرَّحِ لا يُكتشَف ولا يُبلَغ */
    private const DENIED_MODULE = 'payroll';

    /** مساراتٌ للمالكِ حصراً — يجب ألّا تظهر للمدير في تنقّلٍ ولا بحثٍ ولا مباشرة */
    private const OWNER_ONLY_ROUTES = ['features.index', 'settings.edit', 'security.index',
        'ops.index', 'errors.index', 'roles.index', 'quality.index', 'integrations.index'];

    private function manager(): User
    {
        $matrix = collect(self::OPS_MODULES)
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'مدير ' . Str::random(4), 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => $matrix]);

        return $this->makeUser($role);
    }

    /** مفاتيحُ الوجهاتِ المرئيّةِ في الكتالوج (مصدرُ لوحةِ الأوامر والبحثِ عند التركيز) */
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

    /** مسارات وجهات IA المطابقة لاستعلامِ بحثٍ (مع وسمِ الوحدة) */
    private function searchRouteKeys(User $u, string $q): array
    {
        $out = [];
        foreach ($this->ia()->searchDestinations($u, $q) as $d) {
            if (empty($d['route'])) continue;
            $key = $d['route'];
            if ($d['route'] === 'm.index' && ! empty($d['args'])) $key .= ':' . implode(',', $d['args']);
            $out[] = $key;
        }

        return array_values(array_unique($out));
    }

    /* ═══════════ داخليٌّ لا عميل ═══════════ */

    public function test_manager_is_internal_not_a_client(): void
    {
        $this->seedCore();
        $m = $this->manager();

        $this->assertFalse(hub_is_client($m), 'المديرُ عُومِل معاملةَ عميل');
        // يرى الشريطَ الداخليَّ (مساحاتِ العمل) لا بوّابةَ العميل
        $this->actingAs($m)->get('/')->assertOk()->assertSee('مساحات العمل');
    }

    /* ═══════════ التنقّلُ التشغيليُّ مُكتشَف ═══════════ */

    public function test_manager_operational_navigation_is_discoverable(): void
    {
        $this->seedCore();
        $m = $this->manager();

        // مجالاتُ العملِ التي يرى وحداتِها ظاهرةٌ في IA (لا الإدارة)
        $domains = array_keys($this->ia()->visibleDomains($m));
        $this->assertContains('work', $domains, 'مجالُ العملِ محجوبٌ عن المدير');
        $this->assertContains('entities', $domains, 'مجالُ الكياناتِ محجوبٌ عن المدير');
        $this->assertNotContains('administration', $domains, 'مجالُ الإدارةِ ظهر للمدير');

        // ووحدةٌ يديرها مبلوغةٌ مباشرةً (خادميّاً)
        $this->actingAs($m)->get(route('m.index', 'tasks'))->assertOk();
    }

    /* ═══════════ مركزُ التواصلِ مُكتشَفٌ للمدير ═══════════ */

    public function test_manager_collaboration_center_is_discoverable(): void
    {
        $this->seedCore();
        $m = $this->manager();

        $keys = collect(hub_top_links($m))->pluck('key');
        $this->assertTrue($keys->contains('collab'), 'مركزُ التواصلِ غيرُ مُكتشَفٍ للمدير');
        $this->actingAs($m)->get(route('collab.center'))->assertOk();
    }

    /* ═══════════ السياقيُّ المُصرَّحُ مبلوغٌ حيث يُسمَح ═══════════ */

    public function test_manager_contextual_surfaces_reachable_only_where_permitted(): void
    {
        $this->seedCore();
        $m = $this->manager();

        // رحلةُ العميل (حارسُها hub_can(clients,'v')) — للمدير clients، فمرئيّة
        $journey = ['type' => 'entity', 'route' => 'journey', 'guard' => 'journey'];
        $this->assertTrue($this->ia()->destinationVisible($journey, $m), 'سياقٌ مُصرَّحٌ (رحلةُ العميل) محجوبٌ عن المدير');

        // مركزُ التطبيق (hub_can(apps,'v')) — لا apps للمدير، فمحجوب (لا تسريبَ سياقيّ)
        $appsCenter = ['type' => 'entity', 'route' => 'apps.center', 'guard' => 'apps_center'];
        $this->assertFalse($this->ia()->destinationVisible($appsCenter, $m), 'سياقٌ غيرُ مُصرَّحٍ ظهر للمدير');
    }

    /* ═══════════ مركزُ القدراتِ (للمالك) محجوبٌ عن المدير ═══════════ */

    public function test_manager_owner_only_feature_center_is_unavailable(): void
    {
        $this->seedCore();
        $m = $this->manager();

        // ليس في كتالوج روابطِ إدارته (ok=owner)
        $adminOk = collect(hub_admin_links($m))->firstWhere('key', 'features');
        $this->assertFalse((bool) ($adminOk['ok'] ?? false), 'مركزُ القدراتِ ظهر في إدارةِ المدير');

        // ولا في الكتالوج المُنطَّق (البحث/اللوحة)
        $this->assertNotContains('features.index', $this->catalogRouteKeys($m), 'مركزُ القدراتِ يُكتشَف للمدير');

        // والمسارُ المباشرُ يُصَدُّ خادميّاً (ليس 200)
        $this->assertNotSame(200, $this->actingAs($m)->get(route('features.index'))->status(),
            'المديرُ بلغ مركزَ القدرات (٢٠٠) — للمالك حصراً');
    }

    /* ═══════════ الإدارة/الأمن/المطوّر لا تُسرَّب ═══════════ */

    public function test_manager_restricted_admin_and_security_surfaces_are_not_leaked(): void
    {
        $this->seedCore();
        $m = $this->manager();

        $catalog = $this->catalogRouteKeys($m);
        foreach (self::OWNER_ONLY_ROUTES as $r) {
            $this->assertNotContains($r, $catalog, "مسارٌ للمالكِ «{$r}» سُرِّب للمدير في الكتالوج");
            $this->assertNotSame(200, $this->actingAs($m)->get(route($r))->status(),
                "المديرُ بلغ «{$r}» (٢٠٠) — مسارٌ مقيَّد");
        }
    }

    /* ═══════════ البحثُ العامُّ مُنطَّق ═══════════ */

    public function test_manager_global_search_does_not_reveal_unauthorized_destinations(): void
    {
        $this->seedCore();
        $m = $this->manager();

        // استعلاماتٌ تُطابِق وجهاتٍ للمالكِ لو كانت مرئيّة — يجب ألّا تظهر
        $this->assertNotContains('settings.edit', $this->searchRouteKeys($m, 'الإعدادات'));
        $this->assertNotContains('features.index', $this->searchRouteKeys($m, 'القدرات'));
        $this->assertNotContains('security.index', $this->searchRouteKeys($m, 'الأمان'));

        // ووحدةٌ يديرها تظهر (لا حجبٌ خاطئ)
        $this->assertContains('m.index:tasks', $this->searchRouteKeys($m, 'مهام'), 'وحدةٌ مُصرَّحةٌ لم تظهر في بحث المدير');
    }

    /* ═══════════ لوحةُ الأوامر مُنطَّقة ═══════════ */

    public function test_manager_command_palette_does_not_reveal_unauthorized_destinations(): void
    {
        $this->seedCore();
        $m = $this->manager();

        $catalog = $this->catalogRouteKeys($m);
        // وحدةٌ غيرُ مُصرَّحةٍ (payroll) لا تُكتشَف
        $this->assertNotContains('m.index:' . self::DENIED_MODULE, $catalog, 'وحدةٌ غيرُ مُصرَّحةٍ سُرِّبت في اللوحة');
        // مركزُ التواصلِ يُكتشَف
        $this->assertContains('collab.center', $catalog, 'مركزُ التواصلِ غائبٌ عن لوحةِ المدير');
    }

    /* ═══════════ المسارُ المباشرُ خادميُّ الحسم ═══════════ */

    public function test_manager_direct_route_access_stays_server_authoritative(): void
    {
        $this->seedCore();
        $m = $this->manager();

        // وحدةٌ خارجَ نطاقه تُصَدُّ ولو كتب رابطَها
        $this->assertNotSame(200, $this->actingAs($m)->get(route('m.index', self::DENIED_MODULE))->status(),
            'المديرُ بلغ وحدةً خارجَ نطاقه بكتابةِ الرابط');
        // ووحدةٌ في نطاقه تُبلَغ
        $this->actingAs($m)->get(route('m.index', 'projects'))->assertOk();
    }
}
