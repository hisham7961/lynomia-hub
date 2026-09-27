<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **«تذاكري» على الجوال** (خطّة التطبيق 4.1) — `portal/tickets*` فوق `ClientTickets` +
 * `ClientPortalData` نفسَيهما اللذَين يستدعيهما الويب: العميلُ من عضويّاته، مشروعُ غيره
 * ٤٠٤، تذكرةُ غيره ٤٠٤، الحالةُ/القناةُ/الإسنادُ خادميّة، `internal` مختومٌ على الردّ
 * ومحجوبٌ عند القراءة، والتوأمُ المفتوحُ يُوجَّه (٤٠٩) لا يُمنع. والداخليُّ يُردّ بعقد البوّابة.
 */
class MobilePortalTicketsTest extends TestCase
{
    use InteractsWithMobileAuth;

    private function clientUser(string $email): User
    {
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'سامي — حساب عميل', 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** @return array{0: User, 1: Client, 2: Project} */
    private function world(): array
    {
        $this->seedCore();
        $c = Client::create(['name' => 'مزارع النخيل', 'stage' => 'عميل حالي']);
        $p = Project::create(['name' => 'نظام صيانة النخيل', 'client_id' => $c->id, 'status' => 'قيد التنفيذ']);
        $sami = $this->clientUser('sami@client.local');
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $sami->id,
            'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);

        return [$sami, $c, $p];
    }

    private function h(User $u): array
    {
        return $this->bearer($this->mobileLogin($u)['access_token']);
    }

    public function test_client_opens_lists_and_reads_her_ticket_with_server_stamped_fields(): void
    {
        [$sami, $c, $p] = $this->world();
        $H = $this->h($sami);

        $res = $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', [
            'subject' => 'النظام يعطي خطأ 502', 'body' => 'الموقع متوقّف منذ الصباح.',
            'priority' => 'عاجلة', 'project' => (string) $p->id,
            // حقولُ الداخل تُحقن فلا تُقرأ
            'status' => 'مغلقة', 'assignee_id' => (string) $this->owner->id, 'notes' => 'حقن',
        ])->assertStatus(201);

        $id = $res->json('data.ticket.id');
        $t = Ticket::findOrFail($id);
        $this->assertSame((string) $c->id, (string) $t->client_id);
        $this->assertSame((string) $p->id, (string) $t->project_id);
        $this->assertSame((string) $sami->id, (string) $t->created_by);
        $this->assertSame('جديدة', (string) $t->status, 'الحالةُ خادميّة');
        $this->assertNull($t->assignee_id, 'لا يُسنِد العميلُ لأحد');
        $this->assertNull($t->notes);
        $res->assertJsonPath('data.ticket.project_name', 'نظام صيانة النخيل');
        $res->assertJsonPath('data.ticket.done', false);

        $list = $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets')->assertOk()->json('data');
        $this->assertSame([$id], array_column($list['tickets'], 'id'));
        $this->assertSame(['نظام صيانة النخيل'], array_column($list['projects'], 'name'));
        $this->assertContains('عاجلة', $list['priorities']);
        $this->assertArrayNotHasKey('assignee_id', $list['tickets'][0], 'لا عمودَ داخليّ في القائمة');

        $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets/' . $id)->assertOk()
            ->assertJsonPath('data.ticket.body', 'الموقع متوقّف منذ الصباح.');
    }

    public function test_foreign_project_and_foreign_ticket_are_404_and_nothing_is_written(): void
    {
        [$sami] = $this->world();
        $other = Client::create(['name' => 'عميلٌ آخر']);
        $theirProject = Project::create(['name' => 'مشروعُ الغير', 'client_id' => $other->id]);
        $theirs = Ticket::create(['subject' => 'بطءُ الغير', 'client_id' => $other->id, 'status' => 'قيد المعالجة']);
        $orphan = Ticket::create(['subject' => 'بلا عميل', 'status' => 'جديدة']);
        $H = $this->h($sami);

        $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', [
            'subject' => 'تسلّل', 'body' => 'محاولة', 'priority' => 'عاجلة', 'project' => (string) $theirProject->id,
        ])->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->assertSame(0, Ticket::where('subject', 'تسلّل')->count());

        foreach ([$theirs, $orphan] as $t) {
            $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets/' . $t->id)->assertNotFound();
            $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets/' . $t->id . '/reply', ['body' => 'ردّ'])
                ->assertNotFound();
        }
        $this->assertSame(0, Comment::where('module', 'tickets')->count(), 'لا ردَّ يُكتب على تذكرةِ غيره');

        $ids = array_column($this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets')->json('data.tickets'), 'id');
        $this->assertNotContains((string) $theirs->id, $ids);
        $this->assertNotContains((string) $orphan->id, $ids);
    }

    public function test_reply_is_always_public_and_internal_notes_never_reach_the_client(): void
    {
        [$sami, $c] = $this->world();
        $t = Ticket::create(['subject' => 'عطل', 'client_id' => $c->id, 'status' => 'قيد المعالجة',
            'assignee_id' => $this->employee->id]);
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->employee->id,
            'body' => 'ملاحظةٌ-داخليّةٌ-سرّيّة', 'internal' => true, 'created_at' => now()->subMinutes(5)]);
        Comment::create(['module' => 'tickets', 'record_id' => $t->id, 'user_id' => $this->employee->id,
            'body' => 'نعمل عليه الآن', 'internal' => false, 'created_at' => now()->subMinutes(4)]);
        $H = $this->h($sami);

        $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets/' . $t->id . '/reply',
            ['body' => 'ما زال العطلُ قائماً', 'internal' => true])->assertStatus(201)
            ->assertJsonPath('data.reply.mine', true);

        $reply = Comment::where('module', 'tickets')->where('body', 'ما زال العطلُ قائماً')->firstOrFail();
        $this->assertFalse((bool) $reply->internal, 'ردُّ العميل عامٌّ دائماً ولو حُقن internal');

        $detail = $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets/' . $t->id)->assertOk();
        $bodies = array_column($detail->json('data.replies'), 'body');
        $this->assertContains('نعمل عليه الآن', $bodies);
        $this->assertContains('ما زال العطلُ قائماً', $bodies);
        $this->assertStringNotContainsString('ملاحظةٌ-داخليّةٌ-سرّيّة', (string) $detail->getContent());

        // والفريقُ يُشعَر (المُسنَدُ إليه) — السكّةُ نفسُها
        $this->assertTrue(\App\Models\HubNotification::where('user_id', $this->employee->id)
            ->where('record_id', $t->id)->exists());
    }

    public function test_duplicate_open_ticket_is_steered_with_409_then_force_opens_a_second(): void
    {
        [$sami, $c] = $this->world();
        $H = $this->h($sami);
        $payload = ['subject' => 'العطلُ نفسُه', 'body' => 'الوصفُ نفسُه', 'priority' => 'متوسطة'];

        $first = $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', $payload)->assertStatus(201)
            ->json('data.ticket.id');

        $dup = $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', $payload)->assertStatus(409);
        $dup->assertJsonPath('code', 'CONFLICT')->assertJsonPath('details.reason', 'duplicate_ticket')
            ->assertJsonPath('details.duplicate.id', $first);
        $this->assertSame(1, Ticket::where('client_id', $c->id)->count());

        $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', $payload + ['force' => true])->assertStatus(201);
        $this->assertSame(2, Ticket::where('client_id', $c->id)->count());
    }

    public function test_idempotency_key_replays_the_open_without_a_second_ticket(): void
    {
        [$sami, $c] = $this->world();
        $H = $this->h($sami) + ['Idempotency-Key' => 'tk-open-1'];
        $payload = ['subject' => 'بلاغٌ عبر شبكةٍ متقطّعة', 'body' => 'نص', 'priority' => 'متوسطة'];

        $a = $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', $payload)->assertStatus(201);
        $b = $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets', $payload)->assertStatus(201);
        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.ticket.id'), $b->json('data.ticket.id'));
        $this->assertSame(1, Ticket::where('client_id', $c->id)->count());
    }

    public function test_client_without_active_membership_writes_nothing_and_sees_nothing(): void
    {
        $this->seedCore();
        $lonely = $this->clientUser('lonely@client.local');
        $H = $this->h($lonely);

        $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets')->assertOk()
            ->assertJsonPath('data.tickets', []);
        $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets',
            ['subject' => 'x', 'body' => 'y', 'priority' => 'متوسطة'])->assertNotFound();
        $this->assertSame(0, Ticket::count());
    }

    public function test_internal_user_is_refused_by_the_portal_contract(): void
    {
        [, $c] = $this->world();
        $t = Ticket::create(['subject' => 'داخليّ', 'client_id' => $c->id, 'status' => 'جديدة']);
        $H = $this->h($this->owner);

        $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets')->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
        $this->withHeaders($H)->getJson('/api/mobile/v1/portal/tickets/' . $t->id)->assertForbidden();
        $this->withHeaders($H)->postJson('/api/mobile/v1/portal/tickets',
            ['subject' => 'x', 'body' => 'y', 'priority' => 'متوسطة'])->assertForbidden();
        $this->assertSame(1, Ticket::count());
    }
}
