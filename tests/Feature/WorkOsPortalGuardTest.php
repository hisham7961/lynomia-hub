<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **حارسُ البوابة قائمةٌ بيضاءُ لا سوداء** (Work OS · الطور A · WP-A.3 · SF-5 · النقد C1).
 *
 * حسابُ العميل (`account_type=client`) لا يبلغ إلا قائمةً بيضاءَ محدودة؛ وكلُّ ما
 * عداه ٤٠٤ — **فوق مصفوفة الأدوار**، فدورُ عميلٍ مُساءُ الضبط يُمنح وحدةً داخلية
 * (أو المصفوفةَ كلَّها) لا يُسرّب صفّاً. ولماذا قائمةٌ بيضاء: من ٨٢ وحدة، ٦٧ بلا
 * عمودِ عميلٍ لا يعزلها `hub_scope` أصلاً — فقائمةٌ سوداءُ من بضعِ وحداتٍ تترك
 * عشراتٍ مفتوحة. هذا الملفُّ يضرب **عيّنةً واسعةً** من الـ٦٧ لا أربعاً.
 *
 * والحارسُ لا يمسّ الداخليّ: المالكُ وكلُّ مستخدمٍ داخليّ يبلغ ما كان يبلغه —
 * لا انحدارَ على شاشةٍ داخلية (يحرسه هنا اختبارٌ صريح + AllScreensSmokeTest كاملاً).
 *
 * **تحديثُ العقد (الجولة 1 · F22):** قشرةُ الوحدات `m.*` (ومعها لوحةُ التحكّم و«بوابتي»
 * الموظفيّة) لم تعد تُفتَح لعميلٍ بتاتاً — كانت `projects/engagements/fin` تُقرأ في
 * القشرة الداخلية، وهو قرارٌ خاصٌّ بـ`/api/v1` لا بقشرةِ إنسان. صار **طلبُ التصفّح
 * البشريّ** (GET HTML) على هذه السطوح يُحوَّل ٣٠٢ إلى بوّابته (لا ٤٠٤ محيّرة)، بينما
 * يبقى طلبُ JSON/AJAX والمساراتُ العميقة/الإداريّة **٤٠٤** (لا كشفَ وجود). قراءةُ
 * العميلِ لوحداته تبقى عبر `/api/v1` (MODULE_ALLOW) وشاشاتِ بوّابته.
 */
class WorkOsPortalGuardTest extends TestCase
{
    /** عيّنةٌ واسعةٌ من الـ٦٧ وحدةً الداخليةَ التي لا يبلغها عميلٌ أبداً */
    private const INTERNAL_MODULES = [
        'servers', 'vault', 'dbs', 'domains', 'payroll', 'banks', 'costc',
        'phones', 'accounts', 'accounts2', 'emails', 'code', 'websites', 'apis',
        'budgets', 'stock', 'stockmv', 'hr', 'leaves', 'recruit', 'hrlog',
        'suppliers', 'okrs', 'krs', 'policies', 'obligations', 'compliance',
        'incidents', 'deploys', 'restores', 'requests', 'ip', 'competitors',
        'brands', 'media', 'events', 'plans', 'rules', 'feats', 'designs',
        'skills', 'services', 'products', 'subs', 'social', 'posts', 'tasks',
        'issues', 'approvals', 'apps', 'territories', 'hcps', 'companies', 'users',
    ];

    /** مساراتُ مستوى التحكّم (`/admin/*`) — كلُّها ممنوعةٌ على العميل */
    private const ADMIN_PATHS = [
        '/admin/users', '/admin/roles', '/admin/audit', '/admin/ops',
        '/admin/security/findings', '/admin/security/identity', '/admin/errors',
        '/admin/quality', '/admin/activity',
    ];

    /** يُنشئ حسابَ عميلٍ صلبٍ (account_type=client) بدورٍ ومصفوفةٍ محدَّدَين */
    private function clientUser(array $matrix = [], array $flags = []): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** يمنح الحسابَ عضويّةً فعّالةً في عميلٍ — فيعزله hub_scope على الوحدات المسموحة */
    private function activeMembership(User $u, Client $c): ClientMembership
    {
        return ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
    }

    /* ────────── ١) العميل لا يبلغ قشرةَ الوحدات الداخلية (F22) ────────── */

    public function test_a_client_account_never_reaches_internal_module_shells(): void
    {
        $this->seedCore();
        // دورٌ يمنح **كلَّ** الوحدات (v/a/e) — أقصى سوءِ ضبط: لو نجت وحدةٌ لظهرت
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $client = $this->clientUser($all);

        foreach (self::INTERNAL_MODULES as $mod) {
            // (F22) التصفّحُ البشريّ (HTML) على قشرة الوحدة يُحوَّل لبوّابته لا يُعرَض
            $this->actingAs($client)->get("/m/{$mod}")
                ->assertRedirect(route('portal.home'));
            $this->actingAs($client)->get("/m/{$mod}/create")
                ->assertRedirect(route('portal.home'));
            $this->actingAs($client)->get("/m/{$mod}/" . Str::uuid())
                ->assertRedirect(route('portal.home'));

            // …وطلبُ JSON على القشرة نفسِها يبقى ٤٠٤ (لا كشفَ وجودٍ لمن يجسّ برمجيّاً)
            $this->actingAs($client)->getJson("/m/{$mod}")
                ->assertNotFound("طلبُ JSON على /m/{$mod} يبقى ٤٠٤ لحساب عميل");
        }
    }

    /* ────────── ٢) العميل ⇐ ٤٠٤ على كل مستوى التحكّم ────────── */

    public function test_a_client_account_gets_404_on_all_control_plane_routes(): void
    {
        $this->seedCore();
        // دورٌ يحمل كلَّ الأعلام الحسّاسة أيضاً (users/audit/monitor/secrets) — لا يُسرّب
        $all = collect(array_keys(config('hub.modules')))
            ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $client = $this->clientUser($all,
            ['secrets' => 1, 'approve' => 1, 'users' => 1, 'audit' => 1, 'exp' => 1, 'monitor' => 1]);

        foreach (self::ADMIN_PATHS as $path) {
            $this->actingAs($client)->get($path)
                ->assertNotFound("مسارُ التحكّم {$path} يجب أن يكون ٤٠٤ لحساب عميل");
        }
    }

    /* ────────── ٣) الحارسُ يفوق المصفوفة ────────── */

    public function test_the_guard_beats_the_role_matrix(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركة ألف', 'stage' => 'عميل حالي']);

        // دورُ عميلٍ مُساءُ الضبط: مُنِح صراحةً وحداتٍ داخليةً حسّاسة
        $client = $this->clientUser([
            'servers' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1],
            'vault'   => ['v' => 1],
            'payroll' => ['v' => 1],
            'banks'   => ['v' => 1],
        ]);
        $this->activeMembership($client, $c);

        // المصفوفةُ فعلاً تمنح — لا اختبارَ زائف: `hub_can` يُقرّ بالصلاحية…
        $this->assertTrue(hub_can($client->fresh(), 'servers', 'v'),
            'المصفوفةُ تمنح servers:v فعلاً — فالمنعُ من الحارس لا من غيابِ الصلاحية');
        $this->assertTrue(hub_can($client->fresh(), 'payroll', 'v'));

        // …ومع ذلك الحارسُ يردّ فوق المصفوفة: التصفّحُ البشريّ يُحوَّل لبوّابته (F22)،
        // فلا شريطٌ داخليٌّ ولا صفٌّ من servers/vault/payroll/banks يُعرَض له.
        $this->actingAs($client)->get('/m/servers')->assertRedirect(route('portal.home'));
        $this->actingAs($client)->get('/m/vault')->assertRedirect(route('portal.home'));
        $this->actingAs($client)->get('/m/payroll')->assertRedirect(route('portal.home'));
        $this->actingAs($client)->get('/m/banks')->assertRedirect(route('portal.home'));

        // وطلبُ JSON على الوحدةِ الحسّاسةِ يبقى ٤٠٤ فوق المصفوفة — لا كشفَ وجود
        $this->actingAs($client)->getJson('/m/servers')->assertNotFound();
        $this->actingAs($client)->getJson('/m/vault')->assertNotFound();
    }

    /* ────────── ٤) بوّابةُ العميل تبلُغ، والقشرةُ الداخليّةُ تُحوَّل إليها (F22) ────────── */

    public function test_client_portal_is_reachable_and_internal_shell_redirects_to_it(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركة ألف', 'stage' => 'عميل حالي']);
        $client = $this->clientUser([
            'projects'    => ['v' => 1],
            'engagements' => ['v' => 1],
            'fin'         => ['v' => 1],
        ]);
        $this->activeMembership($client, $c);
        Project::create(['name' => 'مشروع العميل', 'client_id' => $c->id]);

        // (F22) قشرةُ الوحدات الداخلية ليست مكانَ عميلٍ ولو منحته المصفوفةُ الوحدة —
        // التصفّحُ البشريّ عليها يُحوَّل ٣٠٢ إلى بوّابته لا يُعرَض له شريطُها الداخليّ.
        $this->actingAs($client)->get('/m/projects')->assertRedirect(route('portal.home'));
        $this->actingAs($client)->get('/m/engagements')->assertRedirect(route('portal.home'));
        $this->actingAs($client)->get('/m/fin')->assertRedirect(route('portal.home'));
        // و«بوابتي» الموظفيّةُ داخليّةٌ رغم اسمها — تُحوَّل كذلك (F22)
        $this->actingAs($client)->get('/me')->assertRedirect(route('portal.home'));

        // وبوّابتُه هي سطحُه: الرئيسةُ ومشاريعُه (المنطَّقةُ بعميله) تُفتحان له فعلاً
        $this->actingAs($client)->get(route('portal.home'))->assertOk();
        $this->actingAs($client)->get(route('portal.projects'))->assertOk();
    }

    /* ────────── ٥) الداخليّ لا يمسّه الحارس — لا انحدار ────────── */

    public function test_an_internal_user_reaches_everything_as_before(): void
    {
        $this->seedCore();

        // المالكُ (account_type=internal افتراضاً) يبلغ ما يبلغه العميلُ ٤٠٤ عليه
        foreach (['servers', 'vault', 'payroll', 'banks', 'domains', 'hr'] as $mod) {
            $this->actingAs($this->owner)->get("/m/{$mod}")
                ->assertOk("المستخدمُ الداخليّ يجب أن يبلغ /m/{$mod} كما كان — الحارسُ لا يمسّه");
        }
        foreach (['/admin/users', '/admin/roles', '/admin/audit'] as $path) {
            $this->actingAs($this->owner)->get($path)
                ->assertOk("المستخدمُ الداخليّ يجب أن يبلغ {$path} كما كان");
        }

        // ومستخدمٌ داخليٌّ مخصَّصٌ لعملاءَ بأعيانهم (clients مأهولة، account_type=internal)
        // يبقى داخليّاً — الحارسُ لا يُحوّله عميلاً (التصنيفُ بنيويٌّ لا استنتاجيّ)
        $c = Client::create(['name' => 'شركة باء', 'stage' => 'عميل حالي']);
        $staffRole = Role::create(['name' => 'موظفُ عملاء', 'scope' => 'all', 'flags' => [],
            'matrix' => ['servers' => ['v' => 1], 'projects' => ['v' => 1]]]);
        $staff = User::create(['name' => 'موظفٌ داخليّ', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $staffRole->id, 'status' => 'نشط',
            'clients' => [$c->id], 'password_changed_at' => now()]);

        $this->assertFalse(hub_is_client($staff), 'موظفٌ داخليّ ذو clients مأهولةٍ ليس عميلاً');
        $this->actingAs($staff)->get('/m/servers')
            ->assertOk('الموظفُ الداخليُّ المخصَّصُ لعملاءَ يبلغ الوحداتِ الداخليةَ كالمعتاد');
    }
}
