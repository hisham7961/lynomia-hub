<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **المعزولُ على شركةٍ يبقى يرى الناس.**
 *
 * `users.company_id` عمودٌ **متروك**: سبق `users.companies` (أضافته هجرةُ
 * `add_companies_to_users` **بعدَه**)، ولا سطرَ في المنتجِ يكتبه — لا حقلَ له في
 * سجلِّ الوحدة، ولا `UserController` يمسّه. فقيمتُه `NULL` في كلِّ صفٍّ عمليّاً.
 *
 * ومع ذلك يلتقطه `hub_company_col` عبر كشفِ المخطَّط، فيصير عزلُ الشركةِ
 * `whereIn('company_id', [...])` على عمودٍ كلُّه `NULL` — **فلا يطابق أحداً**.
 * والنتيجةُ أنّ المعزولَ على شركةٍ يُصبح أعمى عن البشر كافّةً:
 *   · «الموافقُ المطلوب» في الاعتمادات حقلٌ **مطلوب** وقائمتُه فارغة ⇒ لا يُنشأ اعتمادٌ أبداً.
 *   · ٧٢ حقلَ `ref→users` في المنتج كلُّها فارغةٌ له.
 *   · ودليلُ الفريقِ يقول له `full` بينما النطاقُ يُعيد صفراً — تناقضٌ داخليّ.
 *
 * (قيس حيّاً في اليوم ٧: راشدٌ يرى ١ من ٥ زملاءَ في `/team`، و٠ من ٥ في
 *  `/staff`، وقائمةُ الموافقين بلا خيارٍ واحد.)
 *
 * والعلاجُ **لا يُلغي العزل**: صفٌّ بلا شركةٍ لا يخصّ شركةً أخرى، فلا يُحجب به —
 * أمّا صفٌّ يحمل شركةً مغايرةً فيبقى محجوباً. والدعامتان أدناه تُثبتان الأمرين.
 */
class IsolatedUserCanStillSeePeopleTest extends TestCase
{
    public function test_company_isolated_account_still_sees_colleagues_and_can_pick_an_approver(): void
    {
        $this->seedCore();

        $mine   = Company::create(['name_ar' => 'شركتُه']);
        $theirs = Company::create(['name_ar' => 'شركةٌ أخرى']);

        $role = Role::create(['name' => 'معزولٌ على شركة', 'scope' => 'all',
            'flags' => [], 'matrix' => ['approvals' => ['v' => 1, 'a' => 1]]]);

        $me = User::create(['name' => 'المعزول', 'email' => 'iso@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now(),
            'companies' => [$mine->id]]);

        // زميلٌ عاديّ — كحالِ كلِّ حسابٍ في المنتج: بلا company_id
        $peer = User::create(['name' => 'زميلٌ بلا شركة', 'email' => 'peer@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);

        // وحسابٌ مختومٌ صراحةً بشركةٍ أخرى — هذا وحدَه يُحجب
        $alien = User::create(['name' => 'حسابُ شركةٍ أخرى', 'email' => 'alien@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);
        DB::table('users')->where('id', $alien->id)->update(['company_id' => $theirs->id]);

        $this->actingAs($me);

        $opts = hub_ref_options_scoped('users', null);

        $this->assertArrayHasKey((string) $peer->id, $opts,
            'الزميلُ بلا شركةٍ يظهر في منتقي الأشخاص — وإلّا استحال إنشاءُ اعتمادٍ أصلاً');
        $this->assertArrayNotHasKey((string) $alien->id, $opts,
            'وحسابُ الشركةِ الأخرى يبقى محجوباً — العزلُ قائمٌ على ما يحمل شركةً فعلاً');

        $seen = hub_scope(DB::table('users')->whereNull('deleted_at'), 'users')->pluck('id')->all();
        $this->assertContains($peer->id, $seen, 'ويراه في النطاقِ كما في المنتقي — سطحٌ واحدٌ لا سطحان');
        $this->assertNotContains($alien->id, $seen, 'ويبقى الأجنبيُّ محجوباً في النطاقِ أيضاً');
    }
}
