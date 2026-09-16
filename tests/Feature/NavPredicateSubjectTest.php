<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/**
 * **صاحبُ الشرطِ واحدٌ لا اثنان** — `hub_top_links($user)` تُسأل عن **`$user`**،
 * فكلُّ حدٍّ في شرطِ الرابطِ يقيس `$user` نفسَه.
 *
 * كان بندا «النقاط الطرفية» و«لوحة المشرف الميدانيّ» يخلطان صاحبَين: الحدُّ الأوّل
 * يقرأ `$user` والحدُّ الثاني `hub_monitor_group(...)` بلا وسيط — فيقع على
 * `auth()->user()`. وما دام السائلُ هو المستخدَمَ نفسَه استوى الأمران وسكت العيب،
 * فإذا سُئلت عن **غيرِه** — وهذا عقدُها المعلن، ويمرّره `Workspaces::for`
 * و`PrefController` و`InformationArchitecture::centerVisible` صراحةً — أجابت عن
 * رايات الجالسِ في الجلسة لا عن رايات المسؤولِ عنه.
 *
 * العيبُ كامنٌ لا ظاهر: لا نداءَ اليومَ يمرّر غيرَ صاحبِ الجلسة. وهذا سببُ تثبيتِه
 * باختبار — الكامنُ يصير ظاهراً يومَ تُضاف شاشةُ «كيف يرى فلانٌ النظام؟»، ولا أحدَ
 * يومَها يذكر أنّ الخلطَ ها هنا.
 */
class NavPredicateSubjectTest extends TestCase
{
    private function user(string $email, array $matrix = [], array $flags = []): User
    {
        $role = Role::create(['name' => 'دورٌ ' . $email, 'scope' => 'all',
            'flags' => $flags, 'matrix' => $matrix]);

        return User::create(['name' => 'مستخدم ' . $email, 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'internal', 'password_changed_at' => now()]);
    }

    public function test_top_links_answer_about_the_passed_user_not_the_logged_in_one(): void
    {
        $this->seedCore();

        // المسؤولُ الجالسُ في الجلسة: يحمل الرايتين
        $viewer = $this->user('viewer@test.local', ['hr' => ['v' => 1]],
            ['secOps' => 1, 'opsAnalytics' => 1]);

        // المسؤولُ عنه: يملك hr:v (كي يبلغ الحدَّ الثاني في شرطِ المشرف الميدانيّ)
        // ولا يحمل رايةً واحدة — فلا «النقاط الطرفية» له ولا «لوحة المشرف الميدانيّ»
        $subject = $this->user('subject@test.local', ['hr' => ['v' => 1]], []);

        $this->actingAs($viewer);

        $keys = collect(hub_top_links($subject))->pluck('key')->all();

        $this->assertNotContains('endpointsc', $keys,
            'من لا يحمل secOps لا يُعرض له مركزُ النقاط الطرفية — ولو كان السائلُ يحملها');
        $this->assertNotContains('fieldsup', $keys,
            'من لا يحمل opsAnalytics لا تُعرض له لوحةُ المشرف الميدانيّ — ولو حملها السائل');

        // وليس الاختبارُ فارغاً: الحاملُ نفسُه يراهما، فالنفيُ أعلاه نفيُ شرطٍ لا نفيُ وجود
        $ownKeys = collect(hub_top_links($viewer))->pluck('key')->all();
        $this->assertContains('endpointsc', $ownKeys, 'حاملُ secOps يرى مركزَ النقاط الطرفية');
        $this->assertContains('fieldsup', $ownKeys, 'حاملُ opsAnalytics مع hr:v يرى لوحةَ المشرف');
    }
}
