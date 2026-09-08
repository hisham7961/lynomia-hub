<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Company;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **E.3 · تعليقاتُ الجوال (reuse guardTarget — نقطةُ التخويلِ الوحيدة · F2)** —
 * Mobile Readiness · الطور E.
 *
 * يُثبِت أنّ سطحَ الجوال يمرّ بنفسِ حارسِ الويب (`CommentService::guardTarget`) بلا
 * محرّكٍ ثانٍ: وحدةٌ لا يراها المستخدمُ ⇒ ٤٠٣، وسجلٌّ خارج نطاقه ⇒ ٤٠٤، والردُّ يلتصق
 * بخيطه (`assertReplyIntegrity` ⇒ ٤٢٢ لأبٍ من سجلٍّ آخر)، والمنشن يُشعِر، والتفاعلُ
 * يظهر، والمرفقُ يُكشَف حضوراً، والنشرُ **idempotent** (مالكُ الجوال · F1).
 */
class MobileCommentsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function auth(User $u, ?string $uuid = null): array
    {
        return $this->bearer($this->mobileLogin($u, $uuid)['access_token']);
    }

    /** عميلٌ حقيقيٌّ (سجلٌّ في وحدةٍ مسجَّلة) — هدفُ التعليق */
    private function client(string $name = 'عميلُ التعليقات', ?string $companyId = null): Client
    {
        return Client::create(['name' => $name] + ($companyId ? ['company_id' => $companyId] : []));
    }

    // ═══════════════════════ النطاقُ والتخويل (guardTarget) ═══════════════════════

    public function test_module_the_user_cannot_view_is_forbidden(): void
    {
        $this->seedCore();
        // دورٌ يرى كلَّ شيءٍ إلا العملاء (v=0 على clients) — نظيرُ منعِ الويب حرفاً
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $matrix['clients'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'بلا عملاء', 'scope' => 'all', 'flags' => [], 'matrix' => $matrix]);
        $user = User::create(['name' => 'محجوب', 'email' => 'noclients@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);

        $h = $this->auth($user, 'inst-cmt-forbid-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=clients&record=' . Str::uuid())
            ->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_out_of_scope_record_is_404_not_a_leak(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'باء', 'status' => 'نشطة']);
        $foreign = $this->client('عميلُ باء', $coB->id);

        // موظفةٌ معزولةٌ على شركةِ ألف — سجلُّ باءٍ خارجُ نطاقها ⇒ ٤٠٤ (findOrFail داخل guardTarget)
        $this->employee->forceFill(['companies' => [$coA->id]])->saveQuietly();
        $h = $this->auth($this->employee, 'inst-cmt-scope-1');

        $this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=clients&record=' . $foreign->id)
            ->assertStatus(404)->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_missing_module_param_is_422(): void
    {
        $this->seedCore();
        $h = $this->auth($this->owner, 'inst-cmt-noparam-1');
        $this->withHeaders($h)->getJson('/api/mobile/v1/comments')
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    // ═══════════════════════ النشرُ والقراءة (الطريقُ السعيد) ═══════════════════════

    public function test_post_then_list_shows_the_comment_in_scope(): void
    {
        $this->seedCore();
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-post-1');

        $posted = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id, 'body' => 'تعليقٌ من الجوال',
        ])->assertOk()->assertJsonPath('data.comment.body', 'تعليقٌ من الجوال')->json('data.comment.id');

        $bodies = collect($this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=clients&record=' . $c->id)
            ->assertOk()->json('data.comments'))->pluck('body')->all();

        $this->assertContains('تعليقٌ من الجوال', $bodies);
        $this->assertDatabaseHas('comments', ['id' => $posted, 'module' => 'clients', 'record_id' => $c->id]);
    }

    // ═══════════════════════ التصاقُ الردِّ بخيطه (assertReplyIntegrity) ═══════════════════════

    public function test_reply_must_belong_to_the_same_record_thread(): void
    {
        $this->seedCore();
        $recA = $this->client('سجل أ');
        $recB = $this->client('سجل ب');
        $h = $this->auth($this->owner, 'inst-cmt-reply-1');

        // أبٌ على سجل أ
        $parent = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $recA->id, 'body' => 'الأب',
        ])->assertOk()->json('data.comment.id');

        // ردٌّ يحاول الالتصاقَ بالأب لكن تحت سجل ب ⇒ ٤٢٢ (لا يُدسّ ردٌّ في خيطٍ ليس له)
        $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $recB->id, 'parent_id' => $parent, 'body' => 'ردٌّ مدسوس',
        ])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->assertDatabaseMissing('comments', ['body' => 'ردٌّ مدسوس']);
    }

    public function test_reply_in_the_same_thread_succeeds(): void
    {
        $this->seedCore();
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-reply-ok-1');

        $parent = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id, 'body' => 'الأب',
        ])->assertOk()->json('data.comment.id');

        $reply = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id, 'parent_id' => $parent, 'body' => 'ردٌّ صحيح',
        ])->assertOk()->assertJsonPath('data.comment.parent_id', $parent)->json('data.comment.id');

        $this->assertDatabaseHas('comments', ['id' => $reply, 'parent_id' => $parent]);
    }

    // ═══════════════════════ المنشن يُشعِر ═══════════════════════

    public function test_mention_creates_a_notification_for_the_mentioned_user(): void
    {
        $this->seedCore();
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-mention-1');

        $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id,
            'body' => 'انظري هذا يا زميلة', 'mention' => [$this->employee->id],
        ])->assertOk();

        $this->assertTrue(
            HubNotification::where('user_id', $this->employee->id)->where('kind', 'mention')->exists(),
            'المذكورةُ تلقّت إشعارَ منشن'
        );
    }

    // ═══════════════════════ التفاعلُ يظهر في البطاقة ═══════════════════════

    public function test_reaction_on_a_comment_is_reflected_in_the_shape(): void
    {
        $this->seedCore();
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-react-1');

        $cid = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id, 'body' => 'تعليقٌ للتفاعل',
        ])->assertOk()->json('data.comment.id');

        // تفاعلٌ من الموظفة (سكّةُ الويب نفسُها: جدول reactions)
        DB::table('reactions')->insert([
            'id' => (string) Str::uuid(), 'comment_id' => $cid,
            'user_id' => $this->employee->id, 'emoji' => '👍', 'created_at' => now(),
        ]);

        $comment = collect($this->withHeaders($h)->getJson('/api/mobile/v1/comments?module=clients&record=' . $c->id)
            ->assertOk()->json('data.comments'))->firstWhere('id', $cid);

        $emojis = collect($comment['reactions'])->pluck('emoji')->all();
        $this->assertContains('👍', $emojis, 'التفاعلُ يظهر في بطاقة التعليق');
        $this->assertSame(1, collect($comment['reactions'])->firstWhere('emoji', '👍')['count']);
    }

    // ═══════════════════════ المرفقُ يُكشَف حضوراً ═══════════════════════

    public function test_attachment_is_stored_and_flagged_present(): void
    {
        $this->seedCore();
        Storage::fake('local');
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-att-1');

        $res = $this->withHeaders($h)->postJson('/api/mobile/v1/comments', [
            'module' => 'clients', 'record' => $c->id, 'body' => 'مع مرفق',
            'att' => UploadedFile::fake()->create('doc.pdf', 12),
        ])->assertOk();

        $res->assertJsonPath('data.comment.has_attachment', true);
        $cid = $res->json('data.comment.id');
        $this->assertNotNull(Comment::find($cid)->att, 'مسارُ المرفقِ محفوظٌ في الصف');
    }

    // ═══════════════════════ النشرُ idempotent (F1) ═══════════════════════

    public function test_post_is_idempotent_on_the_same_key(): void
    {
        $this->seedCore();
        $c = $this->client();
        $h = $this->auth($this->owner, 'inst-cmt-idem-1');
        $body = ['module' => 'clients', 'record' => $c->id, 'body' => 'مرّةً واحدة'];
        $key = ['Idempotency-Key' => 'cmt-key-777'];

        $first = $this->withHeaders($h + $key)->postJson('/api/mobile/v1/comments', $body)->assertOk();
        $second = $this->withHeaders($h + $key)->postJson('/api/mobile/v1/comments', $body)->assertOk();

        $this->assertSame($first->json('data.comment.id'), $second->json('data.comment.id'),
            'المفتاحُ نفسُه يعيد الردَّ المحفوظ — لا تعليقَ ثانٍ');
        $this->assertSame(1, Comment::where('record_id', $c->id)->where('body', 'مرّةً واحدة')->count(),
            'صفٌّ واحدٌ فقط رغم إعادة المحاولة');
    }

    public function test_post_requires_a_valid_mobile_access_token(): void
    {
        $this->seedCore();
        $this->postJson('/api/mobile/v1/comments', ['module' => 'clients', 'record' => Str::uuid(), 'body' => 'x'])
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }
}
