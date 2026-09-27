<?php

namespace Tests\Feature\Mobile;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Collaboration\DmService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مرفقاتُ السجلّ ونسخُه ومرفقاتُ الرسائل على الجوال** (خطّةُ التطبيق · 3.5/3.6 · طلبا الجوال #1/#2).
 *
 * القائمةُ بقواعد شاشة السجلّ الويبية (`guardRecord` + `DocumentPolicy::filterListable`)،
 * والحذفُ بحارس الويب (رافعٌ/مالكٌ/محرّرُ وحدة + نطاق)، والنسخُ بحارس عرض السجلّ مع ترشيح
 * أسماءِ الحقول المخفيّة، ومرفقُ الرسالة لطرفَيها والتعليقُ لمن يرى خيطَه.
 */
class MobileRecordFilesTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function person(string $name, array $matrix, array $fieldRules = []): User
    {
        $role = Role::create(['name' => $name . Str::random(4), 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]
            + ($fieldRules ? ['field_rules' => $fieldRules] : []));

        return User::create(['name' => $name, 'email' => Str::random(9) . '@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    private function att(Project $p, User $by, string $name): Attachment
    {
        Storage::disk('local')->put($path = 'hub/' . Str::random(12) . '.pdf', '%PDF-1.4 ' . $name);

        return Attachment::create(['module' => 'projects', 'record_id' => $p->id, 'disk' => 'local', 'path' => $path,
            'original_name' => $name, 'mime' => 'application/pdf', 'size' => 20, 'av_status' => 'clean', 'uploaded_by' => $by->id]);
    }

    // ═══════════ 3.5 · قائمةُ مرفقات السجلّ ═══════════

    public function test_listing_follows_record_scope_and_document_policy(): void
    {
        $this->seedCore();
        $reader = $this->person('قارئ', ['projects' => ['v' => 1]]);
        $p = Project::create(['name' => 'مشروعُ المرفقات']);
        $open = $this->att($p, $this->owner, 'open.pdf');
        $denied = $this->att($p, $this->owner, 'denied-contract.pdf');
        DB::table('document_access_rules')->insert(['resource_type' => 'attachment', 'resource_id' => $denied->id,
            'principal_type' => 'user', 'principal_id' => $reader->id, 'action' => '*', 'effect' => 'deny',
            'created_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);

        $res = $this->withHeaders($this->h($reader))
            ->getJson('/api/mobile/v1/files?module=projects&record_id=' . $p->id)->assertOk();
        $this->assertSame([$open->id], array_column($res->json('data.files'), 'id'),
            'الوثيقةُ الممنوعةُ صراحةً لا تُعرَض ولا تُعدّ — كشاشة الويب');
        $this->assertSame(1, $res->json('data.count'));
        $this->assertStringNotContainsString('denied-contract', (string) $res->getContent());
        $this->assertSame(['download' => true, 'preview' => true, 'delete' => false], $res->json('data.files.0.can'));
        $this->assertSame('/api/mobile/v1/files/' . $open->id . '/download', $res->json('data.files.0.download'));
        foreach (['"path"', '"disk"', 'hub/'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $res->getContent());
        }
    }

    public function test_listing_denials_module_scope_and_client(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $p = Project::create(['name' => 'مشروعُ ألف', 'company_id' => $coA->id]);
        $this->att($p, $this->owner, 'a.pdf');
        $url = '/api/mobile/v1/files?module=projects&record_id=' . $p->id;

        $blind = $this->person('بلا مشاريع', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($blind))->getJson($url)->assertStatus(403);

        $isolated = $this->person('معزولٌ على باء', ['projects' => ['v' => 1]]);
        $isolated->forceFill(['companies' => [$coB->id]])->save();
        $this->withHeaders($this->h($isolated))->getJson($url)->assertStatus(404);

        $this->withHeaders($this->h($this->viewer))->getJson('/api/mobile/v1/files?module=projects')
            ->assertStatus(422);

        // العميلُ: الويبُ يُخفي مرفقاتِ الشاشة الداخلية عنه — ٤٠٤ عند البوّابة
        $client = \App\Models\Client::create(['name' => 'عميل']);
        $cu = User::create(['name' => 'عميل', 'email' => 'c@ext.local', 'password' => 'Secret!2026x',
            'role_id' => $this->employee->role_id, 'status' => 'نشط', 'password_changed_at' => now(), 'account_type' => 'client']);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $cu->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);
        $this->withHeaders($this->h($cu))->getJson($url)->assertStatus(404);
    }

    public function test_delete_uses_the_web_guard(): void
    {
        $this->seedCore();
        $reader = $this->person('قارئ', ['projects' => ['v' => 1]]);
        $uploader = $this->person('رافع', ['projects' => ['v' => 1]]);
        $p = Project::create(['name' => 'مشروع']);
        $a1 = $this->att($p, $this->owner, 'owner.pdf');
        $a2 = $this->att($p, $uploader, 'mine.pdf');

        $this->withHeaders($this->h($reader))->deleteJson('/api/mobile/v1/files/' . $a1->id)->assertStatus(403);
        $this->assertNotNull(Attachment::find($a1->id));

        // رافعُه يحذفه ولو لم يملك تعديلَ الوحدة، ومحرّرُ الوحدة يحذف ما رفعه غيرُه
        $this->withHeaders($this->h($uploader))->deleteJson('/api/mobile/v1/files/' . $a2->id)->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->withHeaders($this->h($this->employee))->deleteJson('/api/mobile/v1/files/' . $a1->id)->assertOk();
        $this->assertNull(Attachment::find($a1->id));
        $this->assertNotNull(Attachment::withTrashed()->find($a1->id), 'حذفٌ ناعم — الملفُّ للاستعادة');
        $this->assertSame(2, DB::table('audits')->where('action', 'حذف مرفق')->count());

        $this->withHeaders($this->h($this->employee))->deleteJson('/api/mobile/v1/files/' . $a1->id)->assertStatus(404);
    }

    // ═══════════ 3.6 · نسخُ السجلّ ═══════════

    public function test_versions_list_numbers_authors_and_masks_hidden_field_names(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $p = Project::create(['name' => 'مشروعُ النسخ', 'priority' => 'متوسطة']);
        $p->name = 'مشروعُ النسخ — معدَّل';
        $p->save();
        $p->priority = 'عالية';
        $p->save();
        auth()->logout();

        $masked = $this->person('قارئٌ محجوب', ['projects' => ['v' => 1]], ['projects' => ['priority' => 'hide']]);
        $res = $this->withHeaders($this->h($masked))->getJson('/api/mobile/v1/projects/' . $p->id . '/versions')->assertOk();
        $vs = $res->json('data.versions');
        $this->assertSame([3, 2, 1], array_column($vs, 'version'));
        $this->assertTrue($vs[0]['current']);
        $this->assertSame($this->owner->id, $vs[0]['by']['id']);
        $this->assertSame([], $vs[0]['changed'], 'اسمُ الحقلِ المحجوبِ على الدور لا يُذكر');
        $this->assertSame(['name'], $vs[1]['changed']);
        $this->assertNull($vs[2]['changed']);
        foreach ($vs as $v) $this->assertFalse($v['restorable'], 'قارئٌ بلا تعديلٍ لا يستعيد');
        $this->assertArrayNotHasKey('snapshot', $vs[0]);
        $this->assertStringNotContainsString('عالية', (string) $res->getContent(), 'لا قيمَ في القائمة');

        $ed = $this->withHeaders($this->h($this->employee))->getJson('/api/mobile/v1/projects/' . $p->id . '/versions')->assertOk();
        $this->assertSame([false, true, true], array_column($ed->json('data.versions'), 'restorable'));
        $this->assertSame(['priority'], $ed->json('data.versions.0.changed'));
    }

    public function test_versions_are_guarded_like_the_record(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $p = Project::create(['name' => 'مشروع', 'company_id' => $coA->id]);

        $blind = $this->person('بلا مشاريع', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($blind))->getJson('/api/mobile/v1/projects/' . $p->id . '/versions')->assertStatus(403);
        $iso = $this->person('معزول', ['projects' => ['v' => 1]]);
        $iso->forceFill(['companies' => [$coB->id]])->save();
        $this->withHeaders($this->h($iso))->getJson('/api/mobile/v1/projects/' . $p->id . '/versions')->assertStatus(404);
        $this->withHeaders($this->h($this->owner))->getJson('/api/mobile/v1/nope/' . $p->id . '/versions')->assertStatus(404);
    }

    // ═══════════ طلب الجوال #2 · مرفقُ الرسالة والتعليق ═══════════

    public function test_dm_attachment_handle_is_downloadable_by_the_two_parties_only(): void
    {
        $this->seedCore();
        Storage::disk('local')->put($path = 'hub/' . Str::random(12) . '.txt', 'ملفُّ المحادثة');
        $msg = DmService::send($this->employee, $this->viewer, 'مرفق', $path);

        $h = $this->h($this->viewer);
        $list = $this->withHeaders($h)->getJson('/api/mobile/v1/dm/threads/' . $this->employee->id . '/messages')->assertOk();
        $att = $list->json('data.messages.0.attachment');
        $this->assertSame((string) $msg->id, $att['id']);
        $this->assertSame(strlen('ملفُّ المحادثة'), $att['size']);
        $this->assertSame('/api/mobile/v1/dm/messages/' . $msg->id . '/attachment', $att['download']);

        $this->withHeaders($h)->get($att['download'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $third = $this->person('ثالث', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($third))->getJson($att['download'])->assertStatus(404);
    }

    public function test_comment_attachment_follows_the_thread_guard(): void
    {
        $this->seedCore();
        $p = Project::create(['name' => 'مشروع التعليق']);
        Storage::disk('local')->put($path = 'hub/' . Str::random(12) . '.txt', 'مرفقُ تعليق');
        $c = Comment::create(['module' => 'projects', 'record_id' => $p->id, 'user_id' => $this->owner->id,
            'body' => 'انظر المرفق', 'att' => $path, 'created_at' => now()]);

        $res = $this->withHeaders($this->h($this->viewer))
            ->getJson('/api/mobile/v1/comments?module=projects&record=' . $p->id)->assertOk();
        $att = $res->json('data.comments.0.attachment');
        $this->assertSame((string) $c->id, $att['id']);
        $this->withHeaders($this->h($this->viewer))->get($att['download'])->assertOk();

        $blind = $this->person('بلا مشاريع', ['tasks' => ['v' => 1]]);
        $this->withHeaders($this->h($blind))->getJson($att['download'])->assertStatus(403);
    }
}
