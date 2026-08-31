<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Custody;
use App\Support\Engagements;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **طبقة العمليات الموحّدة: أعمالُنا وأعمالُ عملائنا في بنيةٍ واحدة.**
 *
 * لا «نظام مشاريع عملاء» ثانٍ: الوحداتُ نفسُها صارت واعيةً بسياقها — المشروع
 * يعرف عميلَه وارتباطَه، والأصلُ يفرّق مالكَه عن مديره، والمشترياتُ تُفوتر
 * بهامش، ومبدّلُ مساحة العمل يجعل النظامَ كلَّه يعمل داخل عميلٍ واحد.
 *
 * ما يحرسه هذا الملف:
 *  1) الارتباطُ وحدةٌ قياسية كاملة، والسياقُ يمتد للمشاريع والعقود.
 *  2) مبدّلُ مساحة العميل: تصفيةٌ في القوائم والبحث، ووراثةٌ للسجل الجديد،
 *     ومفتاحُ كاشٍ مختلف — ولا يفتح ما ليس من حقّ المستخدم.
 *  3) عزلُ العملاء الصارم (users.clients): لا قوائمَ ولا رابطاً مباشراً
 *     ولا بحثاً يكشف عميلاً آخر.
 *  4) «تُدار لدينا» ≠ «ملكُنا»: أصلُ العميل خارج قيمة ممتلكاتنا.
 *  5) ربحيةُ الارتباط وفوترةُ المشتريات وصحةُ العميل من مصادرها.
 */
class ClientOperationsTest extends TestCase
{
    protected function client(string $name = 'شركة ألف'): Client
    {
        return Client::create(['name' => $name, 'stage' => 'عميل حالي']);
    }

    protected function isolated(array $matrix, array $clients): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'معزول', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'clients' => $clients, 'password_changed_at' => now()]);
    }

    /* ────────── ١) الارتباط والسياق ────────── */

    public function test_an_engagement_links_client_contracts_and_projects(): void
    {
        $this->seedCore();
        $c = $this->client();

        $this->actingAs($this->owner)->post('/m/engagements', [
            'name' => 'خدمات IT المُدارة', 'clientId' => $c->id,
            'type' => 'خدمة مُدارة', 'status' => 'نشط', 'billing' => 'عقد شهري',
            'revenue' => 12000, 'renewal' => now()->addDays(20)->toDateString(),
        ])->assertRedirect();

        $e = Engagement::where('client_id', $c->id)->firstOrFail();
        $p = Project::create(['name' => 'ترقية مركز البيانات', 'client_id' => $c->id,
            'engagement_id' => $e->id, 'status' => 'نشط']);

        // صفحةُ الارتباط: مشاريعُه وتجديدُه وبطاقةُ الربحية
        $this->actingAs($this->owner)->get('/m/engagements/' . $e->id)
            ->assertOk()->assertSee('ربحية الارتباط')->assertSee('ترقية مركز البيانات')
            ->assertSee('التجديد');

        // وصفحةُ المشروع تقول لمن هو — فتاتُ السياق الكامل
        $this->actingAs($this->owner)->get('/m/projects/' . $p->id)
            ->assertOk()->assertSee('مشروعُ عميل')
            ->assertSee('شركة ألف')->assertSee('خدمات IT المُدارة');

        // وصفحةُ العميل ٣٦٠: الأرقام ودخول المساحة
        $this->actingAs($this->owner)->get('/m/clients/' . $c->id)
            ->assertOk()->assertSee('العميل ٣٦٠°')->assertSee('دخول مساحة العمل');
    }

    /* ────────── ٢) مبدّل مساحة العمل ────────── */

    public function test_the_workspace_switcher_scopes_lists_search_and_inheritance(): void
    {
        $this->seedCore();
        $a = $this->client('شركة ألف');
        $b = $this->client('شركة باء');
        Project::create(['name' => 'مشروع ألف', 'client_id' => $a->id]);
        Project::create(['name' => 'مشروع باء', 'client_id' => $b->id]);
        Project::create(['name' => 'مشروع داخلي']);

        // بلا مساحة: الكل يظهر
        $this->actingAs($this->owner)->get('/m/projects')
            ->assertOk()->assertSee('مشروع ألف')->assertSee('مشروع باء')->assertSee('مشروع داخلي');

        // التبديل لمساحة ألف: قوائمُها وحدَها — والبحثُ كذلك
        $this->actingAs($this->owner)->post('/client-switch', ['client' => $a->id])->assertRedirect();
        $this->actingAs($this->owner)->get('/m/projects')
            ->assertOk()->assertSee('مشروع ألف')
            ->assertDontSee('مشروع باء')->assertDontSee('مشروع داخلي');
        $this->actingAs($this->owner)->get('/search?q=' . urlencode('مشروع'))
            ->assertOk()->assertSee('مشروع ألف')->assertDontSee('مشروع باء');

        // السجلُ الجديد داخل المساحة يرث عميلَها — لا يُنشأ ثم يختفي
        $this->actingAs($this->owner)->post('/m/projects', ['name' => 'مشروع وليد في المساحة'])
            ->assertRedirect();
        $this->assertSame($a->id, Project::where('name', 'مشروع وليد في المساحة')->value('client_id'));

        // ومفتاحُ الكاش يتغير بتغير المساحة — لا شاشةَ عميلٍ تُقدَّم لآخر
        $k1 = hub_scope_key('x');
        session(['hub.client' => $b->id]);
        $this->assertNotSame($k1, hub_scope_key('x'));

        // والعودةُ للمساحة الداخلية تعيد كل شيء
        $this->actingAs($this->owner)->post('/client-switch', ['client' => ''])->assertRedirect();
        $this->actingAs($this->owner)->get('/m/projects')->assertOk()->assertSee('مشروع باء');
    }

    /* ────────── ٣) العزل الصارم ────────── */

    public function test_a_client_isolated_user_cannot_reach_another_clients_records(): void
    {
        $this->seedCore();
        $a = $this->client('شركة ألف');
        $b = $this->client('شركة باء');
        $mine = Project::create(['name' => 'مشروع ألف', 'client_id' => $a->id]);
        $theirs = Project::create(['name' => 'مشروع باء السري', 'client_id' => $b->id]);
        $internal = Project::create(['name' => 'مشروع لينوميا الداخلي']);

        $u = $this->isolated(['projects' => ['v' => 1], 'clients' => ['v' => 1],
            'engagements' => ['v' => 1]], [$a->id]);

        // القوائم: عميلُه وحدَه — لا عميلَ آخر ولا الداخلي (وحدةٌ لها عمودُ عميل)
        $this->actingAs($u)->get('/m/projects')
            ->assertOk()->assertSee('مشروع ألف')
            ->assertDontSee('مشروع باء السري')->assertDontSee('مشروع لينوميا الداخلي');

        // الرابطُ المباشر لسجلِّ عميلٍ آخر: 404 لا 403 — لا إثباتَ وجود
        $this->actingAs($u)->get('/m/projects/' . $theirs->id)->assertNotFound();
        $this->actingAs($u)->get('/m/projects/' . $internal->id)->assertNotFound();

        // البحثُ لا يسرّب
        $this->actingAs($u)->get('/search?q=' . urlencode('السري'))
            ->assertOk()->assertDontSee('مشروع باء السري');

        // قائمةُ العملاء نفسُها معزولة
        $this->actingAs($u)->get('/m/clients')
            ->assertOk()->assertSee('شركة ألف')->assertDontSee('شركة باء');

        // ومحاولةُ التبديل لعميلٍ خارج نطاقه: 403 صريحة
        $this->actingAs($u)->post('/client-switch', ['client' => $b->id])->assertForbidden();
    }

    /* ────────── ٤) الملكية ≠ الإدارة ────────── */

    public function test_client_owned_assets_are_managed_but_not_counted_as_our_property(): void
    {
        $this->seedCore();
        $c = $this->client();

        Asset::create(['name' => 'سيرفرنا', 'type' => 'سيرفر', 'price' => 100]);
        $theirs = Asset::create(['name' => 'سيرفر العميل', 'type' => 'سيرفر', 'price' => 900,
            'owner_scope' => 'عميل — يُدار لدينا', 'client_id' => $c->id]);

        $this->actingAs($this->owner);
        $cat = collect(Custody::catalog())->firstWhere('type', 'سيرفر');

        $this->assertSame(2, (int) $cat['n'], 'القطعتان تُداران معاً');
        $this->assertSame(100.0, (float) $cat['value'], 'وقيمةُ ممتلكاتنا لا تحمل سيرفرَ العميل');

        // وكلُّ وظائف العهدة تعمل لأصل العميل — الإدارةُ كاملة، الملكيةُ محفوظة
        $emp = User::first();
        Custody::move($theirs, 'تسليم', $this->owner->id, now()->toDateString(), null,
            ['client_id' => $c->id]);
        $this->assertSame($this->owner->id, $theirs->fresh()->holder_id);
        $log = \App\Models\AssetCustody::where('asset_id', $theirs->id)->first();
        $this->assertSame($c->id, $log->client_id, 'سجلُّ الحيازة يحفظ لمن يُدار الأصل');
    }

    public function test_custody_handover_records_its_project_context(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع التركيب']);
        $a = Asset::create(['name' => 'لابتوب ميداني', 'type' => 'لابتوب']);

        $this->actingAs($this->owner)->post('/custody/' . $a->id . '/handover', [
            'userId' => $this->owner->id, 'at' => now()->toDateString(),
            'projectId' => $p->id, 'note' => 'لعُهدة مشروع التركيب',
        ])->assertRedirect();

        $log = \App\Models\AssetCustody::where('asset_id', $a->id)->firstOrFail();
        $this->assertSame($p->id, $log->project_id);
        $this->assertSame('مشروع التركيب', Custody::history($a->id)[0]['project'],
            'السجلُّ يسمّي المشروع لا معرّفه');
    }

    /* ────────── ٥) الفوترة والربحية والصحة ────────── */

    public function test_purchase_charge_is_computed_from_markup_unless_overridden(): void
    {
        $this->assertSame(1200.0, Engagements::charge((object) ['amount' => 1000, 'markup' => 20, 'charge' => null]));
        $this->assertSame(1500.0, Engagements::charge((object) ['amount' => 1000, 'markup' => 20, 'charge' => 1500]),
            'اليدويُّ يغلب الهامش');
        $this->assertSame(1000.0, Engagements::charge((object) ['amount' => 1000, 'markup' => null, 'charge' => null]));
    }

    public function test_engagement_profitability_reads_real_invoices_not_a_typed_number(): void
    {
        $this->seedCore();
        $c = $this->client();
        $e = Engagement::create(['name' => 'تشغيل المتجر', 'client_id' => $c->id,
            'status' => 'نشط', 'revenue' => 9000]);
        $p = Project::create(['name' => 'المتجر', 'client_id' => $c->id, 'engagement_id' => $e->id]);

        FinDocument::create(['doc_no' => 'INV-77', 'kind' => 'فاتورة مبيعات', 'project_id' => $p->id,
            'client_id' => $c->id, 'total' => 3000, 'paid' => 2000, 'state' => 'مدفوعة جزئياً']);

        $pl = Engagements::pl($e->fresh());
        $this->assertSame(3000.0, $pl['revenue'], 'المفوتَر من المستندات لا من خانة');
        $this->assertSame(2000.0, $pl['collected']);
        $this->assertSame(9000.0, $pl['contract'], 'والتعاقديُّ للمقارنة لا للادّعاء');
        $this->assertSame(1, $pl['projects']);
    }

    public function test_client_health_names_its_reasons(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = $this->client();

        $this->assertSame('أخضر', Engagements::health($c)['tone']);

        FinDocument::create(['doc_no' => 'INV-9', 'kind' => 'فاتورة مبيعات', 'client_id' => $c->id,
            'total' => 500, 'state' => 'مرسلة', 'due' => now()->subDays(10)->toDateString()]);
        \App\Models\Ticket::create(['subject' => 'النظام واقف', 'client_id' => $c->id,
            'priority' => 'عاجلة', 'status' => 'جديدة']);

        $h = Engagements::health(Client::find($c->id));
        $this->assertNotSame('أخضر', $h['tone']);
        $this->assertNotEmpty($h['why'], 'الإشارةُ تسمّي أسبابها لا تكتفي باللون');
    }
}
