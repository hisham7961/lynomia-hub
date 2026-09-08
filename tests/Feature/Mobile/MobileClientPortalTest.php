<?php

namespace Tests\Feature\Mobile;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\User;
use Tests\TestCase;

/**
 * **بوّابةُ العميل على الجوال** (`/api/mobile/v1/portal/*` · §12/§18) —
 * القرّاءُ المشترَكون مع الويب (`ClientPortalData`): فشلٌ مغلقٌ على العضويّة
 * الفعّالة، عزلٌ عبرَ العملاء، فواتيرُ مبيعاتٍ فقط، وثائقُ جمهورٍ عميليّ فقط،
 * وغرفٌ بعضويّةٍ **وجمهورٍ** معاً برسائلَ تُخفي `internal` بنيوياً.
 */
class MobileClientPortalTest extends TestCase
{
    use InteractsWithMobileAuth;
    use AssertsMobilePayload;

    private User $clientUser;
    private Client $clientA;
    private Client $clientB;
    private Project $projectA;
    private Project $projectB;

    private function seedPortalWorld(): void
    {
        $this->seedCore();

        $this->clientA = Client::create(['name' => 'عميل ألف']);
        $this->clientB = Client::create(['name' => 'عميل باء']);

        $this->clientUser = User::create([
            'name' => 'مندوبة ألف', 'email' => 'a@ext.local',
            'password' => 'Secret!2026x', 'status' => 'نشط',
            'password_changed_at' => now(), 'account_type' => 'client',
        ]);
        ClientMembership::create([
            'client_id' => $this->clientA->id, 'user_id' => $this->clientUser->id,
            'role' => 'owner', 'status' => 'active', 'activated_at' => now(),
        ]);

        $this->projectA = Project::create(['name' => 'مشروع ألف', 'status' => 'قيد التنفيذ',
            'client_id' => $this->clientA->id, 'progress' => 40]);
        $this->projectB = Project::create(['name' => 'مشروع باء', 'status' => 'قيد التنفيذ',
            'client_id' => $this->clientB->id]);

        Engagement::create(['name' => 'ارتباط ألف', 'client_id' => $this->clientA->id,
            'status' => 'نشط', 'client_note' => 'ملاحظة للعميل']);
        Engagement::create(['name' => 'ارتباط باء', 'client_id' => $this->clientB->id, 'status' => 'نشط']);

        // فاتورةُ مبيعاتٍ لألف (تظهر) + مشترياتٌ لألف (لا تظهر — تكشف تكلفتنا) + مبيعاتُ باء
        FinDocument::create(['doc_no' => 'INV-A1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $this->clientA->id, 'total' => 1500.500, 'paid' => 500, 'state' => 'مرسلة']);
        FinDocument::create(['doc_no' => 'PUR-A1', 'kind' => 'فاتورة مشتريات',
            'client_id' => $this->clientA->id, 'total' => 900]);
        FinDocument::create(['doc_no' => 'INV-B1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $this->clientB->id, 'total' => 700]);

        // وثيقةٌ بجمهورٍ عميليّ لألف + داخليّةٌ لألف + عميليّةٌ لباء
        Document::create(['name' => 'عرض ألف', 'audience' => 'client', 'client_id' => $this->clientA->id]);
        Document::create(['name' => 'مذكرة داخلية', 'audience' => 'internal', 'client_id' => $this->clientA->id]);
        Document::create(['name' => 'عرض باء', 'audience' => 'client', 'client_id' => $this->clientB->id]);
    }

    private function clientHeaders(): array
    {
        $data = $this->mobileLogin($this->clientUser, 'inst-portal-11111111');

        return $this->bearer($data['access_token']);
    }

    public function test_portal_home_aggregates_only_this_clients_world(): void
    {
        $this->seedPortalWorld();
        $res = $this->withHeaders($this->clientHeaders())->getJson('/api/mobile/v1/portal/home');
        $res->assertOk();

        $this->assertSame('client', $res->json('data.mode'));
        $this->assertSame(['عميل ألف'], collect($res->json('data.clients'))->pluck('name')->all());
        $this->assertSame(['مشروع ألف'], collect($res->json('data.projects'))->pluck('name')->all());
        $this->assertSame(['ارتباط ألف'], collect($res->json('data.engagements'))->pluck('name')->all());
        $this->assertSame(['INV-A1'], collect($res->json('data.invoices'))->pluck('doc_no')->all(),
            'المشترياتُ وفواتيرُ الغير لا تظهر');
        $this->assertSame(['عرض ألف'], collect($res->json('data.documents'))->pluck('name')->all(),
            'الجمهورُ الداخليّ ووثائقُ الغير لا تظهر');
    }

    public function test_portal_detail_readers_are_client_safe_and_cross_client_is_404(): void
    {
        $this->seedPortalWorld();
        $h = $this->clientHeaders();

        $p = $this->withHeaders($h)->getJson('/api/mobile/v1/portal/projects/' . $this->projectA->id);
        $p->assertOk();
        $this->assertSame('مشروع ألف', $p->json('data.project.name'));
        $this->assertSame('عميل ألف', $p->json('data.project.client.name'));
        // لا عمودَ داخليّاً في الحمولة أصلاً (ما لا يُحمَّل لا يُسرَّب)
        $this->assertArrayNotHasKey('cost', $p->json('data.project'));
        $this->assertArrayNotHasKey('budget', $p->json('data.project'));

        $this->withHeaders($h)->getJson('/api/mobile/v1/portal/projects/' . $this->projectB->id)
            ->assertStatus(404);

        $inv = FinDocument::where('doc_no', 'INV-A1')->first();
        $i = $this->withHeaders($h)->getJson('/api/mobile/v1/portal/invoices/' . $inv->id);
        $i->assertOk();
        $this->assertSame('1500.5', (string) (float) $i->json('data.invoice.total'));

        // فاتورةُ مشترياتٍ (تكلفة) لا تُفتح ولو كانت منسوبةً لعميله
        $pur = FinDocument::where('doc_no', 'PUR-A1')->first();
        $this->withHeaders($h)->getJson('/api/mobile/v1/portal/invoices/' . $pur->id)
            ->assertStatus(404);
    }

    public function test_client_conversations_require_membership_and_client_audience_and_hide_internal(): void
    {
        $this->seedPortalWorld();

        // غرفةُ عميلٍ هو عضوٌ فيها (تظهر) + قناةٌ داخليّةٌ هو عضوٌ فيها خطأً (404)
        $clientRoom = Conversation::create(['kind' => 'channel', 'title' => 'غرفة مشروع ألف',
            'audience' => 'client']);
        ConversationMember::create(['conversation_id' => $clientRoom->id, 'user_id' => $this->clientUser->id]);
        $internalRoom = Conversation::create(['kind' => 'channel', 'title' => 'غرفة الفريق الداخلية',
            'audience' => 'internal']);
        ConversationMember::create(['conversation_id' => $internalRoom->id, 'user_id' => $this->clientUser->id]);

        Comment::create(['module' => 'channel', 'record_id' => $clientRoom->id,
            'conversation_id' => $clientRoom->id, 'user_id' => $this->employee->id,
            'body' => 'مرحباً بالعميل', 'internal' => false, 'created_at' => now()]);
        Comment::create(['module' => 'channel', 'record_id' => $clientRoom->id,
            'conversation_id' => $clientRoom->id, 'user_id' => $this->employee->id,
            'body' => 'همسة فريق داخلية', 'internal' => true, 'created_at' => now()]);

        $h = $this->clientHeaders();

        $list = $this->withHeaders($h)->getJson('/api/mobile/v1/portal/conversations');
        $list->assertOk();
        $titles = collect($list->json('data.conversations'))->pluck('title')->all();
        $this->assertContains('غرفة مشروع ألف', $titles);
        $this->assertNotContains('غرفة الفريق الداخلية', $titles,
            'العضويّةُ وحدَها لا تكفي — الجمهورُ شرط (§17)');

        $conv = $this->withHeaders($h)
            ->getJson('/api/mobile/v1/portal/conversations/' . $clientRoom->id);
        $conv->assertOk();
        $bodies = collect($conv->json('data.messages'))->pluck('body')->all();
        $this->assertSame(['مرحباً بالعميل'], $bodies, 'الموسومُ internal لا يُبثّ لعميل');

        $this->withHeaders($h)
            ->getJson('/api/mobile/v1/portal/conversations/' . $internalRoom->id)
            ->assertStatus(404);
    }

    public function test_suspended_membership_is_closed_fail_empty_world(): void
    {
        $this->seedPortalWorld();
        ClientMembership::where('user_id', $this->clientUser->id)
            ->update(['status' => 'suspended']);

        $res = $this->withHeaders($this->clientHeaders())->getJson('/api/mobile/v1/portal/home');
        $res->assertOk();
        $this->assertSame([], $res->json('data.clients'));
        $this->assertSame([], $res->json('data.projects'));
        $this->assertSame([], $res->json('data.invoices'),
            'عضويّةٌ معلّقةٌ = عالمٌ فارغٌ صادق، لا كلُّ شيء (فشلٌ مغلق)');
    }

    public function test_home_for_client_account_serves_portal_home_not_internal_dashboard(): void
    {
        $this->seedPortalWorld();
        $res = $this->withHeaders($this->clientHeaders())->getJson('/api/mobile/v1/home');
        $res->assertOk();
        $this->assertSame('client', $res->json('data.mode'),
            'بيتُ العميل بوّابتُه — لا لوحةٌ داخليّةٌ بأزرارٍ مخفيّة (§12)');
        $this->assertArrayNotHasKey('my_work', $res->json('data'));
    }
}
