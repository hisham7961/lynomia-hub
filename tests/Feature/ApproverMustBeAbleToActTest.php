<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **لا يُسنَد الحسمُ إلى من لا يستطيعه** (جولةُ الاعتمادِ المخصّصة · M-A1).
 *
 * قِيس السطحُ حيّاً لأوّلِ مرّة: فُعِّلت قاعدةُ `tasks:e`، فعدّل فهدٌ الدوسري
 * مهمّةً — فصُفَّ الطلبُ كما ينبغي، وبقيت المهمّةُ بلا تغيير، وقيل له بصدق:
 *
 * > «هذه العملية محمية — أُرسل طلب الموافقة للمعتمدين وسيصلك إشعار بالقرار»
 *
 * **ثمّ لم يصل قرارٌ أبداً.** فالمعتمِدُ المُسنَدُ إليه الطلبُ — سالم المطيري —
 * يفتح `/m/approvals` فيجد **صفراً**، ويفتح الطلبَ بعنوانِه فيُردُّ **٤٠٣**:
 *
 * ```
 * سالم المطيري · رايةُ approve = true · approvals:v = false
 * /m/approvals        → ٠ صفوف
 * /m/approvals/{id}   → ٤٠٣
 * ```
 *
 * والسببُ أنّ `hub_approvers()` ترشّح بـ**الرايةِ وحدَها** ولا تسأل: أيستطيع
 * هذا الشخصُ فتحَ شاشةِ الاعتمادِ أصلاً؟ فيُسمّى معتمِداً من لا يبلغ البابَ،
 * **ويعلَق العملُ إلى الأبد**: العمليّةُ موقوفةٌ، والطالبُ يَنتظر قراراً لا
 * يستطيع أحدٌ اتّخاذَه.
 *
 * > وهو **صنفُ «شرطُ العرض ≠ شرطُ الباب» مقلوباً**: هناك دُعِي إلى بابٍ يردّه،
 * > وهنا **كُلِّف بعملٍ لا يبلغ بابَه**. والثمنُ أفدح: لا ٤٠٣ يُرى ويُفهَم، بل
 * > صمتٌ يبدو انتظاراً.
 */
class ApproverMustBeAbleToActTest extends TestCase
{
    private function flagHolder(string $email, bool $canSeeApprovals): User
    {
        $role = Role::create(['name' => 'دورُ ' . $email, 'scope' => 'all',
            'flags' => ['approve' => 1],
            'matrix' => $canSeeApprovals ? ['approvals' => ['v' => 1, 'e' => 1]] : ['tasks' => ['v' => 1]]]);

        return User::create(['name' => 'معتمِد ' . $email, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);
    }

    public function test_a_flag_holder_who_cannot_open_approvals_is_not_named_approver(): void
    {
        $this->seedCore();

        $blind = $this->flagHolder('blind@test.local', false);   // رايةٌ بلا بابٍ — حالُ سالم
        $able  = $this->flagHolder('able@test.local', true);

        $ids = hub_approvers();

        $this->assertNotContains((string) $blind->id, array_map('strval', $ids),
            'سُمّي معتمِداً من لا يفتح شاشةَ الاعتماد — فيعلَق الطلبُ بلا حاسمٍ ممكن');
        $this->assertContains((string) $able->id, array_map('strval', $ids),
            'حُذف معتمِدٌ قادر — والإصلاحُ لا يضيّق على من يستطيع');
    }

    /** ولا تفرغ القائمةُ أبداً: المالكُ يبلغ كلَّ بابٍ فيبقى الملاذَ الأخير */
    public function test_the_owner_always_remains_so_the_list_is_never_empty(): void
    {
        $this->seedCore();
        $this->flagHolder('blind2@test.local', false);

        $ids = array_map('strval', hub_approvers());

        $this->assertNotEmpty($ids, '**طلبٌ بلا معتمِدٍ واحدٍ عملٌ ميّت** — القائمةُ لا تفرغ');
        $this->assertContains((string) $this->owner->id, $ids, 'المالكُ يبلغ كلَّ باب');
    }
}
