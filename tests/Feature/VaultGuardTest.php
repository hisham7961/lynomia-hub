<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\VaultSecret;
use Tests\TestCase;

/**
 * جولة تدقيق ٦ — حارس الخزنة:
 *  1) السر لا يُطبع في مصدر صفحة السجل إطلاقاً (كان يُزرع في &lt;span hidden&gt;).
 *  2) «المستخدمون المخولون» (allowed_ids) تُفرض فعلاً: قائمة غير فارغة تحصر
 *     الكشف بأهلها والمالك محصّن — كانت حقلاً معروضاً بلا أي تطبيق.
 *  3) القائمة تسري على الـ API أيضاً.
 */
class VaultGuardTest extends TestCase
{
    /** مستخدم غير مالك يحمل علم الأسرار — يكشف ما لم تحصره قائمة */
    protected function secretsUser(string $email = 'sec@test.local'): User
    {
        $modules = array_keys(config('hub.modules'));
        $matrix = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 0]])->all();
        $role = Role::create(['name' => 'أمين أسرار ' . $email, 'scope' => 'all',
            'flags' => ['secrets' => 1], 'matrix' => $matrix]);

        return User::create(['name' => 'أمين', 'email' => $email,
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    public function test_secret_value_never_printed_in_page_source(): void
    {
        $this->seedCore();
        $v = VaultSecret::create(['title' => 'سر المصدر', 'type' => 'خادم', 'secret_cipher' => 'PlainInDom777']);

        // حتى المالك (المخوّل بالكشف) لا يجد القيمة في مصدر الصفحة — تُجلب عند الطلب فقط
        $this->actingAs($this->owner)->get('/m/vault/' . $v->id)
            ->assertOk()->assertDontSee('PlainInDom777');
    }

    public function test_allowed_ids_restricts_reveal_to_listed_users(): void
    {
        $this->seedCore();
        $keeper = $this->secretsUser('keeper@test.local');
        $outsider = $this->secretsUser('outsider@test.local');
        $v = VaultSecret::create(['title' => 'سر محصور', 'type' => 'خادم',
            'secret_cipher' => 'OnlyKeeper999', 'allowed_ids' => [$keeper->id]]);

        // المدرج في القائمة يكشف
        $this->actingAs($keeper)->post('/m/vault/' . $v->id . '/secret/secret')
            ->assertOk()->assertJson(['v' => 'OnlyKeeper999']);

        // حامل علم الأسرار غير المدرج يُرفض — القائمة تعد فتُنفَّذ
        $this->actingAs($outsider)->post('/m/vault/' . $v->id . '/secret/secret')->assertForbidden();

        // المالك محصّن دائماً
        $this->actingAs($this->owner)->post('/m/vault/' . $v->id . '/secret/secret')
            ->assertOk()->assertJson(['v' => 'OnlyKeeper999']);

        // قائمة فارغة = لا حصر: أي حامل علم يكشف
        $open = VaultSecret::create(['title' => 'سر مفتوح', 'type' => 'خادم', 'secret_cipher' => 'OpenSecret111']);
        $this->actingAs($outsider)->post('/m/vault/' . $open->id . '/secret/secret')
            ->assertOk()->assertJson(['v' => 'OpenSecret111']);
    }

    public function test_allowed_ids_applies_to_api_too(): void
    {
        $this->seedCore();
        $keeper = $this->secretsUser('kapi@test.local');
        $outsider = $this->secretsUser('oapi@test.local');
        $v = VaultSecret::create(['title' => 'سر API', 'type' => 'خادم',
            'secret_cipher' => 'ApiSecret555', 'allowed_ids' => [$keeper->id]]);

        $tk = $this->apiToken($keeper);
        $to = $this->apiToken($outsider);

        // المدرج يستلم السر، وغير المدرج يستلم السجل بلا حقل السر أصلاً
        $this->getJson('/api/v1/vault/' . $v->id, ['Authorization' => "Bearer $tk"])
            ->assertOk()->assertJsonPath('data.secret_cipher', 'ApiSecret555');
        $this->getJson('/api/v1/vault/' . $v->id, ['Authorization' => "Bearer $to"])
            ->assertOk()->assertJsonMissingPath('data.secret_cipher');
    }
}
