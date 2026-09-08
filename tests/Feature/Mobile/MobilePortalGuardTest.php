<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

/**
 * **سياجُ حساب العميل على سطح الجوال** (تطبيق العميل · §12/§47/§48) — حاجبُ إطلاق.
 *
 * الهجومُ المرجعيّ: حسابُ عميلٍ خارجيّ مُنح — خطأً أو عبثاً — **دورَ موظفٍ بمصفوفةٍ
 * كاملة** (كلُّ الوحدات). الويبُ يردّه بـ`PortalGuard` فوق المصفوفة؛ وهذه الحزمة
 * تُثبت أن `/api/mobile/v1` يردّه كذلك بـ`MobilePortalGuard`: **الاختبارُ المباشرُ
 * للنقاط لا إخفاءُ الواجهة** — 404 لا كشفَ وجود، والبحثُ والمخطّطُ والإقلاعُ
 * مقصوصةٌ خادميّاً، وترويسةُ السياق لا توسّع، والداخليُّ لا يمسّه الحارس.
 */
class MobilePortalGuardTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private User $clientUser;
    private Client $clientA;
    private Client $clientB;

    /** عميلٌ خارجيٌّ بعضويّةٍ فعّالة **وبدورِ الموظف الكامل** (الهجومُ المرجعيّ) */
    private function seedClientWorld(): void
    {
        $this->seedCore();

        $this->clientA = Client::create(['name' => 'عميل ألف']);
        $this->clientB = Client::create(['name' => 'عميل باء']);

        $this->clientUser = User::create([
            'name' => 'مندوبة العميل', 'email' => 'client@ext.local',
            'password' => 'Secret!2026x', 'status' => 'نشط',
            'password_changed_at' => now(),
            'account_type' => 'client',
            // الدورُ المُساءُ الضبط عمداً: مصفوفةُ الموظف تمنح v على كل الوحدات
            'role_id' => $this->employee->role_id,
        ]);
        ClientMembership::create([
            'client_id' => $this->clientA->id, 'user_id' => $this->clientUser->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now(),
        ]);
    }

    private function clientHeaders(): array
    {
        $data = $this->mobileLogin($this->clientUser, 'inst-client-11111111');

        return $this->bearer($data['access_token']);
    }

    // ═══════════ §47 · الاختبارُ المباشرُ للنقاط الداخلية (لا إخفاءَ واجهة) ═══════════

    public function test_client_with_full_matrix_cannot_reach_internal_modules_directly(): void
    {
        $this->seedClientWorld();
        $h = $this->clientHeaders();

        // وحداتٌ داخليّةٌ صرفة — الدورُ يمنحها v لكنّ السياجَ فوق المصفوفة: 404
        foreach (['servers', 'vault', 'phones', 'hr', 'payroll', 'tasks', 'endpoints', 'dbs'] as $module) {
            $res = $this->withHeaders($h)->getJson('/api/mobile/v1/' . $module);
            $res->assertStatus(404);
            $this->assertSame('RESOURCE_NOT_FOUND', $res->json('code'),
                "الوحدةُ الداخلية {$module} يجب أن تكون 404 لحساب العميل");
        }
    }

    public function test_client_cannot_reach_internal_surfaces_dm_approvals_prefs_tracking_scanner_sync(): void
    {
        $this->seedClientWorld();
        $h = $this->clientHeaders();

        $this->withHeaders($h)->getJson('/api/mobile/v1/dm/threads')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/approvals')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/prefs')->assertStatus(404);
        $this->withHeaders($h)->postJson('/api/mobile/v1/tracking/start', ['consent' => true])->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/identity/resolve/ABC')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/sync/tasks')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/push/admin/status')->assertStatus(404);
    }

    public function test_manipulated_context_headers_never_widen_client_access(): void
    {
        $this->seedClientWorld();
        $h = $this->clientHeaders() + [
            'X-Lynomia-Company' => 'anything', 'X-Lynomia-Client' => $this->clientB->id,
        ];

        $this->withHeaders($h)->getJson('/api/mobile/v1/servers')->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/dm/threads')->assertStatus(404);
    }

    // ═══════════ المقصوصاتُ الخادمية: مخطّطٌ وبحثٌ وإقلاع ═══════════

    public function test_schema_for_client_never_serializes_internal_module_shapes(): void
    {
        $this->seedClientWorld();
        $h = $this->clientHeaders();

        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/schema');
        $res->assertOk();

        $keys = collect($res->json('data.modules'))->pluck('key')->all();
        foreach (['servers', 'vault', 'tasks', 'hr', 'payroll'] as $internal) {
            $this->assertNotContains($internal, $keys,
                "مخطّطُ العميل سرّب شكلَ وحدةٍ داخلية: {$internal}");
        }
        // سطحُه المسموح (المصفوفةُ تمنحه v هنا) يبقى
        $this->assertContains('projects', $keys);
    }

    public function test_search_for_client_filters_out_internal_hits_even_with_full_matrix(): void
    {
        $this->seedClientWorld();
        Task::create(['title' => 'كناري-داخلي-للبحث', 'status' => 'جديدة']);

        // الداخليُّ يجدها — المحرّكُ نفسُه يعمل
        $emp = $this->mobileLogin($this->employee, 'inst-emp-22222222');
        $found = $this->withHeaders($this->bearer($emp['access_token']))
            ->getJson('/api/mobile/v1/search?q=' . urlencode('كناري-داخلي'));
        $found->assertOk();
        $this->assertContains('tasks', collect($found->json('data.results'))->pluck('module')->all());

        // العميلُ (بالمصفوفة الكاملة نفسِها) لا يرى حتى عنوانَها
        $res = $this->withHeaders($this->clientHeaders())
            ->getJson('/api/mobile/v1/search?q=' . urlencode('كناري-داخلي'));
        $res->assertOk();
        $this->assertSame([], $res->json('data.results'),
            'بحثُ العميل سرّب عنوانَ سجلٍّ داخليّ (§23/§47)');
    }

    public function test_bootstrap_for_client_carries_mode_portal_ia_and_no_internal_nav(): void
    {
        $this->seedClientWorld();
        $res = $this->withHeaders($this->clientHeaders())->getJson('/api/mobile/v1/bootstrap');
        $res->assertOk();

        $this->assertSame('client', $res->json('data.user.account_type'));
        $this->assertTrue($res->json('data.feature_flags.is_client'));
        $this->assertSame([], $res->json('data.nav'), 'لا شريطَ إدارةٍ داخليّاً يُسلسَل لعميل');

        $domains = $res->json('data.ia.domains');
        $this->assertCount(1, $domains);
        $this->assertSame('portal', $domains[0]['key']);
        $portals = collect($domains[0]['sections'][0]['destinations'])->pluck('portal')->all();
        $this->assertSame(['home', 'engagements', 'projects', 'documents', 'invoices', 'conversations'], $portals);

        // عضويّاتُه معلنةٌ للعرض
        $this->assertSame($this->clientA->id, $res->json('data.memberships.0.client_id'));
        $this->assertSame('lead', $res->json('data.memberships.0.role'));

        // والداخليُّ لا يحمل عضويّاتِ عملاء ولا يفقد شجرتَه
        $emp = $this->mobileLogin($this->employee, 'inst-emp-33333333');
        $ires = $this->withHeaders($this->bearer($emp['access_token']))->getJson('/api/mobile/v1/bootstrap');
        $ires->assertOk();
        $this->assertSame('internal', $ires->json('data.user.account_type'));
        $this->assertSame([], $ires->json('data.memberships'));
        $this->assertNotSame([], $ires->json('data.nav'));
    }

    // ═══════════ التعليقاتُ والملفّات: تشديدُ الهدف العميليّ ═══════════

    public function test_client_comments_hide_internal_and_cannot_target_internal_modules_or_feed(): void
    {
        $this->seedClientWorld();
        $p = Project::create(['name' => 'مشروع العميل', 'status' => 'قيد التنفيذ',
            'client_id' => $this->clientA->id]);
        Comment::create(['module' => 'projects', 'record_id' => $p->id,
            'user_id' => $this->employee->id, 'body' => 'ملاحظة داخلية سرّية', 'internal' => true, 'created_at' => now()]);
        Comment::create(['module' => 'projects', 'record_id' => $p->id,
            'user_id' => $this->employee->id, 'body' => 'تحديث للعميل', 'internal' => false, 'created_at' => now()]);

        $h = $this->clientHeaders();

        $res = $this->withHeaders($h)
            ->getJson('/api/mobile/v1/comments?module=projects&record=' . $p->id);
        $res->assertOk();
        $bodies = collect($res->json('data.comments'))->pluck('body')->all();
        $this->assertContains('تحديث للعميل', $bodies);
        $this->assertNotContains('ملاحظة داخلية سرّية', $bodies,
            'رسالةُ الفريق الداخلية بلغت عميلاً (§17/§27)');

        // الوحدةُ الداخلية والـfeed محجوبتان هدفاً — 404 قبل أيّ حلّ
        $this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=tasks&record=' . $p->id)
            ->assertStatus(404);
        $this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=feed')
            ->assertStatus(404);

        // ولا يسم العميلُ تعليقَه «داخلياً» — العلمُ يُفرض false
        $post = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'projects', 'record' => $p->id,
            'body' => 'سؤال من العميل', 'internal' => 1,
        ]);
        $post->assertOk();
        $this->assertFalse($post->json('data.comment.internal'));
    }

    public function test_client_file_surface_restricted_to_portal_modules(): void
    {
        $this->seedClientWorld();
        $h = $this->clientHeaders();

        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/files/upload-session', [
            'module' => 'servers', 'record_id' => (string) \Illuminate\Support\Str::uuid(),
            'filename' => 'dump.sql', 'size' => 10,
        ]);
        $res->assertStatus(404);
        $this->assertSame('RESOURCE_NOT_FOUND', $res->json('code'));
    }

    // ═══════════ الداخليُّ لا يمسّه الحارس + حصريّةُ البوّابة ═══════════

    public function test_internal_user_surface_unchanged_and_portal_is_client_only(): void
    {
        $this->seedClientWorld();
        $emp = $this->mobileLogin($this->employee, 'inst-emp-44444444');
        $h = $this->bearer($emp['access_token']);

        $this->withHeaders($h)->getJson('/api/mobile/v1/tasks')->assertOk();
        $this->withHeaders($h)->getJson('/api/mobile/v1/dm/threads')->assertOk();
        $this->withHeaders($h)->getJson('/api/mobile/v1/home')->assertOk();

        // بوّابةُ العميل ليست للداخليّ — 403 صريحة (له لوحتُه، لا 404 تعمية)
        $res = $this->withHeaders($h)->getJson('/api/mobile/v1/portal/home');
        $res->assertStatus(403);
        $this->assertSame('FORBIDDEN', $res->json('code'));
    }

    // ═══════════ §48 · عبرُ العملاء داخل السطح المسموح ═══════════

    public function test_client_a_sees_only_its_projects_within_allowed_module(): void
    {
        $this->seedClientWorld();
        Project::create(['name' => 'مشروع ألف', 'status' => 'قيد التنفيذ', 'client_id' => $this->clientA->id]);
        $pb = Project::create(['name' => 'مشروع باء', 'status' => 'قيد التنفيذ', 'client_id' => $this->clientB->id]);

        $h = $this->clientHeaders();

        $list = $this->withHeaders($h)->getJson('/api/mobile/v1/projects');
        $list->assertOk();
        $names = collect($list->json('data'))->pluck('name')->all();
        $this->assertContains('مشروع ألف', $names);
        $this->assertNotContains('مشروع باء', $names, 'تسرّب مشروعُ عميلٍ آخر (§48)');

        $this->withHeaders($h)->getJson('/api/mobile/v1/projects/' . $pb->id)->assertStatus(404);
    }
}
