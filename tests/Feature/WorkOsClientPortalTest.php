<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Document;
use App\Models\Engagement;
use App\Models\FinDocument;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مساحةُ العميل — شلٌّ منفصلٌ أبسط** (Work OS · الطور B · WP-B.2 · §13/§82–86).
 *
 * بوابةٌ خلف `PortalGuard` (العميلُ فقط)، كلُّ قراءةٍ فيها معزولةٌ بعملاءِ القارئ
 * (`hub_client_ids`) وجمهورِ الوثيقة/المحادثة — لا رقمَ داخليٍّ (تكلفة/هامش/ربحية)
 * يظهر، ولا سجلَّ عميلٍ آخر يُبلَغ. ما يحرسه هذا الملف:
 *
 *  1) عميلٌ بلا بيانات يرى «لا بيانات بعد» صادقاً — لا تلفيقَ أرقام (§82).
 *  2) عزلٌ صلب: عميلُ ألف لا يبلغ مشروع/وثيقة/فاتورة عميلِ باء (٤٠٤ لا ٤٠٣).
 *  3) لا رقمَ داخليّ: تكلفةُ مشروعٍ وميزانيةُ ارتباطٍ مزروعتان لا تُعرَضان قط.
 *  4) الوثائقُ والمحادثاتُ بالجمهور: الداخليّةُ محجوبة، والعميليّةُ ظاهرة.
 *  5) المستخدمُ الداخليُّ لا يُحبَس ولا يُعطَّل (٥٠٠) — تحويلٌ للوحة.
 */
class WorkOsClientPortalTest extends TestCase
{
    protected function client(string $name = 'شركة ألف'): Client
    {
        return Client::create(['name' => $name, 'stage' => 'عميل حالي']);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) بعضويّةٍ فعّالةٍ في عميلٍ واحدٍ أو أكثر */
    protected function clientUser(array $clients): User
    {
        $role = Role::create(['name' => 'عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);
        $u = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);

        foreach ($clients as $c) {
            ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
                'role' => 'viewer', 'status' => 'active', 'activated_at' => now()]);
        }

        return $u;
    }

    /* ────────── ١) لا بيانات بعد — حالةٌ فارغةٌ صادقة ────────── */

    public function test_a_client_with_no_data_sees_an_honest_empty_state(): void
    {
        $this->seedCore();
        $a = $this->client();
        $u = $this->clientUser([$a]);

        // البوابةُ تُفتح لعميلٍ بلا مشاريع ولا وثائق ولا فواتير — بصدقٍ لا تلفيق
        foreach (['portal.home', 'portal.engagements', 'portal.projects',
                  'portal.documents', 'portal.invoices', 'portal.conversations'] as $route) {
            $this->actingAs($u)->get(route($route))
                ->assertOk()->assertSee('لا بيانات بعد');
        }
    }

    /* ────────── ٢) عزلٌ صلب: لا يبلغ سجلَّ عميلٍ آخر ────────── */

    public function test_a_client_cannot_reach_another_clients_project_document_or_invoice(): void
    {
        $this->seedCore();
        $a = $this->client('شركة ألف');
        $b = $this->client('شركة باء');
        $u = $this->clientUser([$a]);

        $mine = Project::create(['name' => 'مشروعُ ألف الظاهر', 'client_id' => $a->id, 'status' => 'نشط']);
        $theirs = Project::create(['name' => 'مشروعُ باء السرّي', 'client_id' => $b->id, 'status' => 'نشط']);

        $myDoc = Document::create(['name' => 'وثيقةُ ألف', 'audience' => 'client', 'client_id' => $a->id]);
        $theirDoc = Document::create(['name' => 'وثيقةُ باء', 'audience' => 'client', 'client_id' => $b->id]);

        $myInv = FinDocument::create(['doc_no' => 'INV-A1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $a->id, 'total' => 500, 'state' => 'مرسلة']);
        $theirInv = FinDocument::create(['doc_no' => 'INV-B1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $b->id, 'total' => 900, 'state' => 'مرسلة']);

        // القوائم: عميلُه وحدَه — لا سجلَّ باء
        $this->actingAs($u)->get(route('portal.projects'))
            ->assertOk()->assertSee('مشروعُ ألف الظاهر')->assertDontSee('مشروعُ باء السرّي');
        $this->actingAs($u)->get(route('portal.documents'))
            ->assertOk()->assertSee('وثيقةُ ألف')->assertDontSee('وثيقةُ باء');
        $this->actingAs($u)->get(route('portal.invoices'))
            ->assertOk()->assertSee('INV-A1')->assertDontSee('INV-B1');

        // الرابطُ المباشر لسجلِّ باء: ٤٠٤ لا ٤٠٣ — لا إثباتَ وجود
        $this->actingAs($u)->get(route('portal.project', $theirs->id))->assertNotFound();
        $this->actingAs($u)->get(route('portal.document', $theirDoc->id))->assertNotFound();
        $this->actingAs($u)->get(route('portal.invoice', $theirInv->id))->assertNotFound();

        // وسجلُّه هو يُفتح — لا حجبٌ شامل يزوّر العزل
        $this->actingAs($u)->get(route('portal.project', $mine->id))->assertOk()->assertSee('مشروعُ ألف الظاهر');
        $this->actingAs($u)->get(route('portal.document', $myDoc->id))->assertOk()->assertSee('وثيقةُ ألف');
        $this->actingAs($u)->get(route('portal.invoice', $myInv->id))->assertOk()->assertSee('INV-A1');
    }

    /* ────────── ٣) لا رقمَ داخليّ: تكلفة/ميزانية محجوبة ────────── */

    public function test_the_portal_renders_no_internal_only_figures(): void
    {
        $this->seedCore();
        $a = $this->client();
        $u = $this->clientUser([$a]);

        // رقمان داخليّان مميّزان: تكلفةُ المشروع وميزانيةُ الارتباط — لا يُعرَضان قط
        $e = Engagement::create(['name' => 'ارتباطُ ألف', 'client_id' => $a->id, 'status' => 'نشط',
            'revenue' => 12000, 'budget' => 888877]);
        $p = Project::create(['name' => 'مشروعُ ألف', 'client_id' => $a->id, 'engagement_id' => $e->id,
            'status' => 'نشط', 'progress' => 40, 'cost' => 777766, 'budget' => 999955,
            'url' => 'https://internal.example.test/staging', 'git' => 'git@internal:repo.git']);

        foreach ([route('portal.home'), route('portal.engagements'), route('portal.projects'),
                  route('portal.project', $p->id)] as $url) {
            $res = $this->actingAs($u)->get($url)->assertOk();
            $res->assertDontSee('777766');                 // تكلفةُ المشروع
            $res->assertDontSee('999955');                 // ميزانيةُ المشروع
            $res->assertDontSee('888877');                 // ميزانيةُ الارتباط
            $res->assertDontSee('internal.example.test');  // بنيةٌ تقنية (staging/url)
            $res->assertDontSee('git@internal');           // مستودعٌ تقنيّ
        }
    }

    /* ────────── ٤) الجمهور: داخليّةٌ محجوبة، عميليّةٌ ظاهرة ────────── */

    public function test_documents_and_conversations_honor_audience(): void
    {
        $this->seedCore();
        $a = $this->client();
        $u = $this->clientUser([$a]);

        // وثيقةٌ داخليّةٌ منسوبةٌ لعميله لكنها محجوبةٌ عنه، وأخرى عميليّةٌ ظاهرة
        Document::create(['name' => 'محضرٌ داخليّ لألف', 'audience' => 'internal', 'client_id' => $a->id]);
        Document::create(['name' => 'تقريرٌ مشترَك لألف', 'audience' => 'client', 'client_id' => $a->id]);

        $this->actingAs($u)->get(route('portal.documents'))->assertOk()
            ->assertSee('تقريرٌ مشترَك لألف')->assertDontSee('محضرٌ داخليّ لألف');

        // محادثةٌ داخليّةٌ هو عضوٌ فيها (محجوبة) وأخرى عميليّةٌ (ظاهرة) — الجمهورُ قبل العضوية
        $internalConv = Conversation::create(['kind' => 'channel', 'client_id' => $a->id,
            'audience' => 'internal', 'title' => 'قناةٌ داخليّة سرّية']);
        $clientConv = Conversation::create(['kind' => 'channel', 'client_id' => $a->id,
            'audience' => 'client', 'title' => 'قناةُ العميل المشترَكة']);
        foreach ([$internalConv, $clientConv] as $conv) {
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $u->id, 'role' => 'member']);
        }

        $this->actingAs($u)->get(route('portal.conversations'))->assertOk()
            ->assertSee('قناةُ العميل المشترَكة')->assertDontSee('قناةٌ داخليّة سرّية');

        // والرابطُ المباشرُ للقناة الداخليّة: ٤٠٤ ولو كان عضواً
        $this->actingAs($u)->get(route('portal.conversation', $internalConv->id))->assertNotFound();
        $this->actingAs($u)->get(route('portal.conversation', $clientConv->id))->assertOk()
            ->assertSee('قناةُ العميل المشترَكة');
    }

    /* ────────── ٥) الداخليُّ لا يُحبَس ولا يُعطَّل ────────── */

    public function test_an_internal_user_is_not_trapped_or_500ed(): void
    {
        $this->seedCore();

        // المالكُ (internal) على البوابة: تحويلٌ للوحة لا ٥٠٠ ولا حبس
        foreach (['portal.home', 'portal.engagements', 'portal.projects',
                  'portal.documents', 'portal.invoices', 'portal.conversations'] as $route) {
            $this->actingAs($this->owner)->get(route($route))
                ->assertRedirect(route('dashboard'));
        }
    }
}
