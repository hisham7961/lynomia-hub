<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\SavedMessage;
use App\Support\Collaboration;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **مركز التواصل — أساسُ المرحلة ٢ (§103).** أوليّاتٌ إضافيّةٌ فوق المحرّك الواحد:
 * تفضيلُ الإشعار لكلّ محادثة (§16) متزامناً مع الكتم الواحد، والمفضّلة (§15)،
 * والمحفوظات الشخصيّة (§27)، وعقدُ مؤشّر الجلب التدريجيّ (§39). إثباتٌ لا ادّعاء.
 */
class CollabFoundationTest extends TestCase
{
    private function member(array $over = []): ConversationMember
    {
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'ق', 'created_by' => $this->owner->id]);

        return ConversationMember::create(array_merge([
            'conversation_id' => $conv->id, 'user_id' => $this->owner->id, 'role' => 'member',
        ], $over));
    }

    /* ───────── §16 تفضيلُ الإشعار — allowlist + تزامنُ الكتم الواحد ───────── */

    public function test_notify_pref_is_allowlisted_and_stays_in_sync_with_the_single_mute(): void
    {
        $this->seedCore();
        $m = $this->member();

        // 'muted' يختم muted_at (فتُسقطه scopeUnmuted القائمة — كتمٌ واحد)
        $m->forceFill(['notify_pref' => 'muted'])->save();
        $this->assertNotNull($m->fresh()->muted_at, 'notify_pref=muted لم يزامن muted_at');
        $this->assertSame(0, ConversationMember::where('id', $m->id)->unmuted()->count());
        $this->assertSame('muted', $m->fresh()->effectiveNotifyPref());

        // 'mentions' يمسح الكتم
        $m->forceFill(['notify_pref' => 'mentions'])->save();
        $this->assertNull($m->fresh()->muted_at, 'العودةُ عن الكتم لم تمسح muted_at');
        $this->assertSame('mentions', $m->fresh()->effectiveNotifyPref());

        // قيمةٌ خارج القائمة تُرفَض (C10)
        try {
            $m->forceFill(['notify_pref' => 'sometimes'])->save();
            $this->fail('تفضيلُ إشعارٍ خارج القائمة قُبل');
        } catch (\InvalidArgumentException $e) {
        }
    }

    public function test_effective_notify_pref_defaults_to_all_and_mute_column_wins(): void
    {
        $this->seedCore();
        $m = $this->member();
        $this->assertSame('all', $m->effectiveNotifyPref());   // null ⇒ all

        // كتمٌ مباشرٌ بالعمود القديم (بلا notify_pref) ⇒ effective = muted
        $m->forceFill(['muted_at' => now()])->save();
        $this->assertSame('muted', $m->fresh()->effectiveNotifyPref());
    }

    /* ───────── §15 المفضّلة ───────── */

    public function test_favorite_is_personal_and_scoped(): void
    {
        $this->seedCore();
        $m = $this->member();
        $this->assertFalse($m->isFavorite());
        $this->assertSame(0, ConversationMember::query()->favorites()->count());

        $m->forceFill(['favorite_at' => now()])->save();
        $this->assertTrue($m->fresh()->isFavorite());
        $this->assertSame(1, ConversationMember::query()->favorites()->count());
    }

    /* ───────── §27 المحفوظات الشخصيّة ───────── */

    public function test_saved_message_is_unique_per_user_target_and_type_allowlisted(): void
    {
        $this->seedCore();
        $cid = (string) Str::uuid();

        $s = SavedMessage::create(['user_id' => $this->owner->id, 'target_type' => 'comment',
            'target_id' => $cid, 'note' => 'مهمّ']);
        $this->assertSame('مهمّ', $s->note);

        // نفسُ (المستخدم، النوع، الرسالة) لا يُحفَظ مرّتين
        try {
            SavedMessage::create(['user_id' => $this->owner->id, 'target_type' => 'comment', 'target_id' => $cid]);
            $this->fail('محفوظةٌ مكرَّرة قُبلت');
        } catch (\Illuminate\Database\QueryException $e) {
        }

        // نوعٌ خارج القائمة يُرفَض (C10)
        try {
            SavedMessage::create(['user_id' => $this->owner->id, 'target_type' => 'sms', 'target_id' => (string) Str::uuid()]);
            $this->fail('نوعُ محفوظةٍ خارج القائمة قُبل');
        } catch (\InvalidArgumentException $e) {
        }

        // نفسُ الرسالة لـDM (نوعٌ مختلف) تُحفَظ — النوعُ جزءٌ من الوحدانيّة
        $this->assertNotNull(SavedMessage::create(['user_id' => $this->owner->id, 'target_type' => 'dm', 'target_id' => $cid])->id);
    }

    /* ───────── §39 عقدُ مؤشّر الجلب التدريجيّ ───────── */

    public function test_incremental_cursor_round_trips_and_is_garbage_safe(): void
    {
        $iso = '2026-09-24 10:11:12';
        $id = (string) Str::uuid();
        $cur = Collaboration::encodeCursor($iso, $id);

        // مُرمَّزٌ آمنُ النقل (بلا +//= )
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $cur);
        $this->assertSame([$iso, $id], Collaboration::decodeCursor($cur));

        // الغائبُ والفاسدُ ⇒ null (من البداية بأمان) — لا استثناء
        $this->assertNull(Collaboration::decodeCursor(null));
        $this->assertNull(Collaboration::decodeCursor(''));
        $this->assertNull(Collaboration::decodeCursor('@@not-base64@@'));
        $this->assertNull(Collaboration::decodeCursor(Collaboration::encodeCursor('', '')));
    }

    public function test_normalize_notify_pref(): void
    {
        $this->assertSame('all', Collaboration::normalizeNotifyPref(null));
        $this->assertSame('all', Collaboration::normalizeNotifyPref('nonsense'));
        $this->assertSame('mentions', Collaboration::normalizeNotifyPref(' MENTIONS '));
        $this->assertSame('muted', Collaboration::normalizeNotifyPref('muted'));
    }
}
