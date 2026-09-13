<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\Comment;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkUpdate;
use App\Support\AssetProjectService;
use App\Support\ClientPortalData;
use App\Support\ReportReview;
use Tests\TestCase;

/**
 * **إغلاقاتُ النطاق** (Permissions 360 · المتبقّي · 15.2 · 08.1 · 08.2 · 10.2 · 16.3).
 *
 * خمسُ ثغراتِ عزلٍ من فئةٍ واحدة: «سلطةٌ صحيحةٌ بلا حدودِ نطاق»:
 *   · 15.2 حسابُ عميلٍ زالت عضويّاتُه يُغلَق [] لا يُفتَح null على الكون.
 *   · 08.1 عضويّةُ المحادثةِ لا تكفي — عنوانُها (client_id) يُحاكَم على العضويّةِ الحيّة.
 *   · 08.2 منشورُ قناةٍ موسومٌ بشركةٍ لا يتفاعل معه قارئٌ محصورٌ خارجَها (٤٠٤).
 *   · 10.2 «hr:e» يراجع تقاريرَ موظّفي شركاتِه لا المنشأةِ كلِّها.
 *   · 16.3 تخصيصاتُ الأصول/المشاريع تُعزَل على شركةِ الطرفِ الآخر (والعالميُّ بلا شركةٍ يبقى).
 */
class Permissions360ScopeClosureTest extends TestCase
{
    private function user(string $email, array $matrix = [], array $companies = [], string $type = 'internal'): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => $companies ? 'company' : 'all',
            'flags' => [], 'matrix' => $matrix, 'companies' => $companies ?: null]);

        return User::create(['name' => 'مستخدم', 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'account_type' => $type,
            'password_changed_at' => now(), 'companies' => $companies ?: null]);
    }

    /* ═══════════ 15.2 — حسابُ العميلِ يُغلَق لا يُفتَح ═══════════ */

    public function test_client_account_with_no_active_membership_is_fail_closed(): void
    {
        $this->seedCore();
        $c = Client::create(['name' => 'عميلُ الإغلاق']);

        // عضويّتُه الوحيدةُ معلَّقة ⇒ [] (كان null = «بلا تقييد» — عينُ الثغرة)
        $u = $this->user('fc@test.local', ['fin' => ['v' => 1]], type: 'client');
        ClientMembership::create(['client_id' => $c->id, 'user_id' => $u->id,
            'role' => 'lead', 'status' => 'suspended', 'activated_at' => now()]);
        $this->assertSame([], hub_client_ids($u), 'عميلٌ معلَّقُ العضويّاتِ يُغلَق [] لا null');

        // وعميلٌ بلا أيِّ صفٍّ (المسارُ القديم users.clients فارغاً) يُغلَق أيضاً
        $u2 = $this->user('fc2@test.local', [], type: 'client');
        $this->assertSame([], hub_client_ids($u2), 'عميلٌ بلا قائمةٍ أصلاً يُغلَق كذلك');

        // والموظّفُ الداخليُّ بلا قائمةِ عملاءَ يبقى null (بلا تقييدِ عملاء) — لا كسرَ للقائم
        $emp = $this->user('fcemp@test.local', ['fin' => ['v' => 1]]);
        $this->assertNull(hub_client_ids($emp), 'الداخليُّ العامُّ يبقى غيرَ مقيَّدٍ بعملاء');
    }

    /* ═══════════ 08.1 — عنوانُ المحادثةِ يُحاكَم على العضويّةِ الحيّة ═══════════ */

    public function test_portal_conversations_honor_live_client_membership(): void
    {
        $this->seedCore();
        $a = Client::create(['name' => 'عميل ألف']);
        $b = Client::create(['name' => 'عميل باء']);

        $u = $this->user('conv@test.local', [], type: 'client');
        ClientMembership::create(['client_id' => $a->id, 'user_id' => $u->id,
            'role' => 'lead', 'status' => 'active', 'activated_at' => now()]);
        ClientMembership::create(['client_id' => $b->id, 'user_id' => $u->id,
            'role' => 'lead', 'status' => 'suspended', 'activated_at' => now()]);

        $mk = function (?string $clientId, string $title) use ($u): Conversation {
            $conv = Conversation::create(['kind' => 'channel', 'title' => $title,
                'audience' => 'client', 'client_id' => $clientId]);
            ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $u->id]);

            return $conv;
        };
        $cA = $mk((string) $a->id, 'غرفةُ ألف');
        $cB = $mk((string) $b->id, 'غرفةُ باء');       // صفُّ العضويّةِ باقٍ والعنوانُ معلَّق
        $cN = $mk(null, 'غرفةٌ بلا عنوان');

        $this->actingAs($u);
        $ids = ClientPortalData::conversationRows()->pluck('id')->map('strval');
        $this->assertTrue($ids->contains((string) $cA->id), 'محادثةُ عميلِه الفعّالِ ظاهرة');
        $this->assertTrue($ids->contains((string) $cN->id), 'المحادثةُ العامّةُ بلا عنوانٍ ظاهرة');
        $this->assertFalse($ids->contains((string) $cB->id), 'محادثةُ العميلِ المعلَّقِ غابت رغم صفِّ العضويّة');

        $this->assertSame((string) $cA->id, (string) ClientPortalData::conversationDetail((string) $cA->id)->id);
        try {
            ClientPortalData::conversationDetail((string) $cB->id);
            $this->fail('محادثةُ عميلٍ معلَّقٍ فُتحت بالمعرّفِ المباشر');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertTrue(true);   // تتحوّل ٤٠٤ في طبقةِ HTTP
        }
    }

    /* ═══════════ 08.2 — تفاعلُ القناةِ محكومٌ بشركةِ المنشور ═══════════ */

    public function test_feed_reaction_is_company_isolated(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $uA = $this->user('feeda@test.local', ['updates' => ['v' => 1]], [$coA->id]);

        $mk = fn (?string $co, string $body): Comment => Comment::create([
            'module' => 'feed', 'record_id' => null, 'body' => $body,
            'user_id' => $this->owner->id, 'company_id' => $co, 'created_at' => now()]);

        // منشورُ شركةِ باء: الخلاصةُ تحجبه عرضاً، والتفاعلُ بالمعرّفِ المباشرِ = ٤٠٤
        $other = $mk((string) $coB->id, 'منشورُ شركةِ باء');
        $this->actingAs($uA)->post(route('comments.react', $other->id), ['emoji' => '👍'])
            ->assertNotFound();
        $this->assertDatabaseMissing('reactions', ['comment_id' => $other->id]);

        // منشورُ شركتِه والإعلانُ العامُّ بلا شركةٍ: يتفاعل
        $mine = $mk((string) $coA->id, 'منشورُ شركةِ ألف');
        $this->actingAs($uA)->post(route('comments.react', $mine->id), ['emoji' => '👍'])->assertRedirect();
        $this->assertDatabaseHas('reactions', ['comment_id' => $mine->id, 'user_id' => $uA->id]);

        $pub = $mk(null, 'إعلانٌ عامّ');
        $this->actingAs($uA)->post(route('comments.react', $pub->id), ['emoji' => '👍'])->assertRedirect();
        $this->assertDatabaseHas('reactions', ['comment_id' => $pub->id, 'user_id' => $uA->id]);
    }

    /* ═══════════ 10.2 — «hr:e» ضمنَ شركاتِه ═══════════ */

    public function test_hr_editor_reviews_within_their_companies_only(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);

        $author = $this->user('wb@test.local', ['updates' => ['v' => 1, 'a' => 1]], [$coB->id]);
        Employee::create(['name' => 'موظفُ باء', 'status' => 'نشط',
            'user_id' => $author->id, 'company_id' => $coB->id]);
        $w = WorkUpdate::create(['done' => 'عملُ اليوم', 'hours' => 2]);
        $w->forceFill(['created_by' => $author->id])->save();

        // hr:e محصورٌ بألف: موظّفُ باء ليس من رعيّتِه (ولا فرعَ مشروعٍ ينقذه — البندُ بلا مشروع)
        $hrA = $this->user('hra@test.local', ['hr' => ['v' => 1, 'e' => 1]], [$coA->id]);
        $this->assertFalse(ReportReview::canReview($hrA, $w->fresh()),
            'hr:e المحصورُ بشركةِ ألف لا يراجع تقريرَ موظّفِ باء');

        // ونظيرُه المحصورُ بباء، والعامُّ غيرُ المحصور: يراجعان
        $hrB = $this->user('hrb@test.local', ['hr' => ['v' => 1, 'e' => 1]], [$coB->id]);
        $this->assertTrue(ReportReview::canReview($hrB, $w->fresh()));
        $hrAll = $this->user('hrall@test.local', ['hr' => ['v' => 1, 'e' => 1]]);
        $this->assertTrue(ReportReview::canReview($hrAll, $w->fresh()));

        // وكاتبٌ بلا سجلِّ موظّفٍ (لا شركةَ تُنسَب): يبقى عامّاً — لا كسرَ للقائم
        $free = WorkUpdate::create(['done' => 'عملٌ بلا موظّف', 'hours' => 1]);
        $free->forceFill(['created_by' => $this->owner->id])->save();
        $this->assertTrue(ReportReview::canReview($hrA, $free->fresh()),
            'بندٌ بلا شركةِ موظّفٍ يبقى على السلوكِ العامّ');
    }

    /* ═══════════ 16.3 — تخصيصاتُ الأصول/المشاريع بعينِ القارئ ═══════════ */

    public function test_asset_project_reads_are_isolated_by_viewer_company(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف', 'status' => 'نشطة']);
        $coB = Company::create(['name_ar' => 'شركة باء', 'status' => 'نشطة']);
        $pA = Project::create(['name' => 'مشروعُ ألف', 'status' => 'نشط', 'company_id' => $coA->id]);
        $aA = Asset::create(['name' => 'أصلُ ألف', 'type' => 'لابتوب', 'status' => 'متاح', 'company_id' => $coA->id]);
        $aG = Asset::create(['name' => 'أصلٌ عالميّ', 'type' => 'لابتوب', 'status' => 'متاح']);

        $svc = new AssetProjectService();
        $svc->assign($aA, $pA, $this->owner);
        $svc->assign($aG, $pA, $this->owner);

        // بلا قارئٍ (التوافقُ الرجعيّ) وللمالكِ: الصفّان
        $this->assertCount(2, $svc->activeForProject((string) $pA->id));
        $this->assertCount(2, $svc->activeForProject((string) $pA->id, 200, $this->owner));

        // قارئُ ألف يرى الاثنين (أصلُ شركتِه + العالميُّ بلا شركة)؛ قارئُ باء يرى العالميَّ وحدَه
        $vA = $this->user('apa@test.local', ['assets' => ['v' => 1], 'projects' => ['v' => 1]], [$coA->id]);
        $vB = $this->user('apb@test.local', ['assets' => ['v' => 1], 'projects' => ['v' => 1]], [$coB->id]);
        $this->assertCount(2, $svc->activeForProject((string) $pA->id, 200, $vA));
        $this->assertSame([(string) $aG->id],
            $svc->activeForProject((string) $pA->id, 200, $vB)->pluck('asset_id')->map('strval')->all(),
            'أصلُ شركةِ ألف محجوبٌ عن قارئِ باء — والعالميُّ بلا شركةٍ باقٍ (لا فقدَ قدرة)');

        // ومن جهةِ الأصلِ العالميّ: مشروعُ ألف يظهر لقارئِ ألف لا لقارئِ باء — نشطاً وتاريخاً
        $this->assertCount(1, $svc->activeForAsset((string) $aG->id, 100, $vA));
        $this->assertCount(0, $svc->activeForAsset((string) $aG->id, 100, $vB));
        $this->assertCount(1, $svc->historyForAsset((string) $aG->id, 100, $vA));
        $this->assertCount(0, $svc->historyForAsset((string) $aG->id, 100, $vB));
        $this->assertCount(1, $svc->historyForProject((string) $pA->id, 100, $vB),
            'تاريخُ المشروعِ لقارئِ باء يُبقي صفَّ الأصلِ العالميِّ وحدَه');
    }
}
