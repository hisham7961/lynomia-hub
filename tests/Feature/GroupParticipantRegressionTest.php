<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **§41 — انحدارُ دقّةِ المشاركين (DEFECT B · إثباتٌ لا ادّعاء).**
 *
 * العيبُ المُبلَّغ: منتقي المشاركين كان `<select multiple>` غامضاً يُوهِم أنّ الجميعَ
 * محدَّدٌ/إجباريّ، أو لا يتيح اختيارَ أفرادٍ بعينهم. الصحيح: **الجميعُ يبدأ غيرَ محدَّد**،
 * والمستخدمُ يختار صراحةً، وغيرُ المحدَّدين يبقَون **خارجَ** المجموعة.
 *
 * يُثبِت المتنُ الخلفيُّ الدقّةَ الحرفيّة: من {A,B,C,D} يختار A و C → المجموعةُ
 * **بالضبط** {المُنشئ, A, C} دون B وD؛ والصفرُ يُرفَض؛ والواحدُ والجمعُ يُقبَلان؛
 * والافتراضُ في الواجهة صفرُ تحديد.
 */
class GroupParticipantRegressionTest extends TestCase
{
    /** أربعةُ زملاءَ داخليّين متاحين للاختيار (A,B,C,D) */
    private function candidates(): array
    {
        $role = Role::create(['name' => 'زميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $out = [];
        foreach (['A', 'B', 'C', 'D'] as $tag) {
            $out[$tag] = User::create([
                'name' => 'زميل ' . $tag, 'email' => strtolower($tag) . '@test.local',
                'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
                'password_changed_at' => now(),
            ]);
        }

        return $out;
    }

    /** معرّفاتُ أعضاءِ مجموعةٍ (كلُّهم) */
    private function memberIds(Conversation $g): array
    {
        return ConversationMember::where('conversation_id', $g->id)
            ->pluck('user_id')->map('strval')->sort()->values()->all();
    }

    /* ═══════════ الحالةُ المركزيّة (§12/§41): A+C فقط ═══════════ */

    public function test_selecting_A_and_C_creates_group_with_exactly_creator_A_and_C(): void
    {
        $this->seedCore();
        $p = $this->candidates();

        $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $p['A']->id, (string) $p['C']->id],
            'title'        => 'اختيارٌ دقيق',
        ])->assertRedirect();

        $g = Conversation::groups()->latest('id')->first();
        $this->assertNotNull($g);

        $expected = collect([$this->owner->id, $p['A']->id, $p['C']->id])->map('strval')->sort()->values()->all();
        $this->assertSame($expected, $this->memberIds($g),
            'المجموعةُ لا تحوي بالضبط {المُنشئ, A, C}');

        // B وD خارجُ المجموعة قطعاً — لم يُختارا فلا يُضمّان
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $p['B']->id)->exists(),
            'B غيرُ المختار دخل المجموعة');
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $p['D']->id)->exists(),
            'D غيرُ المختار دخل المجموعة');
        $this->assertSame(3, ConversationMember::where('conversation_id', $g->id)->count());
    }

    /* ═══════════ الافتراض: صفرُ تحديدٍ في الواجهة ═══════════ */

    public function test_picker_defaults_to_zero_selected(): void
    {
        $this->seedCore();
        $this->candidates();

        $html = $this->actingAs($this->owner)->get(route('groups.index'))->assertOk()
            ->assertSee('data-pp', false)                       // المنتقي حاضر
            ->assertSee('المحدَّدون: <b>0</b>', false)          // العدُّ الابتدائيّ صفر
            ->getContent();

        // لا مربّعَ اختيارِ مرشّحٍ محدَّدٌ ابتداءً (الجميعُ خارجٌ حتى يُختار)
        $checkedPickerBoxes = preg_match_all('/data-pp-cb[^>]*\schecked/i', $html);
        $this->assertSame(0, $checkedPickerBoxes, 'مرشّحٌ ظهر محدَّداً ابتداءً — العيبُ B');
    }

    /* ═══════════ صفرٌ يُرفَض ═══════════ */

    public function test_zero_selection_is_rejected(): void
    {
        $this->seedCore();
        $this->candidates();

        $before = Conversation::groups()->count();
        $this->actingAs($this->owner)->from(route('groups.index'))
            ->post(route('groups.store'), ['participants' => [], 'title' => 'بلا أحد'])
            ->assertRedirect(route('groups.index'))       // يعود بخطأ التحقّق
            ->assertSessionHasErrors('participants');
        $this->assertSame($before, Conversation::groups()->count(), 'أُنشئت مجموعةٌ بلا مشاركين');

        // وحتى بلا الحقلِ أصلاً (لا مربّعَ محدَّد) — يُرفَض
        $this->actingAs($this->owner)->from(route('groups.index'))
            ->post(route('groups.store'), ['title' => 'بلا حقل'])
            ->assertSessionHasErrors('participants');
        $this->assertSame($before, Conversation::groups()->count());
    }

    /* ═══════════ واحدٌ يُقبَل ═══════════ */

    public function test_one_selection_is_allowed(): void
    {
        $this->seedCore();
        $p = $this->candidates();

        $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $p['A']->id],
        ])->assertRedirect();

        $g = Conversation::groups()->latest('id')->first();
        $expected = collect([$this->owner->id, $p['A']->id])->map('strval')->sort()->values()->all();
        $this->assertSame($expected, $this->memberIds($g));
    }

    /* ═══════════ جمعٌ يُقبَل ═══════════ */

    public function test_multiple_selection_is_allowed(): void
    {
        $this->seedCore();
        $p = $this->candidates();

        $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $p['A']->id, (string) $p['B']->id, (string) $p['C']->id],
        ])->assertRedirect();

        $g = Conversation::groups()->latest('id')->first();
        $expected = collect([$this->owner->id, $p['A']->id, $p['B']->id, $p['C']->id])
            ->map('strval')->sort()->values()->all();
        $this->assertSame($expected, $this->memberIds($g));
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $p['D']->id)->exists());
    }

    /* ═══════════ إلغاءُ التحديد يعمل: المُرسَلُ وحده يُضمّ (لا تحديدَ خفيّ) ═══════════ */

    public function test_deselecting_keeps_only_submitted_participants(): void
    {
        $this->seedCore();
        $p = $this->candidates();

        // نموذجٌ لِمن اختار A و C ثمّ ألغى تحديدَ C قبل الإرسال → يُرسَل A فقط
        $this->actingAs($this->owner)->post(route('groups.store'), [
            'participants' => [(string) $p['A']->id],
        ])->assertRedirect();

        $g = Conversation::groups()->latest('id')->first();
        $this->assertTrue(ConversationMember::where('conversation_id', $g->id)->where('user_id', $p['A']->id)->exists());
        $this->assertFalse(ConversationMember::where('conversation_id', $g->id)->where('user_id', $p['C']->id)->exists(),
            'مشاركٌ أُلغيَ تحديدُه بقي في المجموعة');
    }
}
