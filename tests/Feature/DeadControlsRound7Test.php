<?php

namespace Tests\Feature;

use App\Models\Approval;
use App\Models\Client;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\InboxDocument;
use App\Models\Policy;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السابعة (الدفعة الأولى من أسطول «الخيارات العقيمة») — **ضابطٌ
 * موجودٌ في الشاشة ولا يضبط شيئاً، ومُرشِّحٌ يقرأ عموداً لا يكتبه أحد.**
 *
 *  ١) `hub_ack_targets` يُرشِّح جمهورَ الإقرار بـ`users.company_id` — **ولا
 *     كاتبَ لهذا العمود في المستودع كلِّه**: عضويةُ الشركات تُخزَّن في
 *     `users.companies` (مصفوفة). فسياسةٌ تُعلَن لشركةٍ **لا تبلغ أحداً**،
 *     والشاشةُ تقول «أُعلنت لِـ٠ شخص» فيُقرأ صفرُها «لا أحد معنيّ» لا «العمودُ
 *     ميت». إعلانُ التزامٍ لا يصل مسؤوليةٌ لم تُنقَل وهي تبدو منقولة.
 *  ٢) صفُّ «المستخدمون» في مصفوفة الأدوار **عقيمٌ تماماً**: `ModuleController`
 *     يردّ ٤٠٤ على `users`، و`UserController` يحرس بعَلَم «إدارة المستخدمين»
 *     لا بالمصفوفة. فمن يُفرغ الخانات الأربع يظنّ أنه سحب إدارةَ المستخدمين
 *     ولم يسحب شيئاً — وأخطرُ مكانٍ لخيارٍ عقيمٍ هو شاشةُ الصلاحيات.
 *  ٣) بطاقة «طلبات بطيئة» في مركز التشغيل تعدّ **بصماتِ** الأخطاء لا وقائعَها،
 *     وجارتُها في السطر نفسِه تجمع `count`. فألفُ بطءٍ على مسارٍ واحد تُعرض «١».
 *  ٤) `ApprovalDecisionController` يحسم الموافقات — كتابةً وحذفاً — بعَلَم
 *     «معتمِد» وحده، **بلا `hub_can` واحدة**: معتمِدٌ لا يملك على الرواتب
 *     صلاحيةَ عرضٍ يعتمد تعديلَ راتب.
 *  ٥) `InboxDocController::classify` يقرأ الوثيقة خامّاً بلا نطاق، ويقبل أيّ
 *     `company_id`، والشاشةُ تبثّ أسماءَ **كلّ** الشركات للمعزول.
 *  ٦) زرّ «تحديد الكل كمقروء» في جرس التنبيهات يُرسل POST بلا رمز CSRF —
 *     يفشل ٤١٩ في كل مرة، والرسالةُ المعروضة «تعذّر تحميل المحتوى» تُضلّل.
 */
class DeadControlsRound7Test extends TestCase
{
    protected function isolated(Company $co, array $flags = []): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'معزول ' . Str::random(4), 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'معزول', 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$co->id], 'password_changed_at' => now()]);
    }

    protected function co(string $n): Company
    {
        return Company::create(['name' => $n, 'name_ar' => $n]);
    }

    /* ── ١) جمهورُ الإقرار يُقرأ من عضويةٍ حقيقية ── */

    public function test_a_company_scoped_policy_actually_reaches_that_companys_people(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف');
        $member = $this->isolated($a);

        $p = Policy::create(['title' => 'سياسةُ شركة ألف', 'company_id' => $a->id,
            'status' => 'سارية']);

        $targets = hub_ack_targets('policies', $p);

        $this->assertContains($member->id, $targets,
            'جمهورُ الإقرار يُرشَّح بعمود `users.company_id` ولا كاتبَ له في '
            . 'المستودع كلِّه — فسياسةٌ تُعلَن لشركةٍ لا تبلغ أحداً، والشاشةُ '
            . 'تقول «أُعلنت لِـ٠» فيُقرأ صفرُها «لا أحد معنيّ» لا «العمودُ ميت»');
    }

    /* ── ٢) لا صفَّ عقيماً في مصفوفة الأدوار ── */

    public function test_the_roles_matrix_shows_no_row_that_guards_nothing(): void
    {
        $groups = \App\Http\Controllers\Web\RoleController::groupedModules();
        $keys = collect($groups)->flatMap(fn ($g) => array_keys($g['items']))->all();

        $this->assertNotContains('users', $keys,
            'صفُّ «المستخدمون» في مصفوفة الأدوار لا يحرس شيئاً: `ModuleController` '
            . 'يردّ ٤٠٤ على `users` و`UserController` يحرس بعَلَمٍ لا بالمصفوفة. '
            . 'فمن يُفرغ خاناته يظنّ أنه سحب إدارةَ المستخدمين ولم يسحب شيئاً');
    }

    /** والرابطُ المعروض يوافق من يملك الدخولَ فعلاً */
    public function test_the_user_management_link_matches_who_can_actually_enter(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/partials/staff_account_card.blade.php'));

        $this->assertStringNotContainsString("hub_can(auth()->user(), 'users', 'v')", $src,
            'الرابطُ يُعرض بخانةٍ لا تحرس البابَ — فيراه من يُردّ عنه ٤٠٣، '
            . 'ويُحجب عمّن يملك العَلَم فعلاً');
        $this->assertStringContainsString("hub_flag(auth()->user(), 'users')", $src);
    }

    /* ── ٣) بطاقةُ البطء تعدّ الوقائع ── */

    public function test_the_slow_requests_card_counts_occurrences_not_fingerprints(): void
    {
        $this->seedCore();

        ErrorEvent::create(['kind' => 'slow', 'message' => 'طلب بطيء: GET /a', 'status' => 'جديد',
            'hash' => hash('sha256', 'a'), 'count' => 40, 'first_seen' => now(), 'last_seen' => now()]);
        ErrorEvent::create(['kind' => 'slow', 'message' => 'طلب بطيء: GET /b', 'status' => 'جديد',
            'hash' => hash('sha256', 'b'), 'count' => 2, 'first_seen' => now(), 'last_seen' => now()]);

        $res = $this->actingAs($this->owner)->get('/admin/ops');
        $res->assertOk();
        $errs = $res->viewData('errs');

        $this->assertSame(42, (int) $errs['slow'],
            'البطاقةُ تعدّ بصماتِ الأخطاء لا وقائعَها — فألفُ بطءٍ على مسارٍ واحد '
            . 'تُعرض «١»، بينما جارتُها في السطر نفسِه تجمع `count`');
    }

    /* ── ٤) حسمُ الموافقة يشترط صلاحيةَ وحدته ── */

    public function test_deciding_an_approval_requires_permission_on_its_module(): void
    {
        $this->seedCore();

        // معتمِدٌ بعَلَم الاعتماد ولا صلاحيةَ له على العملاء إطلاقاً
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $matrix['clients'] = ['v' => 0, 'a' => 0, 'e' => 0, 'd' => 0];
        $role = Role::create(['name' => 'معتمِد بلا عملاء', 'scope' => 'all',
            'flags' => ['approve' => 1], 'matrix' => $matrix]);
        $approver = User::create(['name' => 'معتمِد', 'email' => 'ap@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $c = Client::create(['name' => 'عميل']);
        $ap = Approval::create(['mod' => 'clients', 'record_id' => $c->id, 'op' => 'd',
            'title' => 'حذفُ عميل', 'type' => 'حذف', 'status' => 'معلّق', 'by_id' => $this->owner->id,
            'payload' => [], 'meta' => ['ver' => $c->version]]);

        $this->actingAs($approver)->post("/approvals/{$ap->id}/approve")->assertForbidden();

        $this->assertNull($c->fresh()->deleted_at,
            'حُذف سجلٌ باعتمادِ من لا يملك على وحدته صلاحيةً واحدة — عَلَمُ '
            . '«معتمِد» يفتح كلَّ الوحدات، و`hub_can` غائبةٌ عن المسار كلِّه');
    }

    /* ── ٥) صندوقُ الوثائق: النطاق على التصنيف كما على القراءة ── */

    public function test_classifying_an_out_of_scope_document_is_refused(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف');
        $b = $this->co('شركة باء');
        $u = $this->isolated($b);

        $doc = InboxDocument::create(['path' => 'hub/inboxdocs/x.pdf', 'orig' => 'وثيقة ألف.pdf',
            'size' => 10, 'status' => 'وارد', 'company_id' => $a->id,
            'uploaded_by' => $this->owner->id, 'created_at' => now()]);

        $this->actingAs($u)->post("/inboxdocs/{$doc->id}/classify", ['party' => 'دُسّ'])
            ->assertNotFound();

        $this->assertNull($doc->fresh()->party,
            'المعزولُ صنّف وثيقةَ شركةٍ لا يراها — القراءةُ منطَّقةٌ والتصنيفُ '
            . 'يقرأ الجدولَ خاماً بـ`findOrFail`');
    }

    /** ولا تُبثّ له أسماءُ شركاتٍ خارج نطاقه */
    public function test_the_company_picker_is_scoped_to_the_reader(): void
    {
        $this->seedCore();
        $a = $this->co('شركة ألف السرّية');
        $b = $this->co('شركة باء');
        $u = $this->isolated($b);

        $res = $this->actingAs($u)->get('/inboxdocs');
        $res->assertOk();
        $res->assertDontSee('شركة ألف السرّية', false);
    }

    /* ── ٦) زرُّ «تحديد الكل كمقروء» في الجرس يعمل ── */

    public function test_the_bell_mark_all_button_carries_a_csrf_token(): void
    {
        $src = (string) file_get_contents(base_path('resources/views/partials/notifications_mini.blade.php'));

        $this->assertMatchesRegularExpression('/hx-headers|@csrf/', $src,
            'زرّ «تحديد الكل كمقروء» يرسل POST بلا رمز CSRF ولا ترويسة — يُردّ '
            . '٤١٩ في كل مرة، ويعرض app.js «تعذّر تحميل المحتوى» فيبدو عطلَ شبكة');
    }

    /** ويعمل حيّاً لا نصّاً: الطلبُ يمرّ ويُصفّر غيرَ المقروء */
    public function test_marking_all_read_actually_clears_the_badge(): void
    {
        $this->seedCore();
        \App\Models\HubNotification::create(['user_id' => $this->owner->id, 'kind' => 'error',
            'text' => 'إشعار', 'read' => false, 'created_at' => now()]);

        $token = csrf_token();
        $res = $this->actingAs($this->owner)
            ->withHeaders(['HX-Request' => 'true', 'X-CSRF-TOKEN' => $token])
            ->post('/notifications/read-all');
        $res->assertOk();

        $this->assertSame(0, \App\Models\HubNotification::where('user_id', $this->owner->id)
            ->where('read', false)->count());
    }
}
