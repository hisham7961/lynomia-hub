<?php

namespace Tests\Feature\Mobile;

use App\Models\Attachment;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **«وثائقي» على الجوال — فجوةٌ أحدثها إصلاحُ N-5 نفسُه.**
 *
 * أُغلق N-5 في v2.525.0 ببناءِ قسمِ «وثائقي» في `/me` وسكّتَين ذاتيّتَين. وبقي
 * الجوالُ بلا نظير: يُنذر `mobile/v1/home` صاحبَ الشأنِ بوثيقتِه (رايةُ `self` في
 * `attention()`) — **ثمّ لا يجد في العقدِ بابًا يفتحها**. وهو عينُ العيبِ الذي
 * أُغلق للويب، منقولاً إلى سطحٍ آخر.
 *
 * **وهذا هو الصنفُ الذي يلاحقه المجلس بالضبط**: إصلاحٌ يُطبَّق على قارئٍ واحدٍ
 * فيصير للسؤالِ الواحدِ جوابان — «أين أجد وثيقتي؟» له جوابٌ على الويب ولا جوابَ
 * على الجوال. فالإغلاقُ هنا **تكافؤٌ لا ميزةٌ جديدة**، ومن **التعريفِ نفسِه**
 * (`EmployeeDocuments`) الذي تقرؤه البوّابةُ — لا نسخةٌ ثانيةٌ تنحرف غداً.
 */
class MobileMyDocumentsTest extends TestCase
{
    use InteractsWithMobileAuth;

    /** موظّفٌ بلا `hr:v` — وهو حالُ من يُنذَر بوثيقتِه */
    private function staff(string $name = 'هيا المطيري'): array
    {
        $role = Role::create(['name' => 'موظّف' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1]]]);
        $u = User::create(['name' => $name, 'email' => Str::random(9) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id,
            'status' => 'نشط', 'password_changed_at' => now()]);
        $e = Employee::create(['name' => $name, 'status' => 'نشط', 'user_id' => $u->id]);

        return [$u, $e];
    }

    private function doc(Employee $e, string $kind = 'id', ?string $expires = null,
                        string $name = 'iqama.pdf', string $av = 'clean'): Attachment
    {
        Storage::disk('local')->put($path = 'hub/' . Str::random(12) . '.pdf', '%PDF-1.4 اختبار');

        return Attachment::create([
            'module' => 'hr', 'record_id' => $e->id, 'kind' => $kind,
            'disk' => 'local', 'path' => $path, 'original_name' => $name,
            'mime' => 'application/pdf', 'size' => 15, 'av_status' => $av,
            'uploaded_by' => $this->owner->id, 'expires_at' => $expires,
        ]);
    }

    // ═══════════ ١ · العقدُ يعرض وثائقي ═══════════

    public function test_my_documents_are_listed_on_mobile(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $this->doc($e, 'id', now()->addDays(5)->toDateString());

        $tok = $this->mobileLogin($u)['access_token'];
        $res = $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents')->assertOk();

        $rows = $res->json('data.items');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows, 'وثيقةُ ملفِّه لم تصل الجوال — الإنذارُ هناك والوجهةُ مفقودة');
        $this->assertSame('الهوية / الإقامة', $rows[0]['label']);
        $this->assertSame(5, $rows[0]['days']);
    }

    public function test_the_payload_never_carries_a_raw_path_or_disk(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $this->doc($e);

        $tok = $this->mobileLogin($u)['access_token'];
        $body = $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents')->assertOk()->json();

        $raw = json_encode($body, JSON_UNESCAPED_UNICODE);
        foreach (['"path"', '"disk"', 'hub/'] as $leak) {
            $this->assertStringNotContainsString($leak, $raw,
                'العقدُ يسرّب موضعَ الملفِّ على القرص — الرابطُ يُعطى ولا يُعطى المسار');
        }
    }

    // ═══════════ ٢ · فتحُ وثيقتي — بالقاعدةِ نفسِها ═══════════

    public function test_i_can_open_my_own_sensitive_document_on_mobile(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'contract', null, 'contract.pdf');
        $this->assertTrue(hub_doc_sensitive('hr', 'contract'), 'تهيئةٌ خاطئة: النوعُ غيرُ حسّاس');

        $tok = $this->mobileLogin($u)['access_token'];
        $this->withHeaders($this->bearer($tok))
            ->get('/api/mobile/v1/me/documents/' . $a->id . '/file')->assertOk();

        $this->assertSame(1, DB::table('download_log')->where('attachment_id', $a->id)
            ->where('user_id', $u->id)->count(), 'فُتحت وثيقةٌ بلا أثر');
    }

    // ═══════════ ٣ · وحدودُ الاستثناءِ محفوظةٌ كما في الويب ═══════════

    public function test_a_colleagues_document_is_not_mine_on_mobile(): void
    {
        $this->seedCore();
        [$u] = $this->staff('هيا المطيري');
        [, $other] = $this->staff('بدرُ الخالدي');
        $a = $this->doc($other, 'id', null, 'other.pdf');

        $tok = $this->mobileLogin($u)['access_token'];
        $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents/' . $a->id . '/file')->assertStatus(404);
    }

    public function test_an_explicit_denial_is_respected_on_mobile(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'id', null, 'restricted.pdf');
        DB::table('document_access_rules')->insert([
            'resource_type' => 'attachment', 'resource_id' => $a->id,
            'principal_type' => 'user', 'principal_id' => $u->id,
            'effect' => 'deny', 'action' => '*', 'created_at' => now(),
        ]);
        \App\Support\Documents\DocumentPolicy::forget((string) $a->id);

        $tok = $this->mobileLogin($u)['access_token'];
        $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents/' . $a->id . '/file')->assertStatus(403);

        $rows = $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents')->assertOk()->json('data.items');
        $this->assertSame([], $rows, 'وثيقةٌ ممنوعةٌ صراحةً ظهرت في القائمة');
    }

    public function test_an_infected_file_is_blocked_on_mobile(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $a = $this->doc($e, 'id', null, 'infected.pdf', 'infected');

        $tok = $this->mobileLogin($u)['access_token'];
        $this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents/' . $a->id . '/file')->assertStatus(423);
    }

    // ═══════════ ٤ · تعريفٌ واحدٌ يقرؤه السطحان ═══════════

    public function test_web_and_mobile_answer_the_question_identically(): void
    {
        $this->seedCore();
        [$u, $e] = $this->staff();
        $this->doc($e, 'id', now()->addDays(3)->toDateString(), 'a.pdf');
        $this->doc($e, 'passport', now()->addDays(9)->toDateString(), 'b.pdf');
        $this->doc($e, 'cv', null, 'c.pdf');

        $tok = $this->mobileLogin($u)['access_token'];
        $mobile = collect($this->withHeaders($this->bearer($tok))
            ->getJson('/api/mobile/v1/me/documents')->assertOk()->json('data.items'))
            ->pluck('id')->all();

        $web = collect(\App\Support\Workforce\EmployeeDocuments::forUser($u))->pluck('id')->all();

        $this->assertSame($web, $mobile,
            'السطحان يجيبان «ما وثائقي؟» بجوابَين — وهو عينُ ما يلاحقه المجلس');
    }
}
