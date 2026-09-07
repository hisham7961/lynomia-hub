<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (Work OS · الطور C · WP-C.4 · §6/§7 · نقد C9 مُلزِم) طيُّ الرسائلِ المباشرةِ في
 * حاويةِ المحادثةِ الواحدة (SF-3) — هويّةٌ واحدةٌ لا هويّتان.
 *
 * DM يبقى في `dm_messages` (لا محرّكَ حاويةٍ ثانٍ)، لكنه يُنسَب إلى صفِّ
 * `conversations(kind='dm')` بـ`conversation_id` مشتقٍّ حتميّاً من `thread_key` —
 * فالمفتاحان يحلّان إلى الحاويةِ نفسِها، وتصبح العضويّةُ والرقابةُ (§6) والبحثُ
 * نظاماً واحداً على DM والقنوات معاً. هنا:
 *  (١) خيطٌ قديمٌ (قبل الطيّ) يصبح مكتشَفاً عبر حاويتِه بعد التعبئةِ الخلفيّة (C9).
 *  (٢) هويّةٌ واحدة: `thread_key` و`conversation_id` يتّفقان لكلِّ رسائلِ الخيط.
 *  (٣) رسالةٌ جديدةٌ تُنشئ حاويتَها وعضويّتَي طرفيها ثم تعيد استعمالَهما.
 *  (٤) عزلُ الشركة/العميل (A.5) قائم: لا حاويةَ DM عبر الشركات، والعميلُ لا يبلغها.
 *
 * يمتدّ نمطَ `SearchDmLeakTest` و`WorkOsFeedDmScopeTest` — لا يستنسخهما.
 */
class WorkOsDmFoldTest extends TestCase
{
    /** مستخدمٌ داخليٌّ معزولٌ على شركاتٍ بعينها (دورٌ غيرُ مالك كي يُفعَّل العزل) */
    protected function scopedUser(string $name, string $email, array $companies): User
    {
        $role = Role::create(['name' => $name . ' دور', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => $name, 'email' => $email, 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now(),
            'companies' => $companies]);
    }

    /** حسابُ عميلٍ خارجيّ (account_type=client) — يردُّه PortalGuard قبل المصفوفة */
    protected function clientUser(): User
    {
        $role = Role::create(['name' => 'دور عميل', 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'عميلٌ خارجي', 'email' => 'client-fold@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'account_type' => 'client']);
    }

    /** إعادةُ تشغيلِ هجرةِ التعبئةِ نفسِها (idempotent) على تاريخٍ مزروعٍ بعد الترحيل */
    protected function runDmBackfill(): void
    {
        $file = glob(database_path('migrations/*c_backfill_dm_conversations.php'))[0];
        (require $file)->up();
    }

    /* ── ١) خيطٌ قديمٌ يصبح مكتشَفاً عبر الحاوية بعد التعبئة (C9) ── */

    public function test_preexisting_dm_thread_becomes_discoverable_via_container_after_backfill(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركة الخيط']);
        $a = $this->scopedUser('طرف ألف', 'fa@t.local', [$co->id]);
        $b = $this->scopedUser('طرف باء', 'fb@t.local', [$co->id]);
        $key = DmMessage::threadKey($a->id, $b->id);

        // تاريخٌ قائمٌ قبل الطيّ: رسائلُ خامٌّ بلا conversation_id (نظيرُ ما قبل C.4)
        DB::table('dm_messages')->insert([
            ['id' => (string) Str::uuid(), 'thread_key' => $key, 'from_id' => $a->id, 'to_id' => $b->id,
                'company_id' => $co->id, 'body' => 'رسالةٌ قديمة ١', 'created_at' => now()->subMinutes(10)],
            ['id' => (string) Str::uuid(), 'thread_key' => $key, 'from_id' => $b->id, 'to_id' => $a->id,
                'company_id' => $co->id, 'body' => 'رسالةٌ قديمة ٢', 'created_at' => now()->subMinutes(9)],
        ]);

        $cid = DmMessage::conversationIdForThread($key);

        // قبل التعبئة: لا حاويةَ ولا ربط — الرقابةُ عمياءُ عن هذا الخيط (C9)
        $this->assertFalse(Conversation::whereKey($cid)->exists());
        $this->assertSame(2, DmMessage::where('thread_key', $key)->whereNull('conversation_id')->count());

        $this->runDmBackfill();

        // بعدها: حاويةٌ kind=dm بالهويّةِ الحتميّةِ عينِها، ورسائلُ الخيطِ كلُّها موسومةٌ بها
        $conv = Conversation::whereKey($cid)->first();
        $this->assertNotNull($conv, 'التعبئةُ لم تُنشئ حاويةَ الخيطِ القديم — الرقابةُ تبقى عمياءَ عنه');
        $this->assertSame('dm', $conv->kind);
        $this->assertSame($co->id, $conv->company_id, 'الحاويةُ لم ترث شركةَ رسائلها — العزلُ يختلّ');
        $this->assertSame(0, DmMessage::where('thread_key', $key)->whereNull('conversation_id')->count(),
            'بقيت رسائلُ الخيطِ القديمِ بلا هويّةِ حاوية — غيرَ مرئيّةٍ عبر الحاوية للرقابة');
        $this->assertSame($cid, DmMessage::where('thread_key', $key)->orderBy('id')->first()->conversation_id);

        // وعضويّةُ الطرفين — بها تكتشفهما الرقابةُ والبحثُ كنظامٍ واحد
        $members = ConversationMember::where('conversation_id', $cid)->pluck('user_id')->all();
        $this->assertCount(2, $members, 'التعبئةُ لم تُنشئ عضويّتَي طرفي الخيط');
        $this->assertContains($a->id, $members);
        $this->assertContains($b->id, $members);

        // idempotent: إعادةُ التشغيل لا تُكرّر حاويةً ولا عضويّة
        $this->runDmBackfill();
        $this->assertSame(1, Conversation::where('kind', 'dm')->count());
        $this->assertSame(2, ConversationMember::where('conversation_id', $cid)->count());
    }

    /* ── ٢) هويّةٌ واحدة: thread_key وconversation_id يتّفقان ── */

    public function test_a_dm_has_one_container_identity(): void
    {
        $this->seedCore();
        $me = $this->owner;
        $other = $this->employee;

        $this->actingAs($me)->post('/dm/' . $other->id, ['body' => 'أولى'])->assertRedirect();
        $this->actingAs($other)->post('/dm/' . $me->id, ['body' => 'ردّ'])->assertRedirect();

        $key = DmMessage::threadKey($me->id, $other->id);
        $cid = DmMessage::conversationIdForThread($key);

        // حاويةٌ واحدةٌ للثنائيّ لا هويّتان — الاتجاهان خيطٌ واحد
        $this->assertSame(1, Conversation::where('kind', 'dm')->count(), 'نشأت حاويتان لخيطٍ واحد — هويّتان');
        $this->assertSame(1, DmMessage::distinct('thread_key')->count('thread_key'));

        // كلُّ رسائلِ الخيطِ تحمل الهويّةَ الحتميّةَ نفسَها = هويّةُ thread_key
        $convIds = DmMessage::where('thread_key', $key)->pluck('conversation_id')->unique()->values();
        $this->assertCount(1, $convIds, 'رسائلُ الخيطِ الواحدِ نُسبت إلى أكثرَ من حاوية');
        $this->assertSame($cid, $convIds->first(),
            'thread_key وconversation_id لا يحلّان إلى الحاويةِ نفسِها — هويّتان لا واحدة');
    }

    /* ── ٣) رسالةٌ جديدةٌ تُنشئ الحاويةَ ثم تعيد استعمالها ── */

    public function test_a_new_dm_autocreates_then_reuses_its_conversation_and_members(): void
    {
        $this->seedCore();
        $me = $this->owner;
        $other = $this->employee;
        $key = DmMessage::threadKey($me->id, $other->id);
        $cid = DmMessage::conversationIdForThread($key);

        // أوّلُ إرسالٍ يخلق الحاويةَ وعضويّتَي الطرفين ويسِمُ الرسالة
        $this->actingAs($me)->post('/dm/' . $other->id, ['body' => 'أنشئ الحاوية'])->assertRedirect();
        $this->assertTrue(Conversation::whereKey($cid)->exists(), 'الإرسالُ لم يُنشئ حاويةَ الخيط');
        $this->assertSame($cid, DmMessage::where('thread_key', $key)->first()->conversation_id,
            'الرسالةُ الجديدةُ لم تُوسَم بهويّةِ حاويتها');
        $this->assertSame(2, ConversationMember::where('conversation_id', $cid)->count(),
            'لم تُنشأ عضويّةُ الطرفين');

        // إرسالٌ ثانٍ في الخيطِ نفسِه يعيد استعمالها — لا حاويةٌ ثانيةٌ ولا عضويّةٌ مكرّرة
        $this->actingAs($me)->post('/dm/' . $other->id, ['body' => 'أعِد الاستعمال'])->assertRedirect();
        $this->assertSame(1, Conversation::where('kind', 'dm')->count(), 'الإرسالُ الثاني خلق حاويةً ثانية');
        $this->assertSame(2, ConversationMember::where('conversation_id', $cid)->count(),
            'الإرسالُ الثاني كرّر عضويّة');
        $this->assertSame(2, DmMessage::where('conversation_id', $cid)->count());
    }

    /* ── ٤) عزلُ الشركة/العميل (A.5) قائمٌ فوق الطيّ ── */

    public function test_isolation_holds_no_cross_company_dm_container(): void
    {
        $this->seedCore();
        $coA = Company::create(['name_ar' => 'شركة ألف']);
        $coB = Company::create(['name_ar' => 'شركة باء']);
        $userA = $this->scopedUser('أحمد ألف', 'ca@t.local', [$coA->id]);
        $userB = $this->scopedUser('بدر باء', 'cb@t.local', [$coB->id]);
        $userA2 = $this->scopedUser('علي ألف', 'ca2@t.local', [$coA->id]);

        // عبر الشركات: مرفوضٌ (A.5) — لا رسالةَ ولا حاوية
        $this->actingAs($userA)->post('/dm/' . $userB->id, ['body' => 'تسلل'])->assertRedirect();
        $this->assertSame(0, DmMessage::where('from_id', $userA->id)->where('to_id', $userB->id)->count());
        $crossCid = DmMessage::conversationIdForThread(DmMessage::threadKey($userA->id, $userB->id));
        $this->assertFalse(Conversation::whereKey($crossCid)->exists(),
            'نشأت حاويةُ DM عبر الشركات — تسرّبُ عزلٍ يجعل الرقابةَ ترى ثنائيّاً محظوراً');

        // داخلَ الشركةِ نفسِها: حاويةٌ بشركتِها الصحيحة وعضويّةُ الطرفين
        $this->actingAs($userA)->post('/dm/' . $userA2->id, ['body' => 'داخل الشركة'])->assertRedirect();
        $cid = DmMessage::conversationIdForThread(DmMessage::threadKey($userA->id, $userA2->id));
        $conv = Conversation::whereKey($cid)->first();
        $this->assertNotNull($conv);
        $this->assertSame($coA->id, $conv->company_id, 'حاويةُ DM داخل الشركةِ لم تُوسَم بشركتها — يختلّ نطاقُ الرقابة');
        $this->assertSame(2, ConversationMember::where('conversation_id', $cid)->count());

        // وحسابُ العميل لا يبلغ DM أصلاً (PortalGuard) — لا حاويةَ رقابةٍ يصلها العميل
        $client = $this->clientUser();
        $this->actingAs($client)->post('/dm/' . $userA->id, ['body' => 'من عميل'])->assertNotFound();
        $this->assertSame(0, DmMessage::where('from_id', $client->id)->count());
    }
}
