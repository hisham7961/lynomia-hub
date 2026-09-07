<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use App\Support\HubEvents;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **القنواتُ والفضاءات فوق Comment** (Work OS · الطور C · WP-C.1 · §3–5, §7).
 *
 * القناةُ حاويةٌ (`conversations.kind=channel`) ورسائلُها تعليقاتٌ تحمل
 * `conversation_id` — لا محرّكَ رسائلَ ثانٍ، ولا جدولَ تفاعلاتٍ ثانٍ. الحارسُ
 * `guardConversation` على نمط `guardTarget`: يُرى وتُكتَب القناةُ **فقط** بعضويّةٍ
 * فعّالة (`conversation_members`) + نطاقِ الشركة/العميل + صلاحيةِ الوحدةِ الهدف
 * لخيوطِ السجلات. غيرُ العضو لا يُثبت له وجودُها (٤٠٤)، وردٌّ لا يُحقَن في قناةٍ
 * لا يراها القارئ (يمتدّ حارسُ `CommentController@store`).
 *
 * حدودُ الأدوار (owner/moderator/member/guest) عضويّةٌ داخل الحاوية لا RBAC ثانٍ:
 * الضيفُ يقرأ ولا يكتب، والعضوُ يكتب، والمشرفُ/المالكُ يديران الأعضاء — والمالكُ
 * وحدَه يُنصّب المشرفين ولا تُخلى القناةُ من مالك. والعميلُ يبلغ قناةَ جمهورِه
 * (`audience=client`) عبر البوابة لا القناةَ الداخلية (PortalGuard فوق الكل).
 */
class WorkOsChannelsTest extends TestCase
{
    /** مستخدمٌ داخليٌّ بمصفوفةٍ وبِراياتٍ اختيارية */
    private function internal(string $name, array $matrix = [], array $flags = [], array $companies = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(6), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => $name, 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => $companies, 'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) بعضويّةٍ فعّالةٍ في عميل */
    private function clientUser(Client $c): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);

        return $u;
    }

    /** يُنشئ قناةً بمالكها (كما يفعل المتحكّم) — للاختباراتِ التي لا تمرّ بالمسار */
    private function channelOwnedBy(User $owner, array $attrs = []): Conversation
    {
        $conv = Conversation::create(array_merge([
            'kind' => 'channel', 'title' => 'قناةٌ للاختبار',
            'audience' => 'internal', 'visibility' => 'private',
            'created_by' => $owner->id,
        ], $attrs));
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id,
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);

        return $conv;
    }

    /* ────────── ١) إنشاءٌ وعرضٌ للعضو، وإطلاقُ الحدث ────────── */

    public function test_owner_creates_channel_and_sees_it_and_fires_event(): void
    {
        $this->seedCore();

        $captured = [];
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e) use (&$captured) { $captured[] = $e; });

        $res = $this->actingAs($this->owner)->post('/conversations', [
            'title' => 'قناةُ الهندسة',
        ]);

        $conv = Conversation::where('kind', 'channel')->where('title', 'قناةُ الهندسة')->first();
        $this->assertNotNull($conv, 'لم تُنشأ القناة');
        $res->assertRedirect(route('conversations.show', $conv->id));

        // المُنشئُ عضوٌ مالك
        $this->assertSame('owner', ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $this->owner->id)->value('role'));

        // الحدثُ الدلاليّ انبثق (لا يُعاد إعلانه في هذا الطور — يُطلَق هنا)
        $this->assertContains('conversation.created', $captured,
            'حدثُ conversation.created لم يُطلَق عند إنشاء القناة');
        HubEvents::forgetListeners();

        // الفهرسُ والعرضُ للعضو
        $this->actingAs($this->owner)->get('/conversations')->assertOk()->assertSee('قناةُ الهندسة');
        $this->actingAs($this->owner)->get('/conversations/' . $conv->id)->assertOk()->assertSee('قناةُ الهندسة');
    }

    /* ────────── ٢) غيرُ العضو لا يقرأ قناةً خاصة ────────── */

    public function test_non_member_cannot_read_a_private_channel(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالكُ القناة');
        $stranger = $this->internal('غريبٌ عنها');
        $conv = $this->channelOwnedBy($owner, ['title' => 'خاصّة']);

        // ٤٠٤ لا ٤٠٣: لا نُثبت وجودَ ما ليس عضواً فيه
        $this->actingAs($stranger)->get('/conversations/' . $conv->id)->assertNotFound();
        // ولا تظهر في فهرسه
        $this->actingAs($stranger)->get('/conversations')->assertOk()->assertDontSee('خاصّة');
    }

    /* ────────── ٣) ردٌّ لا يُحقَن في قناةٍ لا يراها القارئ ────────── */

    public function test_reply_cannot_be_injected_into_an_unseen_channel(): void
    {
        $this->seedCore();
        $owner = $this->internal('صاحبُ القناة');
        $stranger = $this->internal('محاوِلُ الحقن');
        $conv = $this->channelOwnedBy($owner);

        $before = Comment::where('conversation_id', $conv->id)->count();

        // حقنٌ مباشرٌ عبر مسار التعليقات بـmodule=channel — يُردّ ٤٠٤ بالعضويّة
        $this->actingAs($stranger)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id,
            'conversation_id' => $conv->id, 'body' => 'رسالةٌ مدسوسة',
        ])->assertNotFound();

        $this->assertSame($before, Comment::where('conversation_id', $conv->id)->count(),
            'رسالةٌ دُسّت في قناةٍ لا يراها القارئ');
        $this->assertSame(0, Comment::where('body', 'رسالةٌ مدسوسة')->count());
    }

    /* ────────── ٤) الضيفُ يقرأ ولا يكتب؛ العضوُ يكتب ────────── */

    public function test_guest_reads_but_cannot_post_member_can_post(): void
    {
        $this->seedCore();
        $owner = $this->internal('مالك');
        $guest = $this->internal('ضيف');
        $member = $this->internal('عضو');
        $conv = $this->channelOwnedBy($owner);

        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $guest->id, 'role' => 'guest']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $member->id, 'role' => 'member']);

        // الضيفُ يقرأ
        $this->actingAs($guest)->get('/conversations/' . $conv->id)->assertOk();
        // لكنّه لا يكتب — ٤٠٣
        $this->actingAs($guest)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'محاولةُ ضيف',
        ])->assertForbidden();
        $this->assertSame(0, Comment::where('body', 'محاولةُ ضيف')->count());

        // العضوُ يكتب — وتُنسَب الرسالةُ للحاوية عبر conversation_id
        $this->actingAs($member)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'مرحباً بالقناة',
        ])->assertRedirect();
        $msg = Comment::where('body', 'مرحباً بالقناة')->first();
        $this->assertNotNull($msg);
        $this->assertSame((string) $conv->id, (string) $msg->conversation_id,
            'الرسالةُ لم تُنسَب لحاويتها عبر conversation_id');
        // وتظهر في علاقةِ رسائلِ الحاوية (المحرّكُ الوحيد: comments)
        $this->assertTrue($conv->messages()->where('id', $msg->id)->exists());
    }

    /* ────────── ٥) حدودُ إدارةِ الأعضاء ────────── */

    public function test_member_management_boundaries(): void
    {
        $this->seedCore();
        $owner = $this->internal('المالك');
        $mod = $this->internal('المشرف');
        $member = $this->internal('العضو');
        $bob = $this->internal('بوب');
        $conv = $this->channelOwnedBy($owner);

        // المالكُ يُنصّب مشرفاً، ويضيف عضواً عاديّاً
        $this->actingAs($owner)->post(route('conversations.member.add', $conv->id), [
            'user_id' => $mod->id, 'role' => 'moderator',
        ])->assertRedirect();
        $this->assertSame('moderator', ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $mod->id)->value('role'));
        $this->actingAs($owner)->post(route('conversations.member.add', $conv->id), [
            'user_id' => $member->id, 'role' => 'member',
        ])->assertRedirect();

        // عضوٌ عاديٌّ (عضوٌ لكنّه غيرُ مُدير) لا يضيف أحداً — ٤٠٣
        $this->actingAs($member)->post(route('conversations.member.add', $conv->id), [
            'user_id' => $bob->id, 'role' => 'member',
        ])->assertForbidden();
        $this->assertFalse(ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $bob->id)->exists(), 'عضوٌ عاديٌّ أضاف عضواً');

        // وغريبٌ عن القناة (لا عضويّةَ له) لا يديرها أصلاً — ٤٠٤ لا ٤٠٣ (لا كشفَ وجود)
        $outsider = $this->internal('غريب');
        $this->actingAs($outsider)->post(route('conversations.member.add', $conv->id), [
            'user_id' => $bob->id, 'role' => 'member',
        ])->assertNotFound();

        // لكنّ المشرفَ لا يُنصّب مالكاً/مشرفاً — تصعيدُ هويّةٍ للمالك وحده
        $this->actingAs($mod)->post(route('conversations.member.add', $conv->id), [
            'user_id' => $bob->id, 'role' => 'owner',
        ])->assertForbidden();
        $this->assertFalse(ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $bob->id)->exists(), 'مشرفٌ نصّب مالكاً');

        // المشرفُ يغيّر دورَ عضوٍ عاديّ إلى ضيف
        $this->actingAs($mod)->post(route('conversations.member.role', $conv->id), [
            'user_id' => $member->id, 'role' => 'guest',
        ])->assertRedirect();
        $this->assertSame('guest', ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $member->id)->value('role'));

        // لكنّه لا يمسّ المالكَ (لا يخفضه) — ٤٠٣
        $this->actingAs($mod)->post(route('conversations.member.role', $conv->id), [
            'user_id' => $owner->id, 'role' => 'member',
        ])->assertForbidden();
        $this->assertSame('owner', ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $owner->id)->value('role'));

        // ولا تُخلى القناةُ من مالكها الوحيد — ٤٢٢
        $this->actingAs($owner)->post(route('conversations.member.remove', $conv->id), [
            'user_id' => $owner->id,
        ])->assertStatus(422);
        $this->assertTrue(ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $owner->id)->exists());

        // والمشرفُ يزيل عضواً عاديّاً (بعد أن صار ضيفاً)
        $this->actingAs($mod)->post(route('conversations.member.remove', $conv->id), [
            'user_id' => $member->id,
        ])->assertRedirect();
        $this->assertFalse(ConversationMember::where('conversation_id', $conv->id)
            ->where('user_id', $member->id)->exists());
    }

    /* ────────── ٦) العميلُ يبلغ قناةَ جمهورِه عبر البوابة لا الداخليّة ────────── */

    public function test_client_reaches_client_channel_via_portal_not_internal_ones(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'شركةُ عميل', 'stage' => 'عميل حالي']);
        $client = $this->clientUser($c);
        $staff = $this->internal('موظفُ الحساب');

        // قناةُ جمهورِ العميل — العميلُ عضوٌ فيها
        $shared = $this->channelOwnedBy($staff, [
            'title' => 'قناةُ العميل', 'audience' => 'client', 'client_id' => $c->id,
        ]);
        ConversationMember::create(['conversation_id' => $shared->id, 'user_id' => $client->id,
            'role' => 'member', 'source' => 'explicit']);
        Comment::create(['module' => 'channel', 'record_id' => $shared->id,
            'conversation_id' => $shared->id, 'user_id' => $staff->id,
            'body' => 'أهلاً بك في مساحتك', 'read_by' => [$staff->id], 'created_at' => now()]);

        // قناةٌ داخليّةٌ دُسّ العميلُ عضواً فيها خطأً — يجب أن تبقى محجوبةً عنه
        $internalConv = $this->channelOwnedBy($staff, ['title' => 'أسرارٌ داخلية', 'audience' => 'internal']);
        ConversationMember::create(['conversation_id' => $internalConv->id, 'user_id' => $client->id,
            'role' => 'member', 'source' => 'explicit']);
        Comment::create(['module' => 'channel', 'record_id' => $internalConv->id,
            'conversation_id' => $internalConv->id, 'user_id' => $staff->id,
            'body' => 'هامشُنا الربحيّ ٤٠٪', 'read_by' => [$staff->id], 'created_at' => now()]);

        // البوابة: يرى قناةَ جمهورِه ورسالتَها
        $this->actingAs($client)->get(route('portal.conversation', $shared->id))
            ->assertOk()->assertSee('أهلاً بك في مساحتك');

        // لكنّ القناةَ الداخليّةَ ٤٠٤ عبر البوابة (الجمهورُ داخليّ) — لا تسرّبَ هامشٍ ربحيّ
        $this->actingAs($client)->get(route('portal.conversation', $internalConv->id))->assertNotFound();

        // والمساراتُ الداخليّةُ للقنوات ٤٠٤ للعميل (PortalGuard فوق الكل)
        $this->actingAs($client)->get('/conversations')->assertNotFound();
        $this->actingAs($client)->get('/conversations/' . $shared->id)->assertNotFound();
        $this->actingAs($client)->get('/conversations/' . $internalConv->id)->assertNotFound();
    }

    /* ────────── ٧) نطاقُ الشركة دفاعاً في العمق فوق العضويّة ────────── */

    public function test_company_scope_defends_over_membership(): void
    {
        $this->seedCore();
        // مستخدمٌ مقيَّدٌ بشركةٍ واحدة، وقناةُ شركةٍ أخرى دُسّ عضواً فيها
        $restricted = $this->internal('مقيَّدٌ بشركة أ', [], [], ['company-A']);
        $owner = $this->internal('مالكٌ بشركة ب', [], [], ['company-B']);
        $conv = $this->channelOwnedBy($owner, ['title' => 'قناةُ ب', 'company_id' => 'company-B']);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $restricted->id, 'role' => 'member']);

        // عضوٌ نعم، لكنّ نطاقَ الشركةِ يردّه ٤٠٤ (عضويّةٌ خاطئةٌ لا تُسرّب)
        $this->actingAs($restricted)->get('/conversations/' . $conv->id)->assertNotFound();
        // ولا يكتب فيها
        $this->actingAs($restricted)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'تسريبٌ عبر الحقن',
        ])->assertNotFound();
        $this->assertSame(0, Comment::where('body', 'تسريبٌ عبر الحقن')->count());
    }
}
