<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\DmMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **سيناريو القبول §101 — تحقيقُ الامتثال: قراءةٌ رقابيّةٌ كاملةُ الأثر بلا لمسِ
 * حالةِ قراءة** (Work OS · الطور M · WP-M.4).
 *
 * يمتدّ `WorkOsOversightTest` (بوّاباتُ الرقابة مفردةً) ولا يستنسخه — وهذا الملفُّ
 * يثبت **التحقيقَ الواحدَ المتدفّق** عبر أسطح HTTP الحقيقية، ويشدّ خيطَ M.2:
 * معرّفُ الطلب الواحد (`X-Request-Id` على الردّ نفسِه) هو المختومُ في قيدِ
 * التدقيق الرقابيّ — لا معرّفَ ارتباطٍ ثانٍ:
 *
 *   موظفٌ ينشئ قناةً وينشر فيها ويُراسل زميلَه (المحرّكان معاً) ← الضابطُ بلا
 *   تصعيدٍ يُحال وبلا سببٍ يُرَدّ (ولا أثرَ في الحالين) ← بتصعيدٍ وسببٍ يقرأ
 *   **القناةَ وDM** ولافتةُ الامتثال ظاهرة ← حالةُ القراءة لم تُمَسّ في المحرّكين
 *   ← قيدا تدقيقٍ كاملان (قارئ/سبب/محادثة/IP/rid == رأسُ الردّ) ← لا يحرّر ولا
 *   يحذف رسالةَ غيره ← الفضوليُّ ٤٠٣ والعميلُ ٤٠٤.
 */
class WorkOsAcceptanceOversightTest extends TestCase
{
    private const ROLE = 'مراقبُ الامتثال';
    private const REASON = 'تحقيقٌ رسميّ في شبهة تسريب بيانات عميل';

    /** ضابطُ رقابة: دورٌ اسمُه الإعدادُ نفسُه، بلا مصفوفةٍ ولا رايةِ مالك */
    private function officer(): User
    {
        $role = Role::create(['name' => self::ROLE, 'scope' => 'all', 'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'ضابطُ الرقابة', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** يختم نافذةَ تصعيدٍ حقيقيّة بكلمة المرور (لا تزويرَ حالة) */
    private function freshStepUp(User $u): void
    {
        $this->actingAs($u)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])
            ->assertRedirect();
    }

    public function test_the_full_oversight_investigation_with_a_complete_trail_and_untouched_read_state(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::ROLE);

        /* ── (١) المادةُ الحيّة: قناةٌ ورسالةٌ مباشرة عبر الأسطح الحقيقية ── */

        $this->actingAs($this->employee)->post('/conversations',
            ['title' => 'قناةُ مشروعِ التحقيق', 'body' => 'سرُّ القناة: بياناتُ العميل خرجت'])
            ->assertSessionDoesntHaveErrors();
        $conv = Conversation::where('kind', 'channel')
            ->where('title', 'قناةُ مشروعِ التحقيق')->firstOrFail();
        $chMsg = Comment::where('conversation_id', $conv->id)->firstOrFail();
        $chReadBefore = (array) $chMsg->read_by;

        $this->actingAs($this->employee)
            ->post('/dm/' . $this->viewer->id, ['body' => 'سرُّ المراسلة: أرسلتُ الملفَّ خارجاً'])
            ->assertRedirect();
        $dmCid = DmMessage::conversationIdForThread(
            DmMessage::threadKey($this->employee->id, $this->viewer->id));
        $dmMsg = DmMessage::where('from_id', $this->employee->id)
            ->where('to_id', $this->viewer->id)->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $this->assertNull($dmMsg->read_at, 'الرسالةُ الواردةُ غيرُ مقروءةٍ ابتداءً');

        /* ── (٢) البوّابتان: بلا تصعيدٍ يُحال، وبلا سببٍ يُرَدّ — ولا أثرَ رقابيّ ── */

        $officer = $this->officer();
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode(self::REASON))
            ->assertRedirect();
        $this->assertDatabaseMissing('audits', ['module' => 'oversight']);

        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight/' . $conv->id)->assertRedirect();
        $this->assertDatabaseMissing('audits',
            ['module' => 'oversight', 'action' => 'oversight.read', 'record_id' => $conv->id]);

        /* ── (٣) القراءةُ الرقابيّة: المحرّكان معاً، ولافتةُ الامتثال ظاهرة ── */

        $chRes = $this->actingAs($officer)
            ->get('/oversight/' . $conv->id . '?reason=' . urlencode(self::REASON));
        $chRes->assertOk()->assertSee('سرُّ القناة: بياناتُ العميل خرجت')
            ->assertSee('وصولُ امتثالٍ — مُسجَّل');
        $chRid = (string) $chRes->headers->get('X-Request-Id');
        $this->assertNotSame('', $chRid, 'لا X-Request-Id على ردّ القراءة الرقابيّة');

        $dmRes = $this->actingAs($officer)
            ->get('/oversight/' . $dmCid . '?reason=' . urlencode(self::REASON));
        $dmRes->assertOk()->assertSee('سرُّ المراسلة: أرسلتُ الملفَّ خارجاً');
        $dmRid = (string) $dmRes->headers->get('X-Request-Id');

        /* ── (٤) الخاصيّةُ الجوهريّة §6: حالةُ القراءة لم تُمَسّ في المحرّكين ── */

        $this->assertNull(DmMessage::find($dmMsg->id)->read_at,
            'القراءةُ الرقابيّةُ ختمت DM مقروءةً — الطرفُ صار يعلم');
        $this->assertSame($chReadBefore, (array) Comment::find($chMsg->id)->read_by,
            'القراءةُ الرقابيّةُ حرّكت read_by في القناة');

        /* ── (٥) الأثرُ الكامل: قيدان بمعرّفَي الطلبَين الواحدَين نفسِهما (خيطُ M.2) ── */

        foreach ([[$conv->id, $chRid], [$dmCid, $dmRid]] as [$recordId, $rid]) {
            $rows = DB::table('audits')->where('module', 'oversight')
                ->where('action', 'oversight.read')->where('record_id', $recordId)
                ->orderBy('id')->get();
            $this->assertCount(1, $rows, 'قيدُ قراءةٍ رقابيّةٍ غائبٌ أو مكرَّر');
            $row = $rows->first();
            $this->assertSame($officer->id, $row->user_id, 'القيدُ بلا هويّة القارئ');
            $this->assertSame(self::REASON, $row->reason, 'القيدُ بلا السبب الإلزاميّ');
            $this->assertNotNull($row->ip, 'القيدُ بلا عنوان IP');
            $this->assertSame($rid, $row->request_id,
                'قيدُ الرقابة لا يحمل معرّفَ الطلب الواحد من رأس الردّ — معرّفُ ارتباطٍ ثانٍ؟');
        }

        /* ── (٦) قارئٌ لا كاتب: لا تحريرَ ولا حذفَ لرسالة غيره ── */

        $this->actingAs($officer)->delete('/comments/' . $chMsg->id)->assertForbidden();
        $this->assertNotNull(Comment::find($chMsg->id), 'حُذفت رسالةُ قناةٍ عبر الرقابة');
        $this->actingAs($officer)->delete('/dm/msg/' . $dmMsg->id)->assertForbidden();
        $this->assertNull(DmMessage::find($dmMsg->id)->deleted_at, 'حُذفت رسالةُ DM عبر الرقابة');

        /* ── (٧) البابُ لأهله: الفضوليُّ الداخليّ ٤٠٣، والعميلُ ٤٠٤ ── */

        $this->freshStepUp($this->employee);
        $this->actingAs($this->employee)
            ->get('/oversight/' . $dmCid . '?reason=' . urlencode('فضول'))->assertForbidden();

        $clientRole = Role::create(['name' => 'دورُ عميل ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);
        $client = User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $clientRole->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
        $this->actingAs($client)->get('/oversight')->assertNotFound();
        $this->actingAs($client)
            ->get('/oversight/' . $conv->id . '?reason=' . urlencode('اختراق'))->assertNotFound();
    }
}
