<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **التضييقاتُ الآمنة** (Permissions 360 · المتبقّي · 13.2 · 12.1 · 12.2 · 10.3 · 17.3 · 14.2).
 *
 * كلُّها على النمطِ الآمنِ نفسِه: بوّابةٌ أدقُّ + هجرةٌ عديمةُ الخسارةِ تصون الأدوارَ القائمة:
 *   · 13.2 الإرفاقُ كتابةٌ مسمّاة (e أو attach) لا ظلَّ للعرض — وهجرةُ grant_attach.
 *   · 12.1 بابُ /m/endpoints يشترط ما يشترطه المركزُ (مالك/secOps) — وهجرةُ grant_secops.
 *   · 12.2 روابطُ الجهاز (موظّف/أصل/محطّة) مقفولةٌ سجلّياً — تُختم من بروتوكولِ التسجيل.
 *   · 10.3 حزامُ التصدير (تجميد/تصعيد/تدقيق) على CSV الشهريّ دون تغييرِ سلطةِ العرض.
 *   · 17.3 أعمدةُ الموظفِ في CSV الشهريّ تستشير نمطَ الحقل (hide ⇒ فارغ).
 *   · 14.2 رايةُ dataroomShare تفصل نشرَ المشاركةِ الخارجيّةِ عن كشفِ أسرارِ الخزنة.
 */
class Permissions360SafeTighteningTest extends TestCase
{
    private function user(string $email, array $matrix = [], array $flags = [], array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all', 'flags' => $flags,
            'matrix' => $matrix, 'field_rules' => $fieldRules ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ 13.2 — الإرفاقُ كتابةٌ مسمّاة ═══════════ */

    public function test_attach_requires_edit_or_attach_key(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروعُ الإرفاق', 'status' => 'قيد التنفيذ']);
        $post = fn (User $u) => $this->actingAs($u)->post('/attachments', [
            'module' => 'projects', 'record_id' => $p->id,
            'file' => UploadedFile::fake()->create('مستند.pdf', 4, 'application/pdf')]);

        // قارئٌ بحت (v بلا e/attach) — دورٌ جديدٌ بعد الهجرة: يُصدُّ ٤٠٣ ولا يُكتب صف
        $reader = $this->user('att-v@test.local', ['projects' => ['v' => 1]]);
        $post($reader)->assertForbidden();
        $this->assertSame(0, Attachment::count(), 'قارئٌ بحتٌ لم يعد يُرفق');

        // حاملُ المفتاحِ الدقيقِ attach (بلا e) يُرفق
        $att = $this->user('att-k@test.local', ['projects' => ['v' => 1, 'attach' => 1]]);
        $post($att)->assertRedirect();
        $this->assertSame(1, Attachment::count(), 'مفتاحُ attach يمنح الإرفاق');

        // وحاملُ e يُرفق كما كان (المفتاحُ الرئيس)
        $ed = $this->user('att-e@test.local', ['projects' => ['v' => 1, 'e' => 1]]);
        $post($ed)->assertRedirect();
        $this->assertSame(2, Attachment::count());
    }

    public function test_grant_attach_migration_preserves_existing_readers(): void
    {
        $this->seedCore();
        // دورٌ «قائمٌ» يرى المشاريعَ ويعدّل المهامَّ — كما لو أُنشئ قبل الترقية
        $legacy = Role::create(['name' => 'دورٌ قائم', 'scope' => 'all', 'flags' => [],
            'matrix' => ['projects' => ['v' => 1], 'tasks' => ['v' => 1, 'e' => 1]]]);

        $m = require base_path('database/migrations/2026_09_29_000001_grant_attach_to_existing_roles.php');
        $m->up();

        $mx = Role::find($legacy->id)->matrix;
        $this->assertSame(1, $mx['projects']['attach'] ?? 0, 'من كان يرى بقي يُرفق (لا فقدَ وصول)');
        $this->assertArrayNotHasKey('attach', $mx['tasks'] ?? [], 'حاملُ e لا يحتاج المفتاح');
    }

    /* ═══════════ 12.1 — بابُ /m/endpoints بوّابةُ المركزِ نفسُها ═══════════ */

    public function test_endpoints_module_door_requires_secops(): void
    {
        $this->seedCore();

        // مصفوفةُ endpoints:v وحدَها كانت تلتفُّ على حارسِ المركز — تُصدُّ الآن ٤٠٣
        $v = $this->user('ep-v@test.local', ['endpoints' => ['v' => 1, 'e' => 1]]);
        $this->actingAs($v)->get('/m/endpoints')->assertForbidden();

        // وحاملُ secOps (بوّابةُ المركز) يمرّ — والمالكُ كما هو
        $sec = $this->user('ep-s@test.local', ['endpoints' => ['v' => 1]], ['secOps' => 1]);
        $this->actingAs($sec)->get('/m/endpoints')->assertOk();
        $this->actingAs($this->owner)->get('/m/endpoints')->assertOk();
    }

    public function test_grant_secops_migration_preserves_endpoint_viewer_roles(): void
    {
        $this->seedCore();
        $legacy = Role::create(['name' => 'مشغّلُ أسطولٍ قائم', 'scope' => 'all', 'flags' => [],
            'matrix' => ['endpoints' => ['v' => 1]]]);
        $covered = Role::create(['name' => 'مراقبٌ جامع', 'scope' => 'all', 'flags' => ['monitor' => 1],
            'matrix' => ['endpoints' => ['v' => 1]]]);
        $other = Role::create(['name' => 'بلا نقاط', 'scope' => 'all', 'flags' => [],
            'matrix' => ['tasks' => ['v' => 1]]]);

        $m = require base_path('database/migrations/2026_09_29_000002_grant_secops_to_endpoint_viewer_roles.php');
        $m->up();

        $this->assertSame(1, Role::find($legacy->id)->flags['secOps'] ?? 0, 'رائي الأسطولِ القائمُ بقي يراه');
        $this->assertArrayNotHasKey('secOps', Role::find($covered->id)->flags ?? [], 'حاملُ monitor مغطًّى أصلاً');
        $this->assertArrayNotHasKey('secOps', Role::find($other->id)->flags ?? [], 'من لا يرى الأسطولَ لا يُمنَح');
    }

    /* ═══════════ 12.2 — روابطُ الجهازِ مقفولةٌ سجلّياً ═══════════ */

    public function test_endpoint_link_fields_are_locked_for_everyone(): void
    {
        $this->seedCore();
        foreach (['employeeId', 'assetId', 'stationId'] as $fk) {
            $this->assertSame('ro', hub_field_mode($this->owner, 'endpoints', $fk),
                "حقلُ {$fk} يُختم من بروتوكولِ التسجيلِ لا من نموذجِ CRUD — حتى للمالك");
        }
    }

    /* ═══════════ 10.3 — حزامُ التصديرِ على CSV الشهريّ ═══════════ */

    public function test_monthly_export_wears_the_export_belt(): void
    {
        $this->seedCore();

        // (١) تجميدُ الطوارئ يصدّ حتى المالك — ٤٢٣
        $this->hubSetting('security.freeze_exports', '1');
        $this->actingAs($this->owner)->get(route('reports.monthly.export'))->assertStatus(423);
        $this->hubSetting('security.freeze_exports', '0');

        // (٢) فوق العتبة يتطلّب تأكيدَ الهويّة — صفُّ حضورٍ واحدٌ وعتبةٌ = ١
        $u = $this->user('mx@test.local', ['updates' => ['v' => 1]]);
        Employee::create(['name' => 'موظفُ التصدير', 'status' => 'نشط', 'user_id' => $u->id]);
        \App\Support\Workday::checkIn($u, ['mode' => 'مكتب']);

        $this->hubSetting('security.export_stepup_rows', '1');
        $res = $this->actingAs($this->owner)->get(route('reports.monthly.export'));
        $this->assertTrue($res->isRedirect() && str_contains((string) $res->headers->get('Location'), 'stepup'),
            'تصديرٌ كبيرٌ بلا تصعيدٍ يُحوَّل لتأكيدِ الهويّة');

        // وبعد التصعيد يمرّ وتُختم بصمةُ «تصدير كبير» في التدقيق
        $this->actingAs($this->owner)
            ->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp])
            ->get(route('reports.monthly.export'))->assertOk();
        $this->assertTrue(DB::table('audits')->where('action', 'تصدير كبير')->where('module', 'attend')->exists(),
            'بصمةُ التصديرِ الكبيرِ في سجلِّ التدقيق');
    }

    /* ═══════════ 17.3 — CSV الشهريّ يستشير نمطَ الحقل ═══════════ */

    public function test_monthly_export_respects_hr_field_mode(): void
    {
        $this->seedCore();
        $emp = $this->user('fm-emp@test.local', ['updates' => ['v' => 1]]);
        Employee::create(['name' => 'موظفُ الحقول', 'dept' => 'قسمٌ محجوب', 'status' => 'نشط', 'user_id' => $emp->id]);
        \App\Support\Workday::checkIn($emp, ['mode' => 'مكتب']);

        // محاسبٌ دورُه يحجب حقلَ القسمِ في الموارد — لا يستلمه في CSV أيضاً
        $acc = $this->user('fm-acc@test.local', ['attend' => ['v' => 1], 'hr' => ['v' => 1]],
            fieldRules: ['hr' => ['dept' => 'hide']]);
        $csv = $this->actingAs($acc)->get(route('reports.monthly.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('موظفُ الحقول', $csv, 'الاسمُ غيرُ المحجوبِ باقٍ');
        $this->assertStringNotContainsString('قسمٌ محجوب', $csv, 'حقلٌ محجوبٌ بنمطِ الحقلِ لا يتسرّب في CSV');

        // ونظيرُه بلا حجبٍ يستلمه (لا فقدَ قدرةٍ لغيرِ المحجوب)
        $acc2 = $this->user('fm-acc2@test.local', ['attend' => ['v' => 1], 'hr' => ['v' => 1]]);
        $csv2 = $this->actingAs($acc2)->get(route('reports.monthly.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('قسمٌ محجوب', $csv2);
    }

    /* ═══════════ 14.2 — فصلُ النشرِ الخارجيّ عن كشفِ الأسرار ═══════════ */

    public function test_dataroom_accepts_share_flag_without_vault_secrets(): void
    {
        $this->seedCore();

        // بلا أيِّ راية — ٤٠٣ كما كان
        $none = $this->user('dr-0@test.local', ['updates' => ['v' => 1]]);
        $this->actingAs($none)->get(route('dataroom.index'))->assertForbidden();

        // رايةُ المشاركةِ الخارجيّةِ وحدَها تكفي (بلا كشفِ الخزنة)
        $share = $this->user('dr-s@test.local', ['updates' => ['v' => 1]], ['dataroomShare' => 1]);
        $this->actingAs($share)->get(route('dataroom.index'))->assertOk();

        // وحاملُ secrets يبقى يدخل (إضافةٌ لا كسر)
        $sec = $this->user('dr-v@test.local', ['updates' => ['v' => 1]], ['secrets' => 1]);
        $this->actingAs($sec)->get(route('dataroom.index'))->assertOk();
    }
}
