<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Inbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة الثامنة (الدفعة ٢) — **العزلُ لا يُفكّ، والقارئُ يمرّ بنطاقه.**
 *
 *  ١) **تحديثُ المستخدم يقبل أيَّ قائمة شركات** (`UserController:189`): `store`
 *     يطبّق تقاطعَ `hub_company_ids()` (v2.319) فلا يُنشئ إداريٌّ معزول حساباً
 *     بلا عزل — لكن `update` أغفله. فإداريٌّ محصورٌ بشركةٍ «ب» يفتح حساباً
 *     ويكتب `companies=[أ]` بحفظ نموذج، فيفكّ العزلَ عن غير نفسه. تصعيدُ امتيازٍ
 *     كامل.
 *  ٢) **الصندوقُ الموحّد يقرأ سبعةَ مصادرَ بلا `hub_scope`** (`Inbox.php`):
 *     موافقة/تذكرة/قرار/اجتماع/التزام/بند امتثال في شركةٍ لا يملك المستخدمُ
 *     رؤيتها تظهر في صندوقه — تسريبُ شركةٍ لأخرى على صفحةٍ يفتحها كلُّ مستخدم،
 *     ورابطٌ يردّ ٤٠٤ عند النقر (فالعرضُ المفرد يُنطّق). العزلُ يسبق الإسناد.
 *  ٣) **معاينةُ التوقيع تقرأ أيَّ سجلٍ في أيِّ وحدة** (`EsignController:236`):
 *     `store` يحرس الربطَ بـ`LINKABLE`+`hub_can`+`hub_scope`، و`preview` يمرّره
 *     خاماً إلى `ContractVars::resolve` — فيتسرّب اسمُ موظفٍ وبريدُه وهاتفُه
 *     عبر الشركات والوحدات في معاينةٍ لا تُخزَّن.
 */
class TenancyLeakRound8Test extends TestCase
{
    /** مستخدمٌ معزولٌ على شركةٍ واحدة يملك رؤيةَ كل الوحدات */
    protected function scopedTo(string $companyId): User
    {
        $modules = array_keys(config('hub.modules'));
        $view = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'إداريٌّ معزول', 'scope' => 'all',
            'flags' => ['users' => 1], 'matrix' => $view]);

        return User::create(['name' => 'معزول', 'email' => Str::random(8) . '@t.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'companies' => [$companyId], 'password_changed_at' => now()]);
    }

    /* ── ١) تحديثُ المستخدم يرث حصرَ منشئه ── */

    public function test_update_intersects_companies_with_the_editors_scope(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $admin = $this->scopedTo($b->id);

        $target = User::create(['name' => 'هدف', 'email' => 'target@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'companies' => [$b->id], 'password_changed_at' => now()]);

        // الإداريُّ المعزول على «ب» يحاول ضمَّ الهدف إلى «أ» (خارج نطاقه)
        $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'name' => 'هدف', 'email' => 'target@t.local',
            'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'companies' => [$a->id],
        ])->assertRedirect();

        $this->assertSame([$b->id], array_values((array) $target->fresh()->companies),
            'تحديثُ المستخدم قبل قائمةَ شركاتٍ خارج نطاق محرّرها — العزلُ يُفكّ '
            . 'عن غير النفس بحفظِ نموذج، تماماً كما كان الإنشاءُ قبل v2.319');
    }

    /** وقائمةٌ فارغةٌ لا تصير null (وصولاً مطلقاً) بل تبقى على نطاق المحرّر */
    public function test_update_with_empty_companies_falls_back_to_editor_scope_not_null(): void
    {
        $this->seedCore();
        $b = Company::create(['name_ar' => 'شركة ب']);
        $admin = $this->scopedTo($b->id);

        $target = User::create(['name' => 'هدف٢', 'email' => 'target2@t.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
            'companies' => [$b->id], 'password_changed_at' => now()]);

        $this->actingAs($admin)->put("/admin/users/{$target->id}", [
            'name' => 'هدف٢', 'email' => 'target2@t.local',
            'role_id' => $this->employee->role_id, 'status' => 'نشط',
        ])->assertRedirect();

        $this->assertSame([$b->id], array_values((array) $target->fresh()->companies),
            'قائمةٌ فارغةٌ من إداريٍّ معزول يجب أن تسقط على نطاقه لا على «بلا قيد»');
    }

    /* ── ٢) الصندوق الموحّد لا يقرأ سجلات شركةٍ أخرى ── */

    public function test_the_inbox_does_not_leak_records_from_another_company(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);   // خارج نطاق المستخدم
        $b = Company::create(['name_ar' => 'شركة ب']);
        $u = $this->scopedTo($b->id);
        $now = now();

        // كلُّ سجلٍ في «أ» ومُسنَدٌ للمستخدم بحالةٍ تُظهره — فلولا العزلُ لظهر
        DB::table('approvals')->insert(['id' => (string) Str::uuid(), 'title' => 'موافقة-أ',
            'type' => 'اعتماد', 'approver_id' => $u->id, 'status' => 'معلّق',
            'company_id' => $a->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tickets')->insert(['id' => (string) Str::uuid(), 'subject' => 'تذكرة-أ',
            'assignee_id' => $u->id, 'status' => 'مفتوحة', 'company_id' => $a->id,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('decisions')->insert(['id' => (string) Str::uuid(), 'title' => 'قرار-أ',
            'exec_id' => $u->id, 'status' => 'قيد التنفيذ', 'company_id' => $a->id,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('meetings')->insert(['id' => (string) Str::uuid(), 'title' => 'اجتماع-أ',
            'parts' => json_encode([$u->id]), 'dt' => $now->copy()->addDay()->toDateTimeString(),
            'company_id' => $a->id, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('contract_obligations')->insert(['id' => (string) Str::uuid(), 'title' => 'التزام-أ',
            'owner_id' => $u->id, 'status' => 'قائم', 'company_id' => $a->id,
            'created_at' => $now, 'updated_at' => $now]);
        DB::table('compliance_items')->insert(['id' => (string) Str::uuid(), 'title' => 'امتثال-أ',
            'owner_id' => $u->id, 'status' => 'قائم', 'due' => $now->copy()->addDays(10)->toDateString(),
            'company_id' => $a->id, 'created_at' => $now, 'updated_at' => $now]);

        $titles = array_column(Inbox::items($u), 'title');
        foreach (['موافقة-أ', 'تذكرة-أ', 'قرار-أ', 'اجتماع-أ', 'التزام-أ', 'امتثال-أ'] as $leaked) {
            $this->assertNotContains($leaked, $titles,
                'الصندوقُ الموحّد سرّب «' . $leaked . '» من شركةٍ خارج نطاق المستخدم — '
                . 'العزلُ يسبق الإسناد، والعرضُ المفرد يُنطّق فالرابطُ يردّ ٤٠٤');
        }
    }

    /* ── ٣) معاينةُ التوقيع لا تقرأ سجلاً خارج النطاق ── */

    public function test_esign_preview_does_not_resolve_an_out_of_scope_record(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'شركة أ']);
        $b = Company::create(['name_ar' => 'شركة ب']);
        $u = $this->scopedTo($b->id);

        // موظفٌ في «أ» ببياناتٍ حسّاسة — خارج نطاق المستخدم المعزول على «ب»
        $emp = Employee::create(['name' => 'سرُّ فلان', 'email' => 'secret@a.local',
            'phone' => '99887766', 'company_id' => $a->id, 'status' => 'على رأس العمل']);

        // أسماءُ المتغيّرات الحقيقية التي يولّدها ContractVars (قوسٌ مفرد، عربية)
        $res = $this->actingAs($u)->post('/esign/preview', [
            'free_body' => 'الطرف الثاني: {اسم_الطرف_الثاني} — البريد: {بريد_الطرف_الثاني}',
            'link_module' => 'hr', 'link_id' => $emp->id,
        ]);
        $res->assertOk();
        $html = $res->getContent();

        $this->assertStringNotContainsString('سرُّ فلان', $html,
            'معاينةُ التوقيع حلّت متغيّراتِ سجلٍّ خارج نطاق المستخدم — تسريبُ PII '
            . 'عابرٌ للشركات في معاينةٍ لا تُخزَّن، بينما store يحرسه');
        $this->assertStringNotContainsString('secret@a.local', $html,
            'تسرّب بريدُ موظفٍ خارج النطاق عبر المعاينة');
    }
}
