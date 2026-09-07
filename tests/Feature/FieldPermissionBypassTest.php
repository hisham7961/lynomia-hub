<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Support\StepUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * صلاحيات مستوى الحقل تُفرض في مسارٍ واحد وتُتجاوَز في ستة.
 *
 * العقد المعلن في الشيفرة نفسها (ModuleController::fill):
 * «حقلٌ مخفيٌّ أو قراءةٌ فقط لدور المستخدم: **لا يُكتب أبداً حتى لو حُقن في
 * الطلب**». و`FieldPermissionsTest` يحرس المسار المباشر… ثم:
 *
 *  • **غسيلٌ عبر الموافقات**: إن كانت الوحدة محميةً بموافقةٍ مُلزِمة، لا يمرّ
 *    الطلب بـfill أصلاً — تُلتقط **حمولةٌ خام** من الطلب وتُخزَّن، ثم يُعيد
 *    المعتمِد تشغيلها بصلاحياته هو. فمن لا يرى الراتب يضبط الراتب بوكيل.
 *  • **استعادة نسخة**: تُعيد كتابة كل عمودٍ في اللقطة، ومنه الممنوع.
 *  • **سحب الكانبان** و**الحالة الجماعية**: يكتبان عمود الحالة بلا فحصٍ ولا
 *    تحقّقٍ من الخيارات المعرَّفة.
 *  • **الفرز `?s=`**: يرتّب القائمة بعمودٍ مخفيّ فيكشف ترتيبه (من أعلى راتباً).
 *  • **الاستيراد**: يكتب أي عمودٍ يسمّيه الملف بلا فحص.
 *  • **سجل التدقيق**: يطبع «قبل ← بعد» لحقولٍ لا يملك القارئ رؤيتها.
 */
class FieldPermissionBypassTest extends TestCase
{
    protected User $clerk;
    protected Employee $emp;

    protected function scene(): void
    {
        $this->seedCore();

        $role = Role::create(['name' => 'كاتب', 'scope' => 'all',
            'flags' => ['audit' => 1],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all(),
            'field_rules' => ['hr' => ['salary' => 'hide', 'iqama' => 'ro']]]);

        $this->clerk = User::create(['name' => 'كاتب', 'email' => 'clerk@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $this->emp = Employee::create(['name' => 'مهندسة', 'status' => 'نشط',
            'salary' => 1500, 'iqama' => 'IQ-777']);
    }

    /** الحارس القائم — يبقى قائماً */
    public function test_the_direct_path_still_blocks_injection(): void
    {
        $this->scene();

        $this->actingAs($this->clerk)->put("/m/hr/{$this->emp->id}",
            ['name' => 'مهندسة', 'status' => 'نشط', 'salary' => 99999, 'iqama' => 'HACKED']);

        $fresh = Employee::find($this->emp->id);
        $this->assertEquals(1500, $fresh->salary);
        $this->assertSame('IQ-777', $fresh->iqama);
    }

    /**
     * الغسيل عبر الموافقات: الحمولة تُلتقط خاماً وتُعاد بصلاحيات المعتمِد.
     * الحارس الصحيح: **تُنقّى الحمولة عند الالتقاط** بصلاحيات الطالب — فما لا
     * يملك كتابته لا يدخل الطابور أصلاً، ولا يُوقَّع عليه أحدٌ بحسن نية.
     */
    public function test_the_approval_queue_does_not_launder_a_forbidden_field(): void
    {
        $this->scene();
        $this->hubSetting('approval.rules', 'hr:e');

        $this->actingAs($this->clerk)->put("/m/hr/{$this->emp->id}",
            ['name' => 'مهندسة', 'status' => 'نشط', 'salary' => 99999, 'iqama' => 'HACKED']);

        $ap = DB::table('approvals')->where('mod', 'hr')->orderByDesc('created_at')->first();
        $this->assertNotNull($ap, 'لم يُصفَّ طلب موافقة أصلاً');

        $payload = json_decode((string) $ap->payload, true) ?: [];
        $this->assertArrayNotHasKey('salary', $payload,
            'حقلٌ مخفيّ دخل حمولة الموافقة — يكفي أن يوقّع مالكٌ بحسن نية ليُكتب');
        $this->assertArrayNotHasKey('iqama', $payload, 'حقل «قراءة فقط» دخل الحمولة');

        // وحتى لو اعتُمد الطلب: القيم الأصلية باقية
        $this->actingAs($this->owner)->post("/approvals/{$ap->id}/approve", ['_reason' => 'موافق']);

        $fresh = Employee::find($this->emp->id);
        $this->assertEquals(1500, $fresh->salary, 'الراتب تغيّر عبر وكيلٍ معتمِد');
        $this->assertSame('IQ-777', $fresh->iqama);
    }

    /** استعادة نسخةٍ قديمة تُعيد كل عمود — ومنه ما لا يملك المستخدم كتابته */
    public function test_restoring_a_version_cannot_rewrite_a_forbidden_field(): void
    {
        $this->scene();

        // نسخةٌ قديمة رواتبها مختلفة (كتبها المالك)
        $this->actingAs($this->owner);
        $this->emp->update(['salary' => 8000]);
        $this->emp->update(['salary' => 1500]);

        $v = DB::table('record_versions')->where('record_id', $this->emp->id)
            ->orderBy('version')->first();
        $this->assertNotNull($v, 'لا نسخ محفوظة');

        $this->actingAs($this->clerk)->post("/m/hr/{$this->emp->id}/restore/{$v->version}");

        $this->assertEquals(1500, Employee::find($this->emp->id)->salary,
            'استعادة نسخةٍ كتبت راتباً لا يملك المستخدم رؤيته فضلاً عن كتابته');
    }

    public function test_kanban_drag_respects_field_permissions_and_options(): void
    {
        $this->scene();
        $role = $this->clerk->role;
        $role->update(['field_rules' => ['hr' => ['salary' => 'hide', 'status' => 'ro']]]);

        $this->actingAs($this->clerk)->post("/m/hr/{$this->emp->id}/status", ['status' => 'موقوف']);
        $this->assertSame('نشط', Employee::find($this->emp->id)->status,
            'عمود الحالة «قراءة فقط» وكُتب بالسحب والإفلات');

        // ولا تُكتب حالةٌ خارج خيارات الوحدة — انحرافُ بياناتٍ بضغطة
        $role->update(['field_rules' => []]);
        $this->actingAs($this->clerk)->post("/m/hr/{$this->emp->id}/status", ['status' => 'حالةٌ مخترَعة']);
        $this->assertNotSame('حالةٌ مخترَعة', Employee::find($this->emp->id)->status);
    }

    public function test_bulk_status_respects_field_permissions(): void
    {
        $this->scene();
        $this->clerk->role->update(['field_rules' => ['hr' => ['status' => 'ro']]]);

        $this->actingAs($this->clerk)->post('/m/hr/bulk',
            ['do' => 'status', 'value' => 'موقوف', 'ids' => [$this->emp->id]]);

        $this->assertSame('نشط', Employee::find($this->emp->id)->status,
            'الإجراء الجماعي كتب عموداً ممنوعاً');
    }

    /** الترتيب بعمودٍ مخفيّ يكشف ترتيبه: من أعلى راتباً بلا رؤية رقم */
    public function test_sorting_by_a_hidden_column_is_refused(): void
    {
        $this->scene();
        /*
         * **طابعان مختلفان صراحةً**: الترتيبُ الافتراضي `created_at desc`، وكان
         * الصفّان يُكتبان في الثانية نفسها فيتوقّف الترتيبُ على فضّ التعادل —
         * وهو يختلف بين SQLite وMySQL. فالاختبارُ كان يمرّ على محرّكٍ ويسقط على
         * الآخر لسببٍ لا علاقة له بما يفحصه. الآن الأحدثُ أدنى راتباً: الافتراضيُّ
         * يضعه أولاً، وترتيبُ الراتب تنازلياً يضع الآخر — فيفترقان حتماً.
         */
        Employee::create(['name' => 'أعلى راتباً', 'status' => 'نشط', 'salary' => 90000,
            'created_at' => now()->subDay()]);
        Employee::create(['name' => 'أدنى راتباً', 'status' => 'نشط', 'salary' => 100,
            'created_at' => now()]);

        $html = $this->actingAs($this->clerk)->get('/m/hr?s=salary&d=desc')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'أعلى راتباً'), strpos($html, 'أدنى راتباً'),
            'القائمة رُتّبت بعمودٍ مخفيّ — الترتيب نفسه إفشاء');
    }

    /** سجل التدقيق يعرض «قبل ← بعد»، وفيه قيمُ حقولٍ لا يراها القارئ */
    public function test_the_audit_diff_hides_fields_the_reader_may_not_see(): void
    {
        $this->scene();

        $this->actingAs($this->owner);
        // **قيمةٌ بكسرٍ عشري** لا بعددٍ صحيح: الصفحة تطبع معرّفاتٍ UUID، وسلسلةُ
        // أرقامٍ خالصة كـ«7777» تظهر داخل سُداسيّ أحدها بنحو ١٪ من التشغيلات
        // فيسقط الاختبار زوراً — قرعةٌ من الصنف الذي يحاربه المستودع. النقطة
        // لا ترد في UUID أصلاً، فالإبرةُ تخصّ الراتب وحده على المحرّكين معاً.
        $this->emp->update(['salary' => 7777.75]);

        // **ومُرشَّحٌ على وحدة hr**: الصفحة العامة تعرض قيوداً من كل الوحدات، وقيمةٌ
        // قد تظهر في قيد وحدةٍ **يراها الكاتب بحقّ** (فاتورة/أصل). الترشيح يحصر
        // الفحص بقيود hr حيث يعيش الحقّ الأمنيّ فعلاً.
        $html = $this->actingAs($this->clerk)->get('/admin/audit?module=hr')->assertOk()->getContent();
        foreach (['7777.75', '7777.750'] as $needle) {   // SQLite تطبع الأولى وMySQL الثانية
            $this->assertStringNotContainsString($needle, $html,
                'سجل التدقيق طبع راتباً محجوباً عن قارئه — نافذةٌ خلفية على كل حقلٍ مخفيّ');
        }
        // وليس أجوف: المسك طُبِّق فعلاً على قيد تغيير الراتب لا أنّ القيمة غابت صدفةً
        $this->assertStringContainsString('••• محجوب', $html,
            'لم يظهر أثرُ المسك — القيمة غابت لسببٍ آخر لا للمسك، فالاختبار لا يحرس شيئاً');
    }

    /**
     * **امتدادُ Work OS (WP-B.3 · §13/§98):** عضوُ عميلٍ بدور Finance يرى فواتيرَه،
     * لكنّ التكلفةَ/الهامشَ الداخليَّين لا يبلغانه — لا عبر لوحته ولا عبر رقمٍ مسرَّب.
     * الحقلُ الذي لا يراه القارئُ لا يُحمَّل أصلاً (نظير field-mode للداخليّ، وaudience
     * للعميل): امتدادٌ لنفس العقد — «حقلٌ لا يملك القارئُ رؤيتَه لا يُطبَع».
     */
    public function test_a_client_finance_member_never_receives_internal_cost_or_margin(): void
    {
        $this->seedCore();

        $client = \App\Models\Client::create(['name' => 'عميلُ الماليّة', 'stage' => 'عميل حالي']);
        $u = User::create(['name' => 'ماليّةُ العميل', 'email' => 'fin.member@client.test',
            'password' => 'Secret!2026x', 'status' => 'نشط', 'account_type' => 'client',
            'password_changed_at' => now()]);
        \App\Models\ClientMembership::create(['client_id' => $client->id, 'user_id' => $u->id,
            'role' => 'finance', 'status' => 'active', 'activated_at' => now()]);

        \App\Models\FinDocument::create(['doc_no' => 'INV-FM1', 'kind' => 'فاتورة مبيعات',
            'client_id' => $client->id, 'total' => 6600, 'state' => 'مرسلة']);
        \App\Models\Project::create(['name' => 'مشروعُ الماليّة', 'client_id' => $client->id,
            'status' => 'نشط', 'cost' => 515151, 'budget' => 626262]);

        foreach ([route('portal.home'), route('portal.invoices'), route('portal.projects')] as $url) {
            $res = $this->actingAs($u)->get($url)->assertOk();
            $res->assertDontSee('515151');   // تكلفةٌ داخليّة
            $res->assertDontSee('626262');   // ميزانيّةٌ/هامشٌ داخليّ
        }

        // وفاتورتُه (رقمُ عميلٍ لا داخليّ) تبلغه بحقّ — لا حجبٌ شاملٌ يزوّر العزل
        $this->actingAs($u)->get(route('portal.invoices'))->assertOk()->assertSee('INV-FM1');
    }

    /**
     * حارسا الحساب — انتهاء الصلاحية وقائمة العناوين — كانا يُفحصان عند تسجيل
     * الدخول فقط. فحسابُ متعاقدٍ انتهى عقده يحتفظ بوصولٍ كاملٍ عبر مفتاحه،
     * وحسابٌ محصورٌ بشبكة المكتب يعمل من أي مكانٍ في العالم إلى الأبد.
     */
    public function test_the_api_honours_account_expiry_and_ip_allowlist(): void
    {
        $this->seedCore();

        $token = $this->apiToken($this->employee);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/modules')->assertOk();

        User::whereKey($this->employee->id)->update(['expires_at' => now()->subDay()->toDateString()]);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/modules')->assertStatus(403);

        User::whereKey($this->employee->id)->update(['expires_at' => null, 'allowed_ips' => '10.9.9.9']);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/modules')->assertStatus(403);

        User::whereKey($this->employee->id)->update(['allowed_ips' => '127.0.0.1']);
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/modules')->assertOk();
    }

    /**
     * **أوامرُ المحادثة مسارُ كتابةٍ جديدٌ — فلا يغسل صلاحية** (WP-C.3 · §8):
     * عضوٌ يكتب في قناةٍ لكنّه لا يملك «إضافةَ مهام» (tasks:a=0) لا يخلق مهمةً بأمرِ
     * `/task` — كما لا يخلقها من الشاشة. الرفضُ صريحٌ (٤٠٣) ولا نصٌّ يُخزَّن كنجاحٍ زائف.
     * وأمرٌ مجهولٌ لا يمرّ صامتاً (٤٢٢). يمتدّ نمطَ هذا الملف: حارسٌ يُفرض في مسارٍ
     * ويُلتَفّ عليه في آخر — والأمرُ لا يكون الالتفافَ الجديد.
     */
    public function test_a_chat_command_does_not_launder_module_add_permission(): void
    {
        $this->seedCore();

        // قناةٌ يملكها مصرَّحٌ، وعضوٌ يحادثُ فيها بلا صلاحيةِ إضافةِ مهام
        $ownerRole = Role::create(['name' => 'مالكُ قناة ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]]]);
        $owner = User::create(['name' => 'صاحبُ القناة', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $ownerRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $poorRole = Role::create(['name' => 'محدود ' . Str::random(4), 'scope' => 'all',
            'flags' => [], 'matrix' => ['tasks' => ['v' => 1, 'a' => 0]]]);
        $poor = User::create(['name' => 'عضوٌ محدود', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $poorRole->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناةٌ للحدود',
            'audience' => 'internal', 'visibility' => 'private', 'created_by' => $owner->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $owner->id,
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $poor->id,
            'role' => 'member', 'source' => 'explicit', 'last_read_at' => now()]);

        // العضوُ المحدودُ يحادثُ بحقّ (رسالةٌ عاديّة تمرّ)
        $this->actingAs($poor)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'مرحباً بالفريق',
        ])->assertRedirect();

        // لكنّ /task لا يغسل صلاحيةً لا يملكها — ٤٠٣ ولا مهمة ولا نصٌّ مخزَّن
        $before = Task::count();
        $this->actingAs($poor)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/task مهمةٌ عبر بابٍ خلفيّ',
        ])->assertForbidden();
        $this->assertSame($before, Task::count(), 'أمرُ محادثةٍ خلق مهمةً بلا صلاحية');
        $this->assertSame(0, Comment::where('body', '/task مهمةٌ عبر بابٍ خلفيّ')->count(),
            'أمرٌ مرفوضٌ خُزّن كتعليق — نجاحٌ زائف');

        // وأمرٌ مجهولٌ لا يمرّ صامتاً حتى للمالك المصرَّح
        $this->actingAs($owner)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => '/wipe كلَّ شيء',
        ])->assertStatus(422);
    }

    /**
     * **امتدادُ Work OS (WP-C.2 · §6):** سلطةُ القراءةِ الرقابيّةِ لا تُغسَل كتابةً.
     *
     * القارئُ الرقابيُّ يرى محادثةً ليس عضواً فيها (بحقٍّ، للامتثال) — لكنّ قدرتَه
     * على *القراءة* لا تمنحه *الكتابةَ*: لا يحقن رسالةً في القناة (guardConversation
     * يردّه غيرَ عضوٍ ٤٠٤)، ولا يحذف رسالةَ غيره (٤٠٣). يمتدّ نمطَ هذا الملف: صلاحيةٌ
     * تُفرض في مسارٍ ولا تُلتَفّ عليها في آخر — والرقابةُ لا تكون الالتفافَ الجديد.
     */
    public function test_oversight_read_power_does_not_launder_into_write_access(): void
    {
        $this->seedCore();
        $this->hubSetting('collab.oversight_role', 'مراقبُ الامتثال');

        // قناةٌ يملكها الموظفُ برسالةٍ فيها — والرقيبُ ليس عضواً
        $conv = Conversation::create(['kind' => 'channel', 'title' => 'قناةٌ لا يكتبها الرقيب',
            'audience' => 'internal', 'visibility' => 'private', 'created_by' => $this->employee->id]);
        ConversationMember::create(['conversation_id' => $conv->id, 'user_id' => $this->employee->id,
            'role' => 'owner', 'source' => 'explicit', 'last_read_at' => now()]);
        $msg = Comment::create(['module' => 'channel', 'record_id' => $conv->id,
            'conversation_id' => $conv->id, 'user_id' => $this->employee->id,
            'body' => 'رسالةٌ لا يمسّها الرقيب', 'read_by' => [$this->employee->id], 'created_at' => now()]);

        $role = Role::create(['name' => 'مراقبُ الامتثال', 'scope' => 'all', 'flags' => [], 'matrix' => []]);
        $officer = User::create(['name' => 'ضابطُ الرقابة', 'email' => Str::random(8) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
        $this->actingAs($officer)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/'])->assertRedirect();
        $this->assertTrue(StepUp::fresh());

        // يقرأ بحقٍّ (رقابةٌ) — القدرةُ على الرؤية ثابتة
        $this->actingAs($officer)->get('/oversight/' . $conv->id . '?reason=' . urlencode('مراجعةُ امتثال'))
            ->assertOk()->assertSee('رسالةٌ لا يمسّها الرقيب');

        // لكنّه لا يحقن رسالةً في قناةٍ ليس عضواً فيها — الرؤيةُ لا تصير كتابةً
        $this->actingAs($officer)->post('/comments', [
            'module' => 'channel', 'record_id' => $conv->id, 'conversation_id' => $conv->id,
            'body' => 'حقنٌ من الرقيب',
        ])->assertNotFound();
        $this->assertSame(0, Comment::where('body', 'حقنٌ من الرقيب')->count(),
            'الرقيبُ حقن رسالةً في قناةٍ يقرؤها فقط — سلطةُ قراءةٍ غُسلت كتابةً');

        // ولا يحذف رسالةَ غيره — القراءةُ فقط (لا تحرير/حذف)
        $this->actingAs($officer)->delete('/comments/' . $msg->id)->assertForbidden();
        $this->assertNotNull(Comment::find($msg->id), 'حذف الرقيبُ رسالةَ غيره');
    }

    /**
     * **امتدادُ Work OS (WP-D.2 · §11):** مركزُ قيادةِ المشروع الجديدُ لا يغسل حجبَ الحقل.
     *
     * البطاقاتُ المسطّحةُ صارت تبويبات (نظرة/تسليم/أساس/غرف/مالية/نشاط)، ومعها وُلد
     * تبويبُ ماليةٍ يقرأ `hub_project_pl` (تكلفةٌ · هامشٌ · ميزانيّة · cost_delta). دورٌ
     * داخليٌّ يُخفي `projects.cost`/`budget` **يجب ألا يبلغه هذا التبويبُ ولا أرقامُه** —
     * كما لا يبلغه الحقلُ في الشاشة المباشرة. يمتدّ نمطَ هذا الملف: حارسٌ (field-mode)
     * يُفرض في مسارٍ ولا يُلتَفّ عليه بمسارٍ جديد — والتبويبُ لا يكون الالتفافَ الجديد.
     */
    public function test_the_project_command_centre_tabs_do_not_launder_a_hidden_field(): void
    {
        $this->seedCore();

        // مشروعٌ خارجيٌّ بتكلفةٍ وميزانيّةٍ متمايزتين — كي يُثبَت غيابُهما لا فراغُهما
        $client = \App\Models\Client::create(['name' => 'عميلُ الحجب', 'stage' => 'عميل حالي']);
        $project = \App\Models\Project::create(['name' => 'مشروعُ الحجب', 'client_id' => $client->id,
            'status' => 'نشط', 'manager_id' => $this->owner->id,
            'cost' => 717171, 'budget' => 818181,
            'url' => 'https://prod.laundry-9007.example', 'git' => 'https://git.laundry-9007.example']);

        // المالكُ يرى التكلفةَ في تبويب المالية (حجبُ الدورِ لا حجبٌ شامل)
        $this->actingAs($this->owner)->get('/m/projects/' . $project->id)->assertOk()
            ->assertSee('717,171')->assertSee('data-cctab="finance"', false);

        // دورٌ داخليٌّ يرى المشاريعَ لكن التكلفةَ والميزانيةَ محجوبتان عنه
        $role = Role::create(['name' => 'داخليٌّ بلا مالية', 'scope' => 'all', 'flags' => [],
            'matrix' => collect(array_keys(config('hub.modules')))
                ->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'e' => 1]])->all(),
            'field_rules' => ['projects' => ['cost' => 'hide', 'budget' => 'hide']]]);
        $u = User::create(['name' => 'داخليٌّ بلا مالية', 'email' => Str::random(6) . '@int.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($u)->get('/m/projects/' . $project->id)->assertOk();
        // لا تبويبَ ماليةٍ، ولا رقمَ تكلفة/ميزانية بأيِّ صورة (خامٍ أو منسّق) — لا يغسله التبويب
        $res->assertDontSee('data-cctab="finance"', false);
        foreach (['717171', '717,171', '818181', '818,181'] as $n) {
            $res->assertDontSee($n);
        }
    }
}
