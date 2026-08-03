<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        $this->emp->update(['salary' => 7777]);

        // **مُرشَّحٌ على وحدة hr**: الصفحة العامة تعرض قيوداً من كل الوحدات، وقيمةٌ
        // كـ7777 قد تظهر في قيد وحدةٍ **يراها الكاتب بحقّ** (فاتورة/أصل) فيسقط
        // الاختبار زوراً بلا علاقةٍ بما يفحصه (تلوّثٌ عابرٌ بين الاختبارات، صنف
        // «القرعة» الذي يحاربه المستودع). الترشيح يحصر الفحص بقيود hr حيث يعيش
        // الحقّ الأمنيّ فعلاً — فالنتيجة حتميّةٌ على المحرّكين.
        $html = $this->actingAs($this->clerk)->get('/admin/audit?module=hr')->assertOk()->getContent();
        $this->assertStringNotContainsString('7777', $html,
            'سجل التدقيق طبع راتباً محجوباً عن قارئه — نافذةٌ خلفية على كل حقلٍ مخفيّ');
        // وليس أجوف: المسك طُبِّق فعلاً على قيد تغيير الراتب لا أنّ القيمة غابت صدفةً
        $this->assertStringContainsString('••• محجوب', $html,
            'لم يظهر أثرُ المسك — القيمة غابت لسببٍ آخر لا للمسك، فالاختبار لا يحرس شيئاً');
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
}
