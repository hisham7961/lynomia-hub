<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **محاذاةُ الظهورِ مع البوّابة** (Permissions 360 · 04.1 · 04.2 · 04.4 · 05.4 · 06.3 · 02.4).
 *
 * لا رابطٌ يظهر ثم يُصَدُّ ٤٠٣، ولا وجهةٌ تُبلَغ بلا رابط:
 *   · 04.1 شريطُ الإدارة يُشتقُّ من الكتالوج (رابطٌ ظاهرٌ واحدٌ يكفي) — حاملُ secOps
 *     يبلغ «نظرةَ التحكّم» فيظهر له الشريطُ برابطِه، والموظّفُ العاديُّ بلا شيء.
 *   · 04.2 بلاطةُ صندوقِ الوثائق تتبع بوّابةَ متحكّمِها.
 *   · 04.4 مركزُ الهويّة يظهر لحاملِ products:v كما يقبله متحكّمُه.
 *   · 05.4 زرُّ «تطبيقٌ على المشروع» لا يظهر لمن يصدّه الخادم (projects:e).
 *   · 06.3 دليلُ الأسماء في KPI خلفَ hr:v.
 *   · 02.4 حسابُ العميلِ قراءةٌ فقط على وحداتِ m.* المسموحة.
 */
class Permissions360NavAlignmentTest extends TestCase
{
    private function user(string $email, array $matrix, array $flags = [], string $type = 'internal'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => $type, 'password_changed_at' => now()]);
    }

    /* ═══════════ 04.1 — الشريطُ من الكتالوج ═══════════ */

    public function test_admin_bar_follows_the_catalog_not_a_flag_list(): void
    {
        $this->seedCore();

        // حاملُ secOps يبلغ «نظرةَ التحكّم» (ControlController · v2.487.0) ⇒ الشريطُ يظهر له
        $sec = $this->user('barsec@test.local', [], ['secOps' => 1]);
        $this->assertTrue(hub_admin_bar_visible($sec), 'حاملُ secOps له رابطُ إدارةٍ ظاهرٌ فالشريطُ يظهر');
        $this->assertTrue(collect(hub_admin_links($sec))->firstWhere('key', 'control')['ok'],
            'رابطُ نظرةِ التحكّم ظاهرٌ له (توازي بوّابةِ v2.487.0)');

        // رائي الحوادثِ وحدَها لا يُعدُّ «إداريّاً»: بندُ الحوادثِ نسخةُ وحدةٍ عاديّة
        // (m.index) يبلغها من تنقّلِ الوحدات — لا سلطةَ إدارةٍ فلا شريط
        $inc = $this->user('barinc@test.local', ['incidents' => ['v' => 1]]);
        $this->assertFalse(hub_admin_bar_visible($inc), 'نسخةُ وحدةٍ لا تصنع مسؤولَ إدارة');

        // موظّفٌ بلا أيِّ رابطِ إدارة ⇒ لا شريط
        $emp = $this->user('barnone@test.local', ['updates' => ['v' => 1]]);
        $this->assertFalse(hub_admin_bar_visible($emp), 'من لا رابطَ له لا شريطَ له');

        // وحارسُ IA للمجالِ هو الدالّةُ نفسُها (لا انحراف)
        $ia = \App\Support\InformationArchitecture::make();
        $this->assertSame(hub_admin_bar_visible($sec), $ia->guard('admin_bar', $sec));
        $this->assertSame(hub_admin_bar_visible($emp), $ia->guard('admin_bar', $emp));
    }

    /* ═══════════ 04.2 + 04.4 — بلاطاتٌ وروابطُ تتبع بوّاباتِها ═══════════ */

    public function test_inboxdocs_tile_and_identity_link_follow_their_gates(): void
    {
        $this->seedCore();

        // 04.2 — بلا inboxdocs/files لا بلاطة؛ وبأحدِهما تظهر
        $reg = \App\Support\WidgetRegistry::resolve('links', $this->owner);
        $this->assertNotNull($reg);
        $none = $this->user('tile0@test.local', ['updates' => ['v' => 1]]);
        $tiles = collect(\App\Support\WidgetRegistry::resolve('links', $none))->pluck('r');
        $this->assertNotContains('inboxdocs.index', $tiles, 'بلا صلاحيّةٍ لا بلاطةَ صندوقِ وثائق');
        $files = $this->user('tile1@test.local', ['files' => ['v' => 1]]);
        $tiles2 = collect(\App\Support\WidgetRegistry::resolve('links', $files))->pluck('r');
        $this->assertContains('inboxdocs.index', $tiles2, 'حاملُ files:v يرى البلاطة');

        // 04.4 — حاملُ products:v وحدَها يرى مركزَ الهويّة (المتحكّمُ يقبله)
        $prod = $this->user('idprod@test.local', ['products' => ['v' => 1]]);
        $keys = collect(hub_top_links($prod))->pluck('key');
        $this->assertContains('identity', $keys, 'مركزُ الهويّةِ يظهر لحاملِ products:v');
    }

    /* ═══════════ 05.4 — زرُّ التطبيقِ لا يظهر لمن يُصَدّ ═══════════ */

    public function test_changeorder_apply_button_hidden_without_projects_edit(): void
    {
        $this->seedCore();
        $co = \App\Models\ChangeOrder::create(['title' => 'أمرُ تغييرٍ للاختبار', 'status' => 'معتمد']);

        // محرّرُ أوامرِ تغييرٍ بلا projects:e — الخادمُ يصدّه فلا يُعرَض الزرّ
        $u = $this->user('coe@test.local', ['changeorders' => ['v' => 1, 'e' => 1]]);
        $this->actingAs($u)->get(route('m.show', ['changeorders', $co->id]))
            ->assertOk()->assertDontSee('تطبيقٌ على المشروع');

        // ومعها يظهر
        $u2 = $this->user('coe2@test.local', ['changeorders' => ['v' => 1, 'e' => 1], 'projects' => ['v' => 1, 'e' => 1]]);
        $this->actingAs($u2)->get(route('m.show', ['changeorders', $co->id]))
            ->assertOk()->assertSee('تطبيقٌ على المشروع');
    }

    /* ═══════════ 06.3 — دليلُ الأسماءِ خلفَ hr:v ═══════════ */

    public function test_kpi_people_directory_requires_hr_view(): void
    {
        $this->seedCore();

        $ops = $this->user('kpiops@test.local', [], ['opsAnalytics' => 1]);
        $res = $this->actingAs($ops)->get(route('kpis.index'))->assertOk();
        $this->assertCount(0, $res->viewData('people'), 'بلا hr:v لا دليلَ أسماء');

        $hr = $this->user('kpihr@test.local', ['hr' => ['v' => 1]], ['opsAnalytics' => 1]);
        $res2 = $this->actingAs($hr)->get(route('kpis.index'))->assertOk();
        $this->assertGreaterThan(0, count($res2->viewData('people')), 'حاملُ hr:v يرى الدليل');
    }

    /* ═══════════ 02.4 — العميلُ قراءةٌ فقط على m.* المسموحة ═══════════ */

    public function test_client_is_read_only_on_allowed_modules(): void
    {
        $this->seedCore();
        $client = $this->user('roc@test.local', ['fin' => ['v' => 1, 'a' => 1, 'e' => 1]], [], 'client');

        // القراءةُ تمرّ من الحارس (قد تُقيَّد داخلَ المتحكّم — المهمُّ ليست ٤٠٤ الحارس)
        $this->assertNotSame(404, $this->actingAs($client)->get('/m/fin')->status(),
            'قراءةُ الوحدةِ المسموحةِ تصل المتحكّم');

        // والكتابةُ تُردّ ٤٠٤ من الحارس ولو لُوِّثت مصفوفتُه بـa/e
        $this->actingAs($client)->post('/m/fin', ['name' => 'x'])->assertNotFound();
        $this->actingAs($client)->post('/m/fin/bulk', [])->assertNotFound();
    }
}
