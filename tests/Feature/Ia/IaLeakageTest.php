<?php

namespace Tests\Feature\Ia;

use App\Models\Station;

/**
 * **التكافؤ ومنعُ التسريب (P2 · المانيفست §IaLeakageTest · نقد C1) — الاختبارُ الحامل.**
 *
 * قاعدةُ C1: IA لا يمنحُ صلاحيةً — يفوّضُ الرؤيةَ **للمُسنِد القائم**. هذا الملفُّ
 * يُثبت أنّ حُكمَ كلِّ حارسٍ مُسمّى **يطابق** قبولَ/رفضَ المتحكّم الحقيقيّ لحساباتٍ
 * تمثيليّة (drift guard):
 *   · الرقابةُ لضابطها لا للمالك (ليست باباً موروثاً).
 *   · الجرافُ محجوبٌ عن كلِّ نافذةِ عميل.
 *   · workforce.overview للمراقب، field لِـ owner||(hr.v && monitor)، والكتالوجُ
 *     المستقلُّ (inventory/custody/journey/apps/endpoints) بحسب hub_can/الحارس.
 * ثمّ يُثبت أنّ حمولاتِ IA لا تُفشي اسمَ وحدةٍ محجوبةٍ ولا اسمَ مجالِ نظامٍ لمُنطَّق.
 *
 * الحساباتُ حيّةٌ في القاعدة (لا كائنٌ مُصطنَع): فحسابُ عميلٍ صوريٍّ لا يُنبّه
 * `isClientAccount()` فيُخطئ الحكم — تحذيرُ المانيفست.
 */
class IaLeakageTest extends IaTestCase
{
    /* ══════════ ١) الرقابة: للضابط لا للمالك — حُكمُ الحارس ══════════ */

    public function test_oversight_guard_visible_to_officer_not_owner(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $officer = $this->oversightOfficer();

        $this->assertTrue($ia->guard('oversight', $officer), 'ضابطُ الرقابةِ لا يرى الرقابة');
        $this->assertFalse($ia->guard('oversight', $this->owner), 'المالكُ يرى الرقابةَ (بابٌ موروثٌ — خطأ C1)');
        $this->assertFalse($ia->guard('oversight', $this->employee), 'داخليٌّ غيرُ رقابيٍّ يرى الرقابة');
        $this->assertFalse($ia->guard('oversight', $this->monitorUser()), 'المراقبُ يرى الرقابة');
        $this->assertFalse($ia->guard('oversight', $this->clientAccount()), 'حسابُ عميلٍ يرى الرقابة');
    }

    /** تكافؤُ HTTP: حُكمُ الحارس == قبولُ/رفضُ المتحكّم الحقيقيّ (oversight-vs-owner) */
    public function test_oversight_http_parity_officer_ok_owner_forbidden(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        $ia = $this->ia();

        $officer = $this->oversightOfficer();
        $this->assertTrue($ia->guard('oversight', $officer));
        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight?reason=' . urlencode('تدقيقُ تكافؤ'))->assertOk();

        // المالك: الحارسُ يرفض، والمتحكّمُ يجهض ٤٠٣ في gate() قبل أيّ فحصٍ آخر
        $this->assertFalse($ia->guard('oversight', $this->owner));
        $this->actingAs($this->owner)->get('/oversight?reason=' . urlencode('تدقيق'))->assertForbidden();
    }

    /* ══════════ ٢) الجراف: محجوبٌ عن نافذة العميل — حُكمُ الحارس ══════════ */

    public function test_graph_guard_hidden_from_any_client_window(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        $this->assertTrue($ia->guard('graph', $this->owner), 'المالكُ لا يرى الجراف');
        $this->assertTrue($ia->guard('graph', $this->employee), 'داخليٌّ غيرُ معزولٍ لا يرى الجراف');
        $this->assertFalse($ia->guard('graph', $this->clientAccount()), 'حسابُ عميلٍ يرى الجراف (تسريب)');
        $this->assertFalse($ia->guard('graph', $this->clientScopedUser()),
            'داخليٌّ معزولٌ بعملاءَ يرى الجراف (تسريب) — hub_client_ids != null');
    }

    /** تكافؤُ HTTP: المالك ٢٠٠ والمعزولُ بعملاءَ ٤٠٤ (graph-vs-client) */
    public function test_graph_http_parity_owner_ok_client_scoped_404(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $station = Station::create(['facility' => 'مبنى-التكافؤ', 'type' => 'مكتب']);

        $this->assertTrue($ia->guard('graph', $this->owner));
        $this->actingAs($this->owner)->get('/graph/explore?m=stations&id=' . $station->id)->assertOk();

        $scoped = $this->clientScopedUser();
        $this->assertFalse($ia->guard('graph', $scoped));
        $this->assertNotNull(hub_client_ids($scoped->fresh()), 'القارئُ ليس معزولاً بعملاءَ فعلاً');
        $this->actingAs($scoped)->get('/graph/explore?m=stations&id=' . $station->id)->assertNotFound();
    }

    /* ══════════ ٣) بقيّةُ المراكز المستقلّة: الحُكمُ يطابق hub_can/الحارس ══════════ */

    public function test_workforce_overview_only_for_monitor(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        $this->assertTrue($ia->guard('workforce_overview', $this->owner));
        $this->assertTrue($ia->guard('workforce_overview', $this->monitorUser()));
        $this->assertFalse($ia->guard('workforce_overview', $this->employee), 'غيرُ المراقب يرى نظرةَ القوى');
        $this->assertFalse($ia->guard('workforce_overview', $this->viewer));
        $this->assertFalse($ia->guard('workforce_overview', $this->scopedUser(['tasks'])));
    }

    public function test_field_guard_owner_or_hr_view_and_monitor(): void
    {
        $this->seedCore();
        $ia = $this->ia();

        $this->assertTrue($ia->guard('field', $this->owner), 'المالكُ لا يرى الميدان');
        $this->assertTrue($ia->guard('field', $this->flaggedUser(['monitor' => 1], ['hr'])),
            '(hr.v && monitor) لا يرى الميدان');
        $this->assertFalse($ia->guard('field', $this->monitorUser()), 'مراقبٌ بلا hr يرى الميدان');
        $this->assertFalse($ia->guard('field', $this->scopedUser(['hr'])), 'hr بلا monitor يرى الميدان');
        $this->assertFalse($ia->guard('field', $this->scopedUser(['tasks'])));
    }

    public function test_endpoints_and_releases_guards(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $monitor = $this->monitorUser();

        // endpoints: !client && (owner || monitor)
        $this->assertTrue($ia->guard('endpoints', $this->owner));
        $this->assertTrue($ia->guard('endpoints', $monitor));
        $this->assertFalse($ia->guard('endpoints', $this->employee), 'موظفٌ عاديٌّ يرى النقاطَ الطرفية');
        $this->assertFalse($ia->guard('endpoints', $this->clientAccount()), 'حسابُ عميلٍ يرى النقاطَ الطرفية');

        // releases: !client && owner (المراقبُ لا يراها)
        $this->assertTrue($ia->guard('endpoints_releases', $this->owner));
        $this->assertFalse($ia->guard('endpoints_releases', $monitor), 'المراقبُ يرى إصداراتِ الوكيل (للمالك وحدَه)');
        $this->assertFalse($ia->guard('endpoints_releases', $this->clientAccount()));
    }

    /** المراكزُ المستقلّةُ ذاتُ hub_can: مرئيّةٌ لمن يملك الوحدةَ، محجوبةٌ عمّن لا يملكها */
    public function test_hub_can_backed_center_guards_mirror_the_permission(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $noPerm = $this->scopedUser(['tasks']);   // لا assets/custody/clients/apps

        $map = [
            'inventory'      => 'assets',    // hub_can(assets,v)
            'custody_wallet' => 'custody',   // hub_can(custody,v) — مفتاحُ صلاحيةٍ بلا وحدةِ سجلّ
            'journey'        => 'clients',    // hub_can(clients,v)
            'apps_center'    => 'apps',       // hub_can(apps,v)
        ];
        foreach ($map as $guard => $module) {
            $this->assertTrue($ia->guard($guard, $this->scopedUser([$module])),
                "من يملك {$module} لا يرى الحارس {$guard}");
            $this->assertFalse($ia->guard($guard, $noPerm), "من لا يملك {$module} يرى الحارس {$guard}");
            $this->assertTrue($ia->guard($guard, $this->owner), "المالكُ لا يرى {$guard}");
        }
    }

    /** كلُّ الحرّاس المُسمّاة الـ١٧ مسجّلةٌ في موضعٍ واحد (drift guard) */
    public function test_all_named_guards_are_registered(): void
    {
        $names = $this->ia()->guardNames();
        $expected = ['authed', 'owner', 'monitor', 'field', 'oversight', 'graph', 'inventory',
            'custody_wallet', 'workforce_overview', 'journey', 'apps_center', 'portal_employee',
            'boards', 'endpoints', 'endpoints_releases', 'odoo_project', 'admin_bar'];
        foreach ($expected as $g) {
            $this->assertContains($g, $names, "الحارسُ المُسمّى غائبٌ عن الخدمة: {$g}");
        }
    }

    /* ══════════ ٤) لا تسريبَ اسمٍ محجوبٍ في حمولات IA لمُنطَّق ══════════ */

    public function test_no_hidden_module_or_system_name_leaks_to_a_scoped_user(): void
    {
        $this->seedCore();
        $ia = $this->ia();
        $scoped = $this->scopedUser(['tasks']);

        // كلُّ ما قد يراه المُنطَّق: خريطةُ النظام + بحثٌ واسع + أقسامُ كلِّ مجالٍ مرئيّ
        $payload = $ia->systemMap($scoped);
        foreach (array_keys($ia->visibleDomains($scoped)) as $dk) {
            $payload['_sections'][$dk] = $ia->visibleSections($scoped, $dk);
        }
        foreach (['a', 'e', 'i', 'o', 'الم', 'مركز'] as $q) {
            $payload['_search'][$q] = $ia->searchDestinations($scoped, $q);
        }
        $blob = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // اسمُ وحدةٍ محجوبةٍ (الموردون) لا يظهر في أيّ حمولة
        $this->assertStringNotContainsString(hub_mod('suppliers')['label'], $blob,
            'اسمُ وحدةٍ محجوبةٍ (suppliers) تسرّب لحمولةِ المُنطَّق');
        $this->assertStringNotContainsString(hub_mod('payroll')['label'], $blob,
            'اسمُ وحدةٍ محجوبةٍ (payroll) تسرّب لحمولةِ المُنطَّق');

        // اسمُ مجالِ النظام (الإدارة) لا يظهر لغير المخوَّل
        $this->assertStringNotContainsString(hub_ia()['domains']['administration']['label'], $blob,
            'اسمُ مجالِ النظام تسرّب لحمولةِ المُنطَّق');

        // ولا لوحةَ تشخيصٍ (تعدُّ كلَّ الوحدات) لغير المالك
        $this->assertArrayNotHasKey('diagnostic', $ia->systemMap($scoped));
    }
}
