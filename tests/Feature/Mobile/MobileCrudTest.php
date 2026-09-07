<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use Tests\TestCase;

/**
 * **D.1 · CRUD الجوال + تكافؤُ الصلاحيات (== الويب)** — Mobile Readiness · الطور D.
 *
 * يُثبِت أنّ سطحَ الجوال يعيد استعمالَ محرّكِ الوحدات نفسِه (`V1Controller`) — فالمستخدمُ
 * يرى/يُنشئ/يعدّل عبر الجوال **بالضبط** ما يستطيعه عبر الويب (`hub_can` + `hub_scope`):
 * المعزولُ على شركةٍ لا يرى سجلَّ شركةٍ أخرى (٤٠٤ لا تسريب)، والمعزولُ على مشروعٍ لا يرى
 * سواه، والحقلُ المخفيُّ (`hub_field_mode`) لا يُكتَب ولا يُعاد، والفعلُ الممنوع ٤٠٣.
 * وتضييقُ السياق (`X-Lynomia-Company`) طبقةٌ فوق النطاق على القوائم لا توسيعٌ له.
 */
class MobileCrudTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    /** رؤوسُ حاملِ رمزٍ حيٍّ لمستخدم — يسجّل الدخول ويعيد ترويسةَ Bearer */
    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    // ═══════════════════════ الطريقُ السعيد (المالك) ═══════════════════════

    public function test_owner_can_list_create_show_update_delete_a_client(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-crud-owner-1111');

        // إنشاءٌ (٢٠١) — نفسُ عقدِ /api/v1 عبر parent::apiStore
        $create = $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'عميلُ الجوال'])
            ->assertCreated()->assertJsonPath('data.name', 'عميلُ الجوال');
        $id = $create->json('data.id');
        $this->assertNotEmpty($id);

        // قائمةٌ — يظهر فيها المُنشأ
        $names = collect($this->withHeaders($h)->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))
            ->pluck('name');
        $this->assertTrue($names->contains('عميلُ الجوال'));

        // سجلٌّ واحدٌ + ETag بنسخته
        $show = $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $id)->assertOk()
            ->assertJsonPath('data.id', $id);
        $show->assertHeader('ETag');

        // تعديلٌ جزئيّ (PATCH) — تبقى بقيّةُ الحقول
        $this->withHeaders($h)->patchJson('/api/mobile/v1/clients/' . $id, ['name' => 'عميلٌ مُعدَّل'])
            ->assertOk()->assertJsonPath('data.name', 'عميلٌ مُعدَّل');

        // حذفٌ للسلة
        $this->withHeaders($h)->deleteJson('/api/mobile/v1/clients/' . $id)
            ->assertOk()->assertJsonPath('deleted', true);
        $this->assertSoftDeleted('clients', ['id' => $id]);
    }

    public function test_crud_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->getJson('/api/mobile/v1/clients')
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_unknown_module_is_404_not_500(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-crud-unknown-11');
        $this->withHeaders($h)->getJson('/api/mobile/v1/not_a_module')
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    // ═══════════════════════ تكافؤُ الصلاحيات == الويب ═══════════════════════

    public function test_forbidden_op_is_403_for_a_view_only_user(): void
    {
        $this->seedCore();
        // المشاهدُ يملك v فقط (لا a) — الإنشاءُ ممنوع، تماماً كالويب
        $h = $this->auth($this->viewer, 'inst-crud-viewer-11');
        $this->withHeaders($h)->postJson('/api/mobile/v1/clients', ['name' => 'ممنوع'])
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
        $this->assertDatabaseMissing('clients', ['name' => 'ممنوع']);
    }

    public function test_company_restricted_user_never_sees_or_reaches_a_foreign_company_record(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);

        // موظفةٌ معزولةٌ على شركةِ ألف وحدَها
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-crud-coA-1111');

        // القائمةُ لا تحمل عميلَ باء (لا تسريب)
        $ids = collect($this->withHeaders($h)->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))
            ->pluck('id')->all();
        $this->assertContains($cA->id, $ids, 'عميلُ شركتِها ظاهر');
        $this->assertNotContains($cB->id, $ids, 'عميلُ شركةٍ أجنبيّة لا يتسرّب في القائمة (IDOR)');

        // والوصولُ المباشر لسجلِ شركةٍ أجنبيّة ٤٠٤ (لا فرقَ بين «غير موجود» و«خارج نطاقك»)
        $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $cB->id)->assertStatus(404);

        // ولا يعدّله ولا يحذفه — خارج النطاق ⇒ ٤٠٤ في findScoped
        $this->withHeaders($h)->patchJson('/api/mobile/v1/clients/' . $cB->id, ['name' => 'اختراق'])
            ->assertStatus(404);
        $this->assertDatabaseHas('clients', ['id' => $cB->id, 'name' => 'عميلُ باء']);
    }

    public function test_project_scoped_user_sees_only_own_project_records_like_web(): void
    {
        $this->seedCore();
        $p1 = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ', 'manager_id' => $this->employee->id]);
        $p2 = Project::create(['name' => 'مشروعُ غيري', 'status' => 'قيد التنفيذ']);
        $this->employee->role->forceFill(['scope' => 'proj'])->save();

        $h = $this->auth($this->employee, 'inst-crud-proj-111');
        $names = collect($this->withHeaders($h)->getJson('/api/mobile/v1/projects')->assertOk()->json('data'))
            ->pluck('name');
        $this->assertTrue($names->contains('مشروعي'));
        $this->assertFalse($names->contains('مشروعُ غيري'), 'نطاقُ المشروع == الويب: لا يرى مشروعَ غيره');

        // ووصولُه المباشرُ لمشروعِ غيره ٤٠٤
        $this->withHeaders($h)->getJson('/api/mobile/v1/projects/' . $p2->id)->assertStatus(404);
    }

    // ═══════════════════════ الحقلُ المخفيُّ (hub_field_mode) ═══════════════════════

    public function test_a_hidden_field_is_neither_writable_nor_returned(): void
    {
        $this->seedCore();
        // دورٌ يُخفي حقلَ «الهاتف» على العملاء (hide) — كما يفعل field_rules في الويب
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دورٌ يُخفي الهاتف', 'scope' => 'all', 'flags' => [],
            'matrix' => $matrix, 'field_rules' => ['clients' => ['phone' => 'hide']]]);
        $user = User::create(['name' => 'مستخدمٌ مقيَّدُ الحقل', 'email' => 'hidefield@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $h = $this->auth($user, 'inst-crud-hide-111');

        // يحاول كتابةَ الهاتف — يُسقَط بصمتٍ (لا mass-assignment لحقلٍ مخفيّ)
        $id = $this->withHeaders($h)->postJson('/api/mobile/v1/clients',
            ['name' => 'صاحبُ هاتفٍ مخفيّ', 'phone' => '99998888'])
            ->assertCreated()->json('data.id');

        $this->assertDatabaseMissing('clients', ['id' => $id, 'phone' => '99998888']);
        $this->assertNull(Client::find($id)->phone, 'الحقلُ المخفيُّ لم يُكتَب رغم حقنه');

        // ولا يُعاد في القراءة — المفتاحُ «phone» غائبٌ عن الحمولة
        $data = $this->withHeaders($h)->getJson('/api/mobile/v1/clients/' . $id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('phone', $data, 'الحقلُ المخفيُّ يجب ألّا يُعاد في القراءة');
    }

    // ═══════════════════════ تضييقُ السياق على القوائم (SF-4 · D.1) ═══════════════════════

    public function test_company_context_header_narrows_the_list_but_never_widens_scope(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $cA = Client::create(['name' => 'عميلُ ألف', 'company_id' => $coA->id]);
        $cB = Client::create(['name' => 'عميلُ باء', 'company_id' => $coB->id]);

        // موظفةٌ ترى الشركتَين — الترويسةُ تضيّق العرضَ لإحداهما (AND فوق hub_scope)
        $this->employee->forceFill(['companies' => [$coA->id, $coB->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-crud-narrow-11');

        // بلا ترويسةٍ: يرى الاثنين
        $all = collect($this->withHeaders($h)->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($cA->id, $all);
        $this->assertContains($cB->id, $all);

        // بترويسةِ ألف: يرى ألفَ وحدَه (تضييقٌ لا توسيع)
        $narrow = collect($this->withHeaders($h + ['X-Lynomia-Company' => $coA->id])
            ->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$cA->id], $narrow, 'الترويسةُ تضيّق القائمةَ لشركةِ ألف');

        // وترويسةٌ لشركةٍ خارجَ مجموعته لا توسّع — تُتجاهَل، يبقى النطاقُ الكامل
        $coC = Company::create(['name_ar' => 'جيم', 'status' => 'نشطة']);
        $ignored = collect($this->withHeaders($h + ['X-Lynomia-Company' => $coC->id])
            ->getJson('/api/mobile/v1/clients')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($cA->id, $ignored, 'الترويسةُ الممنوعةُ تُتجاهَل — لا حجبَ ولا توسيع');
        $this->assertContains($cB->id, $ignored);
    }
}
