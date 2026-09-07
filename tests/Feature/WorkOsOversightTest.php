<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DmMessage;
use App\Models\Role;
use App\Models\User;
use App\Support\StepUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **رقابةُ الاتصالات — ميزةُ امتثالٍ ظاهرةٌ مُدقَّقة** (Work OS · الطور C · WP-C.2 · §6).
 *
 * الرقابةُ **بابٌ ظاهرٌ لا خفيّ**: قارئٌ للقراءة فقط خلفَ دورٍ صريحٍ غيرِ المالك
 * (`collab.oversight_role`) + تصعيدِ مصادقةٍ (`hub_require_stepup`) + **سببٍ إلزاميّ**؛
 * لا يمسّ `read_at`/`read_by` (لا تحريكَ لحالة القراءة)، ولا يحرّر/يحذف رسالةَ غيره،
 * وكلُّ قراءةٍ تكتب صفَّ `hub_audit` يحمل القارئَ والسببَ ومعرّفَ المحادثة والعنوانَ
 * ومعرّفَ الطلب. يغطّي **محرّكَي الرسائل معاً**: قنواتُ `Comment` و`DmMessage` عبر
 * الحاوية الموحّدة (C.4) + مسحٌ خامٌّ لـ`dm_messages` (نقد C9) فلا يغيب قديمٌ.
 *
 * يمتدّ نمطَ `AuditScopeLeakTest` (صفُّ التدقيق المُنطَّق) و`FieldPermissionBypassTest`
 * (لا تجاوزَ للقراءة فقط) و`WorkOsPortalGuardTest` (عزلُ العميل) — لا استنساخ.
 */
class WorkOsOversightTest extends TestCase
{
    /** اسمُ دور الرقابة المُسنَد في الإعداد collab.oversight_role — دورٌ غير المالك */
    private const OVERSIGHT_ROLE = 'مراقبُ الامتثال';

    /**
     * ضابطُ رقابةٍ: دورٌ اسمُه = الإعداد، بلا مصفوفةٍ ولا رايةِ مالك — فالبوابةُ
     * على الاسم صراحةً لا على امتيازٍ مضمَّن (ليست باباً خفيّاً للمالك).
     */
    private function officer(): User
    {
        $role = Role::create(['name' => self::OVERSIGHT_ROLE, 'scope' => 'all',
            'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'ضابطُ الرقابة', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    /** حسابُ عميلٍ صلبٍ (account_type=client) — يُردّ بـPortalGuard قبل المتحكّم */
    private function clientUser(): User
    {
        $role = Role::create(['name' => 'دورُ عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => []]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /** يختم نافذةَ تصعيدٍ حقيقيّةً بكلمة المرور — كما في الإنتاج (لا تزوير للحالة) */
    private function freshStepUp(User $u): void
    {
        $this->actingAs($u)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])
            ->assertRedirect();
        $this->assertTrue(StepUp::fresh(), 'لم تُختَم نافذةُ التصعيد');
    }

    /** يبني قناةً (Comment engine) يملكها الموظفُ برسالةٍ فيها — يُعيد [conv, body] */
    private function seedChannel(string $body): array
    {
        $conv = new Conversation();
        $conv->kind = 'channel';
        $conv->title = 'قناةُ التحقيق';
        $conv->audience = 'internal';
        $conv->visibility = 'private';
        $conv->created_by = $this->employee->id;
        $conv->save();

        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id,
            'role' => 'owner', 'source' => 'explicit']);

        $c = Comment::create(['module' => 'channel', 'record_id' => $conv->id,
            'conversation_id' => $conv->id, 'user_id' => $this->employee->id,
            'body' => $body, 'read_by' => [$this->employee->id], 'created_at' => now()]);

        return [$conv, $c];
    }

    /** يبني حاويةَ DM حيّةً عبر مسار الإرسال الحقيقيّ — يُعيد [cid, msg] (msg غيرُ مقروء) */
    private function seedDm(string $body): array
    {
        $this->actingAs($this->employee)->post('/dm/' . $this->viewer->id, ['body' => $body])
            ->assertRedirect();

        $cid = DmMessage::conversationIdForThread(
            DmMessage::threadKey($this->employee->id, $this->viewer->id));
        $msg = DmMessage::where('from_id', $this->employee->id)
            ->where('to_id', $this->viewer->id)->orderByDesc('created_at')->orderByDesc('id')->first();

        return [$cid, $msg];
    }

    /* ────────── ١) بلا تصعيدٍ → تحويلٌ (٤٢٨/redirect)، وبلا أثرٍ ────────── */

    public function test_a_request_without_step_up_is_redirected_and_writes_no_audit(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv] = $this->seedChannel('رسالةٌ لا تُقرأ بلا تصعيد');

        $officer = $this->officer();   // بلا freshStepUp عمداً

        $this->actingAs($officer)->get('/oversight?reason=' . urlencode('تحقيق'))
            ->assertRedirect();
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode('تحقيق'))
            ->assertRedirect();

        // لا قراءةَ فلا أثرَ رقابيّ (البابُ لم يُفتح)
        $this->assertDatabaseMissing('audits', ['module' => 'oversight']);
    }

    /* ────────── ٢) بلا سببٍ → مرفوض (لا بيانات، لا أثرَ قراءة) ────────── */

    public function test_a_request_without_a_reason_is_rejected(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv, $c] = $this->seedChannel('نصٌّ لا يُكشَف بلا سبب');

        $officer = $this->officer();
        $this->freshStepUp($officer);

        // الفهرسُ بلا سبب: يُعرَض نموذجُ السبب لا البيانات — لا رسالةٌ ولا قناةٌ تُسرَّب
        $this->actingAs($officer)->get('/oversight')->assertOk()
            ->assertSee('السبب')
            ->assertDontSee('قناةُ التحقيق');

        // العرضُ بلا سبب: رفضٌ صريح (تحويل) — ولا صفَّ قراءةٍ يُكتب
        $this->actingAs($officer)->get('/oversight/' . $conv->id)->assertRedirect();
        $this->assertDatabaseMissing('audits',
            ['module' => 'oversight', 'action' => 'oversight.read', 'record_id' => $conv->id]);
    }

    /* ────────── ٣) الخاصيّةُ الجوهريّة §6: القراءةُ الرقابيّةُ لا تحرّك read_at ────────── */

    public function test_oversight_read_never_mutates_dm_read_state(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);

        $body = 'رقمُ حسابٍ سرّيٌّ في DM';
        [$cid, $msg] = $this->seedDm($body);
        $this->assertNull($msg->read_at, 'الرسالةُ الواردةُ غيرُ مقروءةٍ ابتداءً');

        $officer = $this->officer();
        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight/' . $cid . '?reason=' . urlencode('تحقيقٌ في تسريب'))
            ->assertOk()->assertSee($body);

        // القراءةُ الرقابيّةُ **لا** تختم الرسالةَ مقروءةً — الطرفُ لا يعلم، والإيصالُ حقُّه
        $this->assertNull(DmMessage::find($msg->id)->read_at,
            'القراءةُ الرقابيّةُ حرّكت read_at — كُسرت خاصيّةُ §6 الجوهريّة');
    }

    public function test_oversight_read_never_mutates_channel_read_by(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv, $c] = $this->seedChannel('رسالةُ قناةٍ لا تُختَم مقروءةً بالرقابة');

        $before = (array) Comment::find($c->id)->read_by;
        $this->assertContains($this->employee->id, $before, 'صاحبُ الرسالةِ في read_by ابتداءً');

        $officer = $this->officer();
        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode('مراجعة'))
            ->assertOk();

        $after = (array) Comment::find($c->id)->read_by;
        $this->assertSame($before, $after,
            'أُضيف القارئُ الرقابيُّ إلى read_by — القراءةُ الرقابيّةُ يجب ألّا تلمس حالةَ القراءة');
        $this->assertNotContains($officer->id, $after,
            'القارئُ الرقابيُّ دخل قائمةَ من قرأ رسالةَ غيره');
    }

    /* ────────── ٤) القارئُ الرقابيُّ لا يحرّر/يحذف رسالةَ غيره (٤٠٣) ────────── */

    public function test_oversight_reader_cannot_edit_or_delete_others_messages(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv, $c] = $this->seedChannel('رسالةٌ لا يحذفها إلا صاحبُها');
        [$cid, $msg] = $this->seedDm('رسالةٌ مباشرةٌ لا يمسّها غريب');

        $officer = $this->officer();
        $this->freshStepUp($officer);

        // حذفُ تعليقِ قناةِ غيره — مرفوض (صاحبُ التعليق أو المالكُ وحدَه)
        $this->actingAs($officer)->delete('/comments/' . $c->id)->assertForbidden();
        $this->assertNotNull(Comment::find($c->id), 'حُذفت رسالةُ غيره عبر الرقابة');

        // حذفُ رسالةِ DM لغيره — مرفوض (لصاحبها وحده)
        $this->actingAs($officer)->delete('/dm/msg/' . $msg->id)->assertForbidden();
        $this->assertNull(DmMessage::find($msg->id)->deleted_at, 'حُذفت رسالةُ DM لغيره عبر الرقابة');
    }

    /* ────────── ٥) صفُّ التدقيق: القارئ/السبب/معرّفُ المحادثة/العنوان/معرّفُ الطلب ────────── */

    public function test_every_oversight_read_writes_a_complete_audit_row(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv, $c] = $this->seedChannel('محتوىً يُقرأ رقابيّاً');

        $officer = $this->officer();
        $this->freshStepUp($officer);
        $reason = 'تحقيقٌ في تسريبِ بياناتِ العميل';
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode($reason))
            ->assertOk();

        $this->assertDatabaseHas('audits', [
            'user_id'   => $officer->id,          // القارئ
            'module'    => 'oversight',
            'action'    => 'oversight.read',
            'record_id' => $conv->id,             // معرّفُ المحادثة
            'reason'    => $reason,               // السببُ الإلزاميّ
        ]);

        $row = DB::table('audits')->where('module', 'oversight')->where('action', 'oversight.read')
            ->where('record_id', $conv->id)->orderByDesc('id')->first();
        $this->assertNotNull($row, 'لا صفَّ تدقيقٍ للقراءة الرقابيّة');
        $this->assertNotNull($row->ip, 'صفُّ الرقابةِ بلا عنوانِ IP');
        $this->assertNotNull($row->request_id, 'صفُّ الرقابةِ بلا معرّفِ طلب');
    }

    /* ────────── ٦) الرقابةُ ترى المحرّكَين: قناةٌ (Comment) ورسالةٌ مباشرة (DM) ────────── */

    public function test_oversight_reads_both_message_engines(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);

        [$conv] = $this->seedChannel('سرُّ القناةِ الداخليّة');
        [$dmCid] = $this->seedDm('سرُّ الرسالةِ المباشرة');

        $officer = $this->officer();
        $this->freshStepUp($officer);
        $reason = urlencode('مسحُ امتثال');

        // محرّكُ القناة (Comment عبر conversation_id)
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . $reason)
            ->assertOk()->assertSee('سرُّ القناةِ الداخليّة');

        // محرّكُ DM عبر الحاوية الموحّدة (C.4)
        $this->actingAs($officer)->get('/oversight/' . $dmCid . '?reason=' . $reason)
            ->assertOk()->assertSee('سرُّ الرسالةِ المباشرة');

        // ولافتةُ «وصولُ امتثالٍ — مُسجَّل» ظاهرةٌ على الشاشة (بابٌ ظاهرٌ لا خفيّ)
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . $reason)
            ->assertSee('وصولُ امتثالٍ — مُسجَّل');
    }

    /* ────────── ٧) نقد C9: مسحٌ خامٌّ لـdm_messages فلا يغيب DM قديمٌ بلا حاوية ────────── */

    public function test_oversight_discovers_raw_dm_rows_without_a_container_link(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);

        // حاويةٌ حيّةٌ + رسالتُها المربوطة
        [$dmCid] = $this->seedDm('رسالةٌ حديثةٌ مربوطةٌ بالحاوية');
        $key = DmMessage::threadKey($this->employee->id, $this->viewer->id);

        // رسالةٌ خامٌّ قديمةٌ بلا conversation_id (كأنها سبقت هجرةَ التعبئة C.4)
        DmMessage::create(['thread_key' => $key, 'from_id' => $this->viewer->id,
            'to_id' => $this->employee->id, 'body' => 'رسالةٌ قديمةٌ خامٌّ بلا حاوية',
            'created_at' => now()->subDay()]);

        $officer = $this->officer();
        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight/' . $dmCid . '?reason=' . urlencode('مسحٌ رقابيّ'))
            ->assertOk()
            ->assertSee('رسالةٌ حديثةٌ مربوطةٌ بالحاوية')
            ->assertSee('رسالةٌ قديمةٌ خامٌّ بلا حاوية');
    }

    /* ────────── ٨) داخليٌّ غيرُ رقابيٍّ → ٤٠٣ ────────── */

    public function test_a_non_oversight_internal_user_is_forbidden(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);
        [$conv] = $this->seedChannel('لا يبلغها غيرُ الرقابة');

        // الموظفُ داخليٌّ بدورٍ اسمُه ليس دورَ الرقابة — ومع تصعيدٍ وسببٍ يبقى ٤٠٣
        $this->freshStepUp($this->employee);
        $this->actingAs($this->employee)->get('/oversight?reason=' . urlencode('فضول'))
            ->assertForbidden();
        $this->actingAs($this->employee)->get('/oversight/' . $conv->id . '?reason=' . urlencode('فضول'))
            ->assertForbidden();
    }

    /* ────────── ٩) عميلٌ → ٤٠٤ (PortalGuard فوق الكل) ────────── */

    public function test_a_client_account_gets_404_on_oversight(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);

        $client = $this->clientUser();
        $this->actingAs($client)->get('/oversight')->assertNotFound();
        $this->actingAs($client)->get('/oversight/' . (string) Str::uuid())->assertNotFound();
    }

    /* ────────── ١٠) عزلُ الشركة دفاعاً في العمق: رقابةٌ مقيَّدةٌ لا تبلغ شركةً أخرى ────────── */

    public function test_a_company_scoped_officer_cannot_read_another_companys_conversation(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', self::OVERSIGHT_ROLE);

        $mine = \App\Models\Company::create(['name_ar' => 'شركتي']);
        $theirs = \App\Models\Company::create(['name_ar' => 'شركةٌ أخرى']);

        // قناةُ الشركةِ الأخرى
        $conv = new Conversation();
        $conv->kind = 'channel';
        $conv->title = 'قناةُ الشركةِ الأخرى';
        $conv->audience = 'internal';
        $conv->visibility = 'private';
        $conv->company_id = $theirs->id;
        $conv->created_by = $this->employee->id;
        $conv->save();
        Comment::create(['module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'user_id' => $this->employee->id, 'body' => 'سرُّ الشركةِ الأخرى', 'read_by' => [$this->employee->id],
            'created_at' => now()]);

        // ضابطُ رقابةٍ معزولٌ على «شركتي» — لا يبلغ قناةَ الشركةِ الأخرى (٤٠٤ دفاعاً في العمق)
        $role = Role::create(['name' => self::OVERSIGHT_ROLE, 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $officer = User::create(['name' => 'رقابةٌ معزولة', 'email' => Str::random(8) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$mine->id], 'password_changed_at' => now()]);

        $this->freshStepUp($officer);
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode('تحقيق'))
            ->assertNotFound();
        $this->assertDatabaseMissing('audits',
            ['module' => 'oversight', 'action' => 'oversight.read', 'record_id' => $conv->id]);
    }
}
