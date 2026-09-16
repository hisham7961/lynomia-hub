<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **اللافتةُ تُعلَّق قبلَ الطَّرقِ لا بعدَه** (محاكاةُ الشهر · M-F6).
 *
 * راشد بن حمد — معزولٌ على شركةٍ **واحدة** — يفتح «اجتماعات ← ＋ إضافة»،
 * يكتب العنوان، يضغط «إضافة»، فيُعاد إلى النموذجِ وقد ضاع ما كتب:
 *
 * ```
 * POST /m/meetings → 302 ← /m/meetings/create
 * ⚑ حسابك معزول على شركات محددة — اختر شركة من شركاتك
 * meetings: 0 قبل · 0 بعد
 * ```
 *
 * وثلاثُ ملاحظاتٍ مرتّبة:
 *
 *   ١. **الحقلُ إلزاميٌّ عند الخادمِ ولا يُعلَن كذلك في الصفحة** — لا نجمةَ ولا
 *      `required`، لأنّ الإلزامَ **مشروطٌ بحالِ المستخدمِ** لا بالسجلّ، والصفحةُ
 *      لا تعرف الشرط. فيضغط زرّاً يعلم الخادمُ سلفاً أنّه سيردّه.
 *   ٢. **وشركتُه واحدة** — والاختيارُ من واحدٍ ليس اختياراً.
 *   ٣. **والرسالةُ حين تصل جيّدة**؛ العيبُ في توقيتِها لا في نصِّها.
 *
 * والحراسةُ لا تُرفع عن الخادمِ بحال: **المتصفّحُ راحةٌ، والخادمُ حكم.**
 */
class IsolatedCompanyKnownBeforeSubmitTest extends TestCase
{
    private function isolated(array $companyIds): User
    {
        $role = Role::create(['name' => 'مدير' . uniqid(), 'scope' => 'all', 'flags' => [],
            'matrix' => ['meetings' => ['v' => 1, 'a' => 1, 'e' => 1]]]);

        return User::create(['name' => 'راشد بن حمد', 'email' => uniqid() . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now(),
            'companies' => $companyIds]);
    }

    /** أ · شركةٌ واحدة: تُنتقى سلفاً — فلا سؤالَ أصلاً */
    public function test_a_single_company_is_chosen_for_him_not_asked_of_him(): void
    {
        $this->seedCore();
        $co = Company::create(['name_ar' => 'شركةُ راشد']);
        $u = $this->isolated([(string) $co->id]);

        $res = $this->actingAs($u)->get(route('m.create', 'meetings'));
        $res->assertOk();

        // القيمةُ مُنتقاةٌ في الصفحةِ نفسِها — لا يُطلَب منه انتقاءٌ من واحد
        $res->assertSee('value="' . $co->id . '" selected', false);
    }

    /** ب · أكثرُ من شركة: الحقلُ يُوسَم مطلوباً **له** فيمنعه المتصفّحُ قبل الإرسال */
    public function test_with_several_companies_the_field_is_marked_required_for_him(): void
    {
        $this->seedCore();
        $a = Company::create(['name_ar' => 'الشركةُ الأولى']);
        $b = Company::create(['name_ar' => 'الشركةُ الثانية']);
        $u = $this->isolated([(string) $a->id, (string) $b->id]);

        $f = collect(config('hub.modules.meetings.fields'))->firstWhere('ref', 'companies');
        $this->assertNotNull($f);

        $this->assertTrue(hub_field_required('meetings', $f, $u),
            'الخادمُ يفرض الشركةَ على المعزولِ والصفحةُ لا تُعلنها — فيضغط زرّاً يُردّ');
    }

    /** وغيرُ المعزولِ لا يُثقَل بإلزامٍ لا يخصُّه */
    public function test_an_unisolated_user_is_not_burdened_with_the_requirement(): void
    {
        $this->seedCore();
        Company::create(['name_ar' => 'شركةٌ عامّة']);

        $f = collect(config('hub.modules.meetings.fields'))->firstWhere('ref', 'companies');
        $this->assertFalse(hub_field_required('meetings', $f, $this->employee),
            'غيرُ المعزولِ لا يفرض عليه الخادمُ شركةً — فلا تُفرض عليه الصفحة');
    }

    /** ج · وحارسُ الخادمِ باقٍ: البابُ لا يُفتح، إنّما تُعلَّق لافتتُه قبل الطَّرق */
    public function test_the_server_guard_still_refuses_a_foreign_company(): void
    {
        $this->seedCore();
        $mine = Company::create(['name_ar' => 'شركتي']);
        $other = Company::create(['name_ar' => 'شركةٌ ليست لي']);
        $u = $this->isolated([(string) $mine->id]);

        $before = \Illuminate\Support\Facades\DB::table('meetings')->count();
        $this->actingAs($u)->post(route('m.store', 'meetings'),
            ['title' => 'اجتماعٌ بشركةٍ ليست له', 'companyId' => (string) $other->id]);

        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('meetings')->count(),
            '**الحراسةُ لا تُرفع عن الخادمِ بحال** — المتصفّحُ راحةٌ والخادمُ حكم');
    }
}
