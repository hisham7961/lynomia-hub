<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\DeliveryController;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Quote;
use App\Models\QuoteMilestone;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **لوحةُ PSA التشغيليّة** (Work OS · الطور D · WP-D.3 · §17) — تجميعٌ فوق سكّة
 * العرض→المشروع، لا محرّكَ صحةٍ/ربحيةٍ/معالمَ ثانٍ. يضرب هذا الملفُّ أربعَ حقائق:
 *   ① ازدواجُ فوترة المعلم يبقى مسدوداً في بديل «المعالم المستحقة» (correctness).
 *   ② الطبقاتُ مرتَّبةٌ حتميّاً بـid لا بـcreated_at (قرعةٌ تُخفي نقصاً — CLAUDE.md).
 *   ③ حسابُ العميل يُردّ ٤٠٤ على `delivery.psa` (PortalGuard فوق المصفوفة)؛ والداخليّ يبلغ.
 *   ④ عزلُ الشركة قائمٌ في اللوحة (يمتدّ في CompanyIsolationTest كذلك).
 */
class WorkOsPsaBoardTest extends TestCase
{
    /* ────────── مساعدات البذر ────────── */

    private function extProject(Client $c, array $attrs = []): Project
    {
        return Project::create(array_merge(
            ['name' => 'مشروع ' . Str::random(4), 'client_id' => $c->id, 'status' => 'قيد التنفيذ'],
            $attrs));
    }

    private function quote(Client $c, Project $p, string $status = 'مقبول', array $extra = []): Quote
    {
        return Quote::create(array_merge([
            'doc_no' => 'Q-PSA-' . strtoupper(Str::random(5)), 'title' => 'عرضٌ بجدول',
            'status' => $status, 'accepted_at' => $status === 'مقبول' ? now()->subDays(10) : null,
            'client_id' => $c->id, 'project_id' => $p->id, 'total' => 1000, 'tax' => 100,
            'amount' => 900, 'currency' => 'د.ك'], $extra));
    }

    private function ms(Quote $q, array $attrs = []): QuoteMilestone
    {
        return QuoteMilestone::create(array_merge(
            ['quote_id' => $q->id, 'title' => 'دفعةٌ أولى', 'pct' => 30, 'sort' => 1], $attrs));
    }

    /** حسابُ عميلٍ صلبٍ بدورٍ ومصفوفةٍ محدَّدَين — على نمط WorkOsPortalGuardTest */
    private function clientUser(array $matrix = []): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** يقرأ صفوفَ طبقةٍ من ناتج lanes() — تأكيدٌ على كلّ الصفوف لا عيّنة */
    private function laneIds(array $lanes, string $key): array
    {
        $lane = collect($lanes)->firstWhere('key', $key);

        return array_map(fn ($r) => $r['id'], $lane['rows'] ?? []);
    }

    /* ────────── ① ازدواجُ الفوترة يبقى مسدوداً (reached-not-invoiced) ────────── */

    public function test_double_billing_stays_blocked_in_milestones_due_lane(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'عميلُ التسليم', 'stage' => 'عميل حالي']);
        $p = $this->extProject($c);
        $q = $this->quote($c, $p);
        $m = $this->ms($q, ['reached_at' => now()->subDays(5)]);   // بُلغ بلا فاتورة → مستحقّ

        // مستحقٌّ الآن: المشروعُ في بديل المعالم، وقيمتُه ٣٠٪ من ١٠٠٠
        $lanes = (new DeliveryController)->lanes();
        $this->assertContains($p->id, $this->laneIds($lanes, 'milestones'),
            'معلمٌ بُلغ ولم يُفوتَر لم يظهر في بديل المعالم المستحقة');
        $due = collect($lanes)->firstWhere('key', 'milestones')['rows'];
        $this->assertEqualsWithDelta(300.0, collect($due)->firstWhere('id', $p->id)['due']['total'], 0.001);

        // سُكّت فاتورةُ المعلم → يخرج من البديل (فاتورةٌ حيّة، لا ازدواج)
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])->assertRedirect();
        $m->refresh();
        $this->assertNotNull($m->invoice_id, 'لم تُربَط فاتورةُ المعلم');
        Cache::flush();
        $this->assertNotContains($p->id, $this->laneIds((new DeliveryController)->lanes(), 'milestones'),
            'معلمٌ مفوتَرٌ ما زال يُطالَب به في اللوحة — ازدواجُ فوترة');

        // محاولةُ سكٍّ ثانٍ: مسدودةٌ طبقيّاً (لا فاتورةَ ثانية) وتبقى خارجَ البديل
        $this->post("/quote/{$q->id}/act", ['do' => 'ms.invoice', 'ms' => $m->id])
            ->assertRedirect(route('m.show', ['fin', $m->invoice_id]));
        $this->assertSame(1, FinDocument::withTrashed()->where('doc_no', 'LIKE', 'INV-' . $q->doc_no . '%')->count(),
            'سُكّت فاتورةُ معلمٍ ثانية — ازدواجُ فوترة');
        Cache::flush();
        $this->assertNotContains($p->id, $this->laneIds((new DeliveryController)->lanes(), 'milestones'));

        // أُلغيت الفاتورة → يعود المعلمُ مستحقّاً (الإشارةُ تعود بموتها كإشارة ١٥)
        FinDocument::whereKey($m->invoice_id)->update(['state' => 'ملغاة']);
        Cache::flush();
        $this->assertContains($p->id, $this->laneIds((new DeliveryController)->lanes(), 'milestones'),
            'لم يعُد المعلمُ مستحقّاً بعد إلغاء فاتورته');
    }

    /* ────────── ② ترتيبٌ حتميٌّ بـid لا بـcreated_at ────────── */

    public function test_lanes_are_deterministically_ordered_by_id_not_created_at(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $c = Client::create(['name' => 'عميلٌ للترتيب', 'stage' => 'عميل حالي']);

        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->extProject($c, ['status' => 'قيد التنفيذ', 'blocked' => true])->id;
        }
        // created_at متساوٍ عمداً على كلّ الصفوف — فالفاصلُ الوحيدُ الحتميّ هو id.
        // (بلا فاصلِ id تُعيدها MySQL 8 بترتيبٍ آخرَ فتُخفي القرعةُ نقصاً — CLAUDE.md)
        DB::table('projects')->whereIn('id', $ids)
            ->update(['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);

        $got = $this->laneIds((new DeliveryController)->lanes(), 'blocked');

        $expected = $ids;
        sort($expected, SORT_STRING);   // ترتيبُ القاعدة على عمودٍ نصّيّ = SORT_STRING
        $this->assertSame($expected, $got, 'ترتيبُ الطبقة ليس حتميّاً بـid عند تساوي created_at');
        // تأكيدٌ على **كلّ** الصفوف: لا عيّنةٌ واحدةٌ تُخفي البقية
        $this->assertCount(count($ids), $got, 'الطبقةُ لم تُعِد كلَّ الصفوف');
    }

    /* ────────── ③ العميلُ ٤٠٤، والداخليّ يبلغ ────────── */

    public function test_client_gets_404_and_internal_reaches_the_board(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركةُ عميل', 'stage' => 'عميل حالي']);

        // حسابُ عميلٍ حتى بـprojects:v — PortalGuard يردّه ٤٠٤ فوق المصفوفة
        $client = $this->clientUser(['projects' => ['v' => 1]]);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $client->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
        $this->assertTrue(hub_can($client->fresh(), 'projects', 'v'), 'المصفوفةُ تمنح projects:v فعلاً');
        $this->actingAs($client)->get('/delivery/psa')->assertNotFound();

        // الداخليُّ (المالك) يبلغها
        $this->actingAs($this->owner)->get('/delivery/psa')->assertOk();

        // وداخليٌّ بلا projects:v يُردّ ٤٠٣ (بوّابةُ الدور فوقها PortalGuard الداخليُّ لا يُفعَّل)
        $noProj = Role::create(['name' => 'بلا مشاريع', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => 'موظفٌ محدود', 'email' => Str::random(6) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $noProj->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($u)->get('/delivery/psa')->assertForbidden();
    }

    /* ────────── ④ عزلُ الشركة في اللوحة ────────── */

    public function test_board_is_company_isolated(): void
    {
        $this->seedCore();
        $coA = \App\Models\Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = \App\Models\Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $ca = Client::create(['name' => 'عميل ألف', 'company_id' => $coA->id]);
        $cb = Client::create(['name' => 'عميل باء', 'company_id' => $coB->id]);
        $this->employee->update(['companies' => [$coA->id]]);   // معزولةٌ على ألف

        $pa = $this->extProject($ca, ['company_id' => $coA->id, 'status' => 'قيد التنفيذ']);
        $pb = $this->extProject($cb, ['company_id' => $coB->id, 'status' => 'قيد التنفيذ']);

        $this->actingAs($this->employee);
        $active = $this->laneIds((new DeliveryController)->lanes(), 'active');
        $this->assertContains($pa->id, $active, 'المعزولةُ لا ترى مشروعَ شركتها');
        $this->assertNotContains($pb->id, $active, 'تسرّب مشروعُ شركةٍ أجنبيةٍ إلى اللوحة');
    }
}
