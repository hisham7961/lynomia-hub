<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-5.4) صفحةُ تفصيل قيد التدقيق `admin/audit/{id}` (spec §1.3 · §45 · §42.10).
 *
 * القيدُ الواحد يُقرأ كاملاً: من فعل، وماذا فعل (الفرقُ عبر `Audit::diff` ثم
 * `Redactor`)، ومتى وأين، وبأيّ طلب، وما دلالتُه الأمنية، وهل بصمتُه سليمة —
 * **تحقّقاً موضعيّاً** على الصفّ وحده لا فحصَ سلسلةٍ كاملاً في طلب.
 *
 * والحارس بترتيبٍ مقصود: راية `audit` أولاً (403 موحّدةً لمن لا يملكها)، ثم
 * `Audit::scopedQuery` فالخارجُ عن النطاق **404 لا 403** — فالتمييزُ بين
 * «موجودٌ ممنوع» و«غير موجود» يفشي وقوعَ الفعل نفسِه لمن لا يراه.
 */
class AuditDetailTest extends TestCase
{
    /** مدقّقٌ محدود يحمل رايةَ التدقيق — تجهيزةُ AuditScopeLeakTest نفسُها */
    protected function auditor(array $matrix, array $clients = [], array $companies = [], array $fieldRules = []): User
    {
        $role = Role::create(['name' => 'مدقّق محدود ' . Str::random(5), 'scope' => 'all',
            'flags' => ['audit' => 1], 'matrix' => $matrix,
            'field_rules' => $fieldRules ?: null]);

        return User::create(['name' => 'مدقّق مقيَّد', 'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'clients' => $clients ?: null, 'companies' => $companies ?: null,
            'password_changed_at' => now()]);
    }

    // ── الحارس: الراية ثم النطاق ──

    /** بلا راية `audit`: 403 — سواءٌ وُجد القيدُ أو لم يوجد، فالرفض لا يفشي شيئاً */
    public function test_403_without_the_audit_flag(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = hub_audit('تعديل', 'tasks', null, 'قيد موجود فعلاً');

        $this->actingAs($this->employee)->get('/admin/audit/' . $a->id)->assertForbidden();
        $this->actingAs($this->employee)->get('/admin/audit/999999')->assertForbidden();
    }

    /** المعزولُ بشركةٍ يفتح قيدَ شركةٍ أخرى: **404 لا 403** — والـ403 كان يقول «القيدُ موجود» */
    public function test_404_outside_company_scope_never_a_revealing_403(): void
    {
        $this->seedCore();
        $myCo = (string) Str::uuid();
        $otherCo = (string) Str::uuid();

        $this->actingAs($this->owner);
        $mine = hub_audit('تعديل', 'tasks', null, 'قيد شركتي', ['company_id' => $myCo]);
        $foreign = hub_audit('تعديل', 'tasks', null, 'قيد شركة أخرى', ['company_id' => $otherCo]);

        $u = $this->auditor(['tasks' => ['v' => 1]], [], [$myCo]);
        $this->actingAs($u)->get('/admin/audit/' . $mine->id)->assertOk()->assertSee('قيد شركتي');
        $this->actingAs($u)->get('/admin/audit/' . $foreign->id)->assertNotFound();
        // ومعرّفٌ لا وجود له أصلاً: الجوابُ نفسُه — فلا فرقَ يُقاس بين المحجوب والمعدوم
        $this->actingAs($u)->get('/admin/audit/424242')->assertNotFound();
    }

    /** والمحصورُ بعميلٍ لا يفتح قيدَ سجلِّ عميلٍ آخر — 404 كذلك */
    public function test_404_outside_client_scope(): void
    {
        $this->seedCore();
        $mine = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('clients')->insert([
            ['id' => $mine,  'name' => 'عميلي المسموح'],
            ['id' => $other, 'name' => 'العميل الآخر'],
        ]);
        $cMine = (string) Str::uuid();
        $cOther = (string) Str::uuid();
        DB::table('contracts')->insert([
            ['id' => $cMine,  'title' => 'عقد أ', 'type' => 'خدمات', 'client_id' => $mine],
            ['id' => $cOther, 'title' => 'عقد ب', 'type' => 'خدمات', 'client_id' => $other],
        ]);

        $this->actingAs($this->owner);
        $ok = hub_audit('تعديل', 'contracts', $cMine, 'عقد عميلي المسموح');
        $hidden = hub_audit('تعديل', 'contracts', $cOther, 'عقد العميل المحجوب');

        $u = $this->auditor(['contracts' => ['v' => 1], 'clients' => ['v' => 1]], [$mine]);
        $this->actingAs($u)->get('/admin/audit/' . $ok->id)->assertOk()->assertSee('عقد عميلي المسموح');
        $this->actingAs($u)->get('/admin/audit/' . $hidden->id)->assertNotFound();
    }

    // ── الفرق: مقنَّعٌ حسب قيود الحقل، ولا سرَّ على الصفحة ──

    /** حقلٌ محجوبٌ بقيود مستوى الحقل: يُقال «تغيّر» ولا تُطبع قيمتاه — والمالكُ يراهما */
    public function test_diff_is_masked_per_field_rules(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = hub_audit('تعديل', 'tasks', (string) Str::uuid(), 'مهمة حسّاسة', [
            'before' => ['title' => 'العنوان القديم المكتوم'],
            'after'  => ['title' => 'العنوان الجديد المكتوم'],
        ]);

        // المالك: القيمتان مطبوعتان بأسمائهما من سجل الوحدات
        $this->actingAs($this->owner)->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('عنوان المهمة')
            ->assertSee('العنوان القديم المكتوم')
            ->assertSee('العنوان الجديد المكتوم');

        // مدقّقٌ يرى الوحدة لكن الحقلَ محجوبٌ عنه: أثرُ التغيّر باقٍ والقيمتان لا
        $u = $this->auditor(['tasks' => ['v' => 1]], [], [], ['tasks' => ['title' => 'hide']]);
        $this->actingAs($u)->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('محجوب')
            ->assertDontSee('العنوان القديم المكتوم')
            ->assertDontSee('العنوان الجديد المكتوم');
    }

    /**
     * لا سرَّ على الصفحة — تجهيزاتُ SecretsNeverInAuditRound7Test نفسُها:
     * عمودُ سرٍّ في قيدٍ قديم (قبل بصمة المصدر) يُخفى بالقناع، ورمزُ `lyn_`
     * داخل قيمةٍ بريئة يطمسه `Redactor` بعد `Audit::diff`.
     */
    public function test_no_secret_ever_appears_on_the_detail_page(): void
    {
        $this->seedCore();
        $plain = 'PLAINTEXT-' . Str::random(12);
        $token = 'lyn_' . Str::random(44);

        DB::table('audits')->insert(['action' => 'تعديل', 'module' => 'vault',
            'record_id' => (string) Str::uuid(), 'name' => 'سرّ قديم',
            'before' => json_encode(['secret_cipher' => $plain, 'notes' => 'url?token=abc123secret']),
            'after'  => json_encode(['secret_cipher' => 'sha256:aaaa', 'notes' => 'الرمز ' . $token]),
            'created_at' => now()]);
        $id = (int) DB::table('audits')->max('id');

        $html = $this->actingAs($this->owner)->get('/admin/audit/' . $id)->assertOk()->getContent();
        $this->assertStringNotContainsString($plain, $html,
            'قيمةُ عمود السرّ طُبعت على صفحة التفصيل — Audit::MASKED لم يُطبَّق');
        $this->assertStringNotContainsString($token, $html,
            'رمزُ lyn_ داخل قيمةٍ بريئة طُبع كاملاً — الفرقُ لم يمرّ بـRedactor');
        $this->assertStringNotContainsString('abc123secret', $html,
            'قيمةُ token= في سلسلة استعلامٍ طُبعت — نمطُ مفتاح=قيمة لم يُطمس');
    }

    // ── النزاهة: تحقّقٌ موضعيّ يصدُق ──

    /** قيدٌ سليم يُعلَن سليماً، وتعديلٌ مباشرٌ في القاعدة يقلب الإعلان «عبث» */
    public function test_direct_db_tamper_flips_the_local_verification_to_tampered(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        // اسمُ التجهيزة لا يحمل حروفَ «عبث» — assertDontSee أدناه يمسح الصفحةَ كلَّها
        $a = hub_audit('تعديل', 'tasks', null, 'قيد سيُحرَّف لاحقاً');
        $this->assertNotEmpty($a->hash, 'القيد لم يُختم أصلاً — الاختبار يحرس فراغاً');

        $this->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('بصمة مطابقة')->assertDontSee('عبث');

        // عبثٌ مباشر: تحديثٌ خامٌ يتجاوز النموذج فلا يُعاد الختم
        DB::table('audits')->where('id', $a->id)->update(['name' => 'اسمٌ زُوِّر بعد الختم']);

        $this->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('عبث')->assertDontSee('بصمة مطابقة');
    }

    // ── الأمن والعلاقات ──

    /** الدلالةُ الأمنية بكود SecurityEvents، والعنوانُ غير المألوف بقاعدة user_ips (لا حاسبَ ثالثاً) */
    public function test_security_section_shows_code_and_unfamiliar_ip(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = hub_audit('دخول فاشل', null, null, 'محاولة دخول');

        // لا صفَّ في user_ips لهذا العنوان ⇒ غير مألوف (قاعدةُ Risk::session: hits < 3)
        $this->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('AUTH_FAILURE')
            ->assertSee('غير مألوف');

        DB::table('user_ips')->insert(['id' => (string) Str::uuid(),
            'user_id' => $this->owner->id, 'ip' => '127.0.0.1', 'hits' => 9,
            'last_seen_at' => now()]);
        $this->get('/admin/audit/' . $a->id)->assertOk()->assertDontSee('غير مألوف');

        // وقيدٌ غيرُ أمنيّ لا يُلبَس كوداً — تصريحٌ صادق بدل يقينٍ مزيَّف
        $b = hub_audit('تعديل', 'tasks', null, 'قيد عادي');
        $this->get('/admin/audit/' . $b->id)->assertOk()->assertDontSee('AUTH_FAILURE');
    }

    /** العلاقات: خطأٌ وحادثةٌ ومهمةٌ تشارك القيدَ معرّفَ طلبه — وقيدٌ بلا معرّفٍ حالةٌ صادقة */
    public function test_relations_by_request_id(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $rid = 'rid-' . Str::random(12);

        $taskId = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $taskId, 'title' => 'مهمة إصلاح الخطأ المرتبط',
            'status' => 'جديدة', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('error_events')->insert(['id' => (string) Str::uuid(),
            'hash' => hash('sha256', 'x' . $rid), 'kind' => 'php',
            'message' => 'خطأ يشارك معرف الطلب', 'request_id' => $rid,
            'meta' => json_encode(['task_id' => $taskId]),
            'first_seen' => now(), 'last_seen' => now()]);
        DB::table('incidents')->insert(['id' => (string) Str::uuid(),
            'title' => 'حادثة تشارك معرف الطلب', 'request_id' => $rid,
            'created_at' => now(), 'updated_at' => now()]);

        $a = hub_audit('تعديل', 'tasks', null, 'قيد بمعرّف طلب', ['request_id' => $rid]);
        $this->get('/admin/audit/' . $a->id)->assertOk()
            ->assertSee('خطأ يشارك معرف الطلب')
            ->assertSee('حادثة تشارك معرف الطلب')
            ->assertSee('مهمة إصلاح الخطأ المرتبط')
            // ورابطُ أثر الطلب الكامل إلى system.trace
            ->assertSee('/system/trace/' . $rid);

        // قيدٌ بلا معرّف (مهمة مجدولة مثلاً): لا علاقات مُختلَقة — تصريحٌ صادق
        $b = hub_audit('تعديل', 'tasks', null, 'قيد بلا معرّف طلب', ['request_id' => null]);
        $this->get('/admin/audit/' . $b->id)->assertOk()->assertSee('بلا معرّف طلب');
    }

    // ── ميزانية الاستعلام ──

    /** الصفحةُ الدافئة ≤ ٣٠ استعلامَ بياناتٍ — فحوصُ المخطط المخبّأة خارج العدّ */
    public function test_query_budget_thirty_warm(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);
        $a = hub_audit('تعديل', 'tasks', (string) Str::uuid(), 'قيد الميزانية', [
            'before' => ['title' => 'قديم', 'status' => 'جديدة'],
            'after'  => ['title' => 'جديد', 'status' => 'منجزة'],
            'request_id' => 'rid-budget-01',
        ]);

        $this->get('/admin/audit/' . $a->id)->assertOk();      // تسخينُ الخبيئات

        DB::enableQueryLog();
        $this->get('/admin/audit/' . $a->id)->assertOk();
        $data = array_values(array_filter(DB::getQueryLog(), fn ($q) =>
            ! preg_match('/sqlite_master|pragma_table|pragma_index|information_schema/i', $q['query'])));
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(30, count($data),
            'صفحةُ التفصيل الدافئة كلّفت ' . count($data) . ' استعلاماً — فوق ميزانية الثلاثين');
    }
}
