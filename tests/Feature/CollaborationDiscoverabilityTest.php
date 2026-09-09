<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\HubNotification;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **قبولُ الاكتشاف — مركزُ التواصل (§40/§42).**
 *
 * «المُنجَزُ غيرُ القابلِ للاكتشاف = ناقص.» يُثبِت هذا أنّ مركزَ التواصل وجهةٌ في
 * التنقّلِ العاديّ (لا بحثٌ فقط · DEFECT A)، وأنّ الإنشاءَ والانتباهَ والمحفوظاتِ
 * والبحثَ وجهاتٌ واضحةٌ **داخلَ** المركز، وأنّ العميلَ لا يبلغ شيئاً من ذلك.
 */
class CollaborationDiscoverabilityTest extends TestCase
{
    private function client(): User
    {
        $role = Role::create(['name' => 'عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => 'cl@test.local', 'account_type' => 'client',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    /* ═══════════ DEFECT A — الشريطُ الجانبيّ، لا البحثُ وحده ═══════════ */

    public function test_collaboration_center_appears_in_the_main_sidebar_for_internal_users(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->employee)->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('مركز التواصل', $html, 'مركزُ التواصل غائبٌ عن الشريط (DEFECT A)');
        $this->assertStringContainsString('href="' . route('collab.center') . '"', $html,
            'لا رابطَ لِمركز التواصل في الشريط');
    }

    public function test_collaboration_is_a_top_link_not_search_only(): void
    {
        $this->seedCore();
        // في كتالوج الروابط العلويّة (مصدرُ الشريط) — لا في البحثِ وحده
        $keys = collect(hub_top_links($this->employee))->pluck('key');
        $this->assertTrue($keys->contains('collab'), 'مركزُ التواصل ليس وجهةً في التنقّل العاديّ');
    }

    public function test_client_never_sees_collaboration_in_navigation_or_reaches_it(): void
    {
        $this->seedCore();
        $client = $this->client();

        // لا في كتالوج روابطه
        $keys = collect(hub_top_links($client))->pluck('key');
        $this->assertFalse($keys->contains('collab'), 'العميلُ يرى مركزَ التواصل في تنقّله — تسريب');

        // ولا يبلغ المركزَ ولا الانتباهَ أصلاً (٤٠٤)
        $this->actingAs($client)->get(route('collab.center'))->assertNotFound();
        $this->actingAs($client)->get(route('collab.attention'))->assertNotFound();
    }

    /* ═══════════ §9/§17/§18 — لوحُ الإنشاء داخلَ المركز ═══════════ */

    public function test_center_exposes_a_prominent_create_action(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get(route('collab.center'))->assertOk()->getContent();
        $this->assertStringContainsString('➕ إنشاء', $html, 'لا زرَّ إنشاءٍ بارزٍ في المركز');
        // الخياراتُ الأربعةُ روابطٌ حقيقيّةٌ داخلَ المركز
        $this->assertStringContainsString(route('collab.center', ['new' => 'group']), $html);
        $this->assertStringContainsString(route('collab.center', ['new' => 'dm']), $html);
        $this->assertStringContainsString(route('collab.center', ['new' => 'channel']), $html);
        $this->assertStringContainsString(route('collab.center', ['new' => 'pchannel']), $html);
    }

    public function test_new_group_panel_renders_participant_picker_unselected(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get(route('collab.center', ['new' => 'group']))
            ->assertOk()->assertSee('مجموعة جديدة')->assertSee('data-pp', false)->getContent();
        // الافتراضُ صفرُ تحديد
        $this->assertStringContainsString('المحدَّدون: <b>0</b>', $html);
        $this->assertSame(0, preg_match_all('/data-pp-cb[^>]*\schecked/i', $html));
    }

    public function test_new_dm_panel_renders_searchable_people_and_opens_in_collab(): void
    {
        $this->seedCore();
        $html = $this->actingAs($this->employee)->get(route('collab.center', ['new' => 'dm']))
            ->assertOk()->assertSee('رسالة جديدة')->getContent();
        // كلُّ زميلٍ رابطٌ يفتح المحادثةَ في المركز (?dm=)
        $this->assertStringContainsString(route('collab.center', ['dm' => $this->owner->id]), $html);
    }

    public function test_new_channel_and_private_channel_panels_render(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get(route('collab.center', ['new' => 'channel']))
            ->assertOk()->assertSee('قناة جديدة')->assertSee('مدى الظهور');
        $this->actingAs($this->employee)->get(route('collab.center', ['new' => 'pchannel']))
            ->assertOk()->assertSee('قناة خاصّة');
    }

    /* ═══════════ §13/§17 — الإنشاءُ من المركزِ يُفتَح في المركز ═══════════ */

    public function test_group_created_from_collab_opens_inside_collab(): void
    {
        $this->seedCore();
        $resp = $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $this->employee->id], 'origin' => 'collab', 'title' => 'مجموعةٌ من المركز',
        ]);
        $g = Conversation::groups()->latest('id')->first();
        $this->assertNotNull($g);
        $resp->assertRedirect(route('collab.center', ['c' => $g->id]));

        // وبلا origin=collab يبقى السلوكُ القديم (صفحةُ المحادثة) — لا كسر.
        // مُعرِّفاتُ المجموعاتِ UUID (لا تسلسل) — نلتقط الجديدةَ بالاستثناءِ لا بـlatest('id') (قرعة)
        $resp2 = $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $this->viewer->id],
        ]);
        $g2 = Conversation::groups()->where('id', '!=', $g->id)->first();
        $this->assertNotNull($g2);
        $resp2->assertRedirect(route('conversations.show', $g2->id));
    }

    public function test_channel_created_from_collab_opens_inside_collab(): void
    {
        $this->seedCore();
        $resp = $this->actingAs($this->owner)->post(route('conversations.store'), [
            'title' => 'قناةٌ من المركز', 'audience' => 'internal', 'visibility' => 'company', 'origin' => 'collab',
        ]);
        $ch = Conversation::channels()->latest('id')->first();
        $this->assertNotNull($ch);
        $resp->assertRedirect(route('collab.center', ['c' => $ch->id]));
    }

    /* ═══════════ §14 — بدءُ مجموعةٍ من محادثة ═══════════ */

    public function test_dm_context_offers_start_group_and_preselects_partner(): void
    {
        $this->seedCore();
        // لوحُ إنشاءِ مجموعةٍ بالطرفِ محدَّداً سلفاً
        $html = $this->actingAs($this->owner)->get(route('collab.center', ['new' => 'group', 'with' => $this->employee->id]))
            ->assertOk()->getContent();
        // الطرفُ محدَّدٌ سلفاً (مربّعُه checked) والعدُّ ١
        $this->assertStringContainsString('المحدَّدون: <b>1</b>', $html);
        $this->assertSame(1, preg_match_all('/data-pp-cb[^>]*\schecked/i', $html));
    }

    /* ═══════════ §15 — إدارةُ المجموعةِ في لوحِ سياقِ المركز ═══════════ */

    public function test_group_management_renders_in_collab_context_panel(): void
    {
        $this->seedCore();
        // مجموعةٌ يملكها المالكُ وفيها الموظّفة — يُفتَح خيطُها في المركز
        $g = Conversation::create(['kind' => 'group', 'audience' => 'internal', 'visibility' => 'private',
            'created_by' => $this->owner->id]);
        ConversationMember::create(['conversation_id' => $g->id, 'user_id' => $this->owner->id,
            'role' => 'owner', 'last_read_at' => now()]);
        ConversationMember::create(['conversation_id' => $g->id, 'user_id' => $this->employee->id, 'role' => 'member']);

        $this->actingAs($this->owner)->get(route('collab.center', ['c' => $g->id]))->assertOk()
            ->assertSee('إضافةُ أشخاص')                 // §15 إضافةٌ بفرعٍ آمن
            ->assertSee('مغادرةُ المجموعة')              // §15 المغادرة
            ->assertSee('سيتم إنشاءُ مجموعةٍ جديدةٍ');   // §16 التفسيرُ الآمن
    }

    /* ═══════════ §19 — الانتباه (من محرّكِ الإشعاراتِ القائم) ═══════════ */

    public function test_attention_destination_shows_mentions_and_replies(): void
    {
        $this->seedCore();
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'mention',
            'text' => 'ذكرك المالكُ في مهمّة', 'read' => false, 'created_at' => now()]);
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'reply',
            'text' => 'ردّ المالكُ على تعليقك', 'read' => false, 'created_at' => now()]);
        // إشعارٌ من نوعٍ آخر لا يظهر في الانتباه
        HubNotification::create(['user_id' => $this->employee->id, 'kind' => 'assigned',
            'text' => 'أُسنِدت إليك مهمّة', 'read' => false, 'created_at' => now()]);

        $this->actingAs($this->employee)->get(route('collab.attention'))->assertOk()
            ->assertSee('الانتباه والإشارات')
            ->assertSee('ذكرك المالكُ في مهمّة')
            ->assertSee('ردّ المالكُ على تعليقك')
            ->assertDontSee('أُسنِدت إليك مهمّة');
    }

    /* ═══════════ §20/§21 — المحفوظاتُ والبحثُ وجهتان في المركز ═══════════ */

    public function test_saved_and_search_are_visible_in_collaboration(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get(route('collab.center'))->assertOk()
            ->assertSee(route('saved.index'), false)
            ->assertSee(route('search.messages'), false)
            ->assertSee(route('collab.attention'), false);
    }

    /* ═══════════ §22 — الحالةُ الفارغةُ تشرح المنتَج ═══════════ */

    public function test_empty_state_explains_the_product_with_actions(): void
    {
        $this->seedCore();
        $this->actingAs($this->employee)->get(route('collab.center'))->assertOk()
            ->assertSee('مركزُ التواصلِ الموحّد')
            ->assertSee('ابدأ رسالة')
            ->assertSee('أنشئ مجموعة')
            ->assertSee('عرض الإشارات');
    }
}
