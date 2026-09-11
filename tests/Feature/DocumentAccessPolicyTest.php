<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **سياسةُ الوصولِ للوثائق على مستوى المورد** (Permissions 360 · وثائق · المستوى 5/6).
 *
 * إثباتٌ لا ادّعاء: المرفقُ يُتاح افتراضاً بصلاحيةِ سجلِّه الأمِّ (لا كسرَ توافق)، لكنَّ المالكَ
 * يمنعُ/يسمحُ **لوثيقةٍ بعينها** لدورٍ أو مستخدمٍ بمعرِّفها المستقرّ — والمنعُ يُفرَضُ على
 * التنزيلِ والمعاينةِ والحزمةِ معاً، ولا يلتفُّ عليه إعادةُ التسمية.
 */
class DocumentAccessPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** مستخدمٌ داخليٌّ بمصفوفةٍ محدَّدة */
    private function internal(string $email, array $mods, string $scope = 'all'): User
    {
        $matrix = [];
        foreach ($mods as $m => $ops) $matrix[$m] = is_array($ops) ? $ops : ['v' => 1];
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $scope, 'flags' => [], 'matrix' => $matrix]);

        return User::create(['name' => 'داخليّ', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /** مرفقٌ حقيقيٌّ على سجلِّ مشروعٍ (ملفٌ على القرص كي يُخدَم عند السماح) */
    private function attach(Project $p, string $name = 'وثيقة.pdf', string $kind = 'report'): Attachment
    {
        $path = 'hub/test/' . \Illuminate\Support\Str::random(12) . '.pdf';
        Storage::disk('local')->put($path, 'DUMMY-PDF-BYTES');

        return Attachment::create([
            'module' => 'projects', 'record_id' => $p->id, 'kind' => $kind,
            'disk' => 'local', 'path' => $path, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 15, 'av_status' => 'clean',
            'uploaded_by' => $this->owner->id,
        ]);
    }

    private function rule(Attachment $a, string $ptype, string $pid, string $effect, string $action = '*'): void
    {
        DB::table('document_access_rules')->insert([
            'resource_type' => 'attachment', 'resource_id' => $a->id,
            'principal_type' => $ptype, 'principal_id' => $pid,
            'effect' => $effect, 'action' => $action, 'created_at' => now(),
        ]);
        \App\Support\DocumentPolicy::forget((string) $a->id);
    }

    /* ═══════════ الوراثة الافتراضية (لا كسر) ═══════════ */

    public function test_inherited_access_when_no_rule_and_parent_visible(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p);
        $u = $this->internal('reader@test.local', ['projects' => ['v' => 1]]);

        $this->actingAs($u)->get(route('att.dl', $a->id))->assertOk();
        $this->actingAs($u)->get(route('att.view', $a->id))->assertOk();
    }

    /* ═══════════ منعُ وثيقةٍ بعينها ═══════════ */

    public function test_explicit_user_deny_blocks_download_and_preview(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p, '2026 مراجعة الرواتب.pdf', 'report');
        $u = $this->internal('denied@test.local', ['projects' => ['v' => 1]]);

        // يرى المشروعَ، لكنّ هذه الوثيقةَ ممنوعةٌ عنه صراحةً
        $this->rule($a, 'user', $u->id, 'deny');
        $this->actingAs($u)->get(route('att.dl', $a->id))->assertForbidden();
        $this->actingAs($u)->get(route('att.view', $a->id))->assertForbidden();
    }

    public function test_explicit_role_deny_blocks(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p);
        $u = $this->internal('roled@test.local', ['projects' => ['v' => 1]]);

        $this->rule($a, 'role', $u->role_id, 'deny');
        $this->actingAs($u)->get(route('att.dl', $a->id))->assertForbidden();
    }

    public function test_user_allow_overrides_role_deny(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p);
        $u = $this->internal('override@test.local', ['projects' => ['v' => 1]]);

        // الدورُ ممنوع، لكنَّ هذا المستخدمَ مسموحٌ صراحةً (الأخصُّ يعلو)
        $this->rule($a, 'role', $u->role_id, 'deny');
        $this->rule($a, 'user', $u->id, 'allow');
        $this->actingAs($u)->get(route('att.dl', $a->id))->assertOk();
    }

    public function test_owner_bypasses_document_rules(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p);
        // منعٌ على دورِ المالك — لا يمسّه (المالكُ يتجاوز)
        $this->rule($a, 'user', $this->owner->id, 'deny');
        $this->actingAs($this->owner)->get(route('att.dl', $a->id))->assertOk();
    }

    /* ═══════════ الثبات: الاسمُ ليس المعرِّف ═══════════ */

    public function test_rename_does_not_change_authorization(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p, 'الأصلي.pdf');
        $u = $this->internal('rename@test.local', ['projects' => ['v' => 1]]);
        $this->rule($a, 'user', $u->id, 'deny');

        // إعادةُ التسميةِ لا تُغيّر القرارَ (القاعدةُ على المعرِّفِ لا الاسم)
        $a->forceFill(['original_name' => 'اسمٌ مختلفٌ تماماً.pdf'])->save();
        \App\Support\DocumentPolicy::forget((string) $a->id);
        $this->actingAs($u)->get(route('att.dl', $a->id))->assertForbidden();
    }

    /* ═══════════ الحزمةُ لا تلتفُّ على المنع ═══════════ */

    public function test_bulk_zip_excludes_denied_document(): void
    {
        if (! class_exists(\ZipArchive::class)) $this->markTestSkipped('zip غير متاح');
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $ok = $this->attach($p, 'مسموح.pdf');
        $denied = $this->attach($p, 'ممنوع سري.pdf');
        $u = $this->internal('zipper@test.local', ['projects' => ['v' => 1]]);
        $this->rule($denied, 'user', $u->id, 'deny');

        $res = $this->actingAs($u)->get(route('att.zip', ['projects', $p->id]))->assertOk();
        // الوثيقةُ الممنوعةُ لم تُنزَّل (لا صفٌّ لها في سجل التنزيل)، والمسموحةُ نُزّلت
        $this->assertSame(0, DB::table('download_log')->where('attachment_id', $denied->id)->count());
        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $ok->id)->count());
    }

    /* ═══════════ مسارُ الضبط ═══════════ */

    public function test_owner_can_set_rule_but_unauthorized_cannot(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p);
        $target = $this->internal('target@test.local', ['projects' => ['v' => 1]]);

        // المالكُ يضبط منعاً
        $this->actingAs($this->owner)->post(route('att.access', $a->id), [
            'principal_type' => 'user', 'principal_id' => $target->id, 'effect' => 'deny',
        ])->assertRedirect();
        $this->assertSame(1, DB::table('document_access_rules')->where('resource_id', $a->id)->count());

        // مستخدمٌ بلا صلاحيةِ تعديلِ الوحدةِ لا يضبط
        $rando = $this->internal('rando@test.local', ['projects' => ['v' => 1]]);
        $this->actingAs($rando)->post(route('att.access', $a->id), [
            'principal_type' => 'user', 'principal_id' => $target->id, 'effect' => 'allow',
        ])->assertForbidden();
    }

    /** **أطرافٌ متعددة في ضبطةٍ واحدة**: عدّةُ أشخاصٍ ودورٍ معاً — قاعدةٌ لكلٍّ، وكلُّها تُفرَض */
    public function test_owner_can_set_rules_for_multiple_people_and_roles_at_once(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع', 'status' => 'نشط']);
        $a = $this->attach($p, 'صورةٌ سرية.jpg');
        $u1 = $this->internal('p1@test.local', ['projects' => ['v' => 1]]);
        $u2 = $this->internal('p2@test.local', ['projects' => ['v' => 1]]);

        // المالكُ يسمحُ لشخصين ودورٍ في POST واحد
        $this->actingAs($this->owner)->post(route('att.access', $a->id), [
            'effect' => 'allow',
            'users' => [$u1->id, $u2->id],
            'roles' => [$u1->role_id],
        ])->assertRedirect();

        // ثلاثُ قواعدَ أُنشئت (شخصان + دور)
        $this->assertSame(3, DB::table('document_access_rules')->where('resource_id', $a->id)->count());
        $this->assertSame(2, DB::table('document_access_rules')->where('resource_id', $a->id)
            ->where('principal_type', 'user')->count());
        $this->assertSame(1, DB::table('document_access_rules')->where('resource_id', $a->id)
            ->where('principal_type', 'role')->count());

        // ولا اختيارَ طرفٍ ⇒ ٤٢٢ (لا قاعدةَ فارغة)
        $this->actingAs($this->owner)->post(route('att.access', $a->id), ['effect' => 'deny'])
            ->assertStatus(422);
    }
}
