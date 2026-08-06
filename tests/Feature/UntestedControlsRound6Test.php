<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * الموجة السادسة — الدفعة ٢٢: **ضوابطُ أمنيةٌ بلا اختبارٍ واحد.**
 *
 * لا عيبَ يُدَّعى هنا ابتداءً — هذه ضوابطُ **حيّة** تحمل وزناً أمنيّاً حقيقيّاً
 * ولم يكن يحرسها اختبار: دورةُ حياة مفتاح API (إنشاء/تدوير/إبطال)، وتعطيلُ
 * المصادقة الثنائية، وقفلُ الطوارئ بمواضع إنفاذه الثلاثة. وضابطٌ بلا اختبار
 * **ضابطٌ حتى ينكسر صامتاً**: انحرافُ سطرٍ في إعادة بناءٍ لاحقة يُطفئه ولا
 * تحمرّ الحزمة. فتُكتب حراستُه هنا صراحةً.
 *
 * وفَحَصَ الكتابةُ ما لم يكن مفحوصاً فكشف واحدةً حقيقية: **التدويرُ يُبقي
 * `expires_at` القديم** — مفتاحٌ منتهٍ يُدوَّر فيُسلَّم للمستخدم بقيمةٍ جديدة
 * ورسالةِ نجاح، وهو ميتٌ لحظةَ نسخِه.
 */
class UntestedControlsRound6Test extends TestCase
{
    /* ── ١) دورةُ حياة مفتاح API كاملةً ── */

    public function test_the_api_key_lifecycle_create_rotate_revoke(): void
    {
        $this->seedCore();

        // إنشاء: القيمةُ تُعرض مرةً واحدة، والمخزَّنُ بصمتُها لا هي
        $res = $this->actingAs($this->owner)->post('/profile/token', [
            'tname' => 'مفتاحُ التكامل', 'tdays' => 30]);
        $plain = (string) $res->getSession()->get('newtoken');

        $this->assertMatchesRegularExpression('/^lyn_[A-Za-z0-9]{44}$/', $plain,
            'المفتاحُ لم يُسلَّم للمستخدم — وهو لا يظهر مرةً أخرى');
        $t = ApiToken::where('user_id', $this->owner->id)->first();
        $this->assertSame(hash('sha256', $plain), (string) $t->token_hash,
            'يُخزَّن المفتاحُ نصّاً لا بصمة — تسريبُ القاعدة يُسلّم كلَّ التكاملات');
        $this->assertStringNotContainsString($plain, json_encode($t->toArray(), JSON_UNESCAPED_UNICODE));

        // ويعمل فعلاً
        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/clients')->assertOk();

        // تدوير: القديمةُ تتوقف فوراً والجديدةُ تعمل — **وصلاحيةُ المدة تُجدَّد**
        $res2 = $this->actingAs($this->owner)->post('/profile/token/' . $t->id . '/rotate');
        $new = (string) $res2->getSession()->get('newtoken');
        $this->assertNotSame($plain, $new, 'التدويرُ لم يُغيّر القيمة');

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/clients')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ' . $new)
            ->getJson('/api/v1/clients')->assertOk();

        // إبطال: لا يُقبل بعده
        $this->actingAs($this->owner)->delete('/profile/token/' . $t->id);
        $this->withHeader('Authorization', 'Bearer ' . $new)
            ->getJson('/api/v1/clients')->assertStatus(401);
        $this->assertSame(0, ApiToken::where('id', $t->id)->count());
    }

    /** ولا يمسّ أحدٌ مفتاحَ غيره — الحارسُ على `user_id` لا على المعرّف وحده */
    public function test_nobody_rotates_or_revokes_someone_elses_key(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'مفتاحُ المالك']);
        $t = ApiToken::where('user_id', $this->owner->id)->firstOrFail();

        $this->actingAs($this->employee)->post('/profile/token/' . $t->id . '/rotate')->assertNotFound();
        $this->actingAs($this->employee)->delete('/profile/token/' . $t->id);

        $this->assertSame(1, ApiToken::where('id', $t->id)->count(),
            'أبطل موظفٌ مفتاحَ غيره — الحذفُ بلا حارسٍ على المالك');
    }

    /** **العيبُ الذي كشفه غيابُ الاختبار**: التدويرُ يُبقي انتهاءً قد مضى */
    public function test_rotating_a_key_does_not_hand_back_a_dead_one(): void
    {
        $this->seedCore();

        $this->actingAs($this->owner)->post('/profile/token', ['tname' => 'مفتاحٌ قديم', 'tdays' => 30]);
        $t = ApiToken::where('user_id', $this->owner->id)->firstOrFail();
        $t->forceFill(['expires_at' => now()->subDay()])->save();      // انتهت مدّتُه

        $res = $this->actingAs($this->owner)->post('/profile/token/' . $t->id . '/rotate');
        $new = (string) $res->getSession()->get('newtoken');

        $this->withHeader('Authorization', 'Bearer ' . $new)
            ->getJson('/api/v1/clients')->assertOk();
        // الرسالةُ تقول «انسخ الجديدة الآن» — فإن كانت ميتةً فهي كذبةٌ صريحة
    }

    /* ── ٢) تعطيلُ المصادقة الثنائية لا يقع برمزٍ خاطئ ── */

    public function test_two_factor_cannot_be_disabled_without_a_valid_code(): void
    {
        $this->seedCore();

        $secret = \App\Support\Totp::secret();
        $this->owner->forceFill(['totp_secret_cipher' => $secret, 'totp_enabled' => true])->saveQuietly();

        $this->actingAs($this->owner)->post('/profile/2fa/disable', ['code' => '000000']);
        $this->assertTrue((bool) $this->owner->fresh()->totp_enabled,
            'عُطّلت المصادقةُ الثنائية برمزٍ خاطئ — حارسُ الحساب يُنزَع بلا إثبات');

        $this->actingAs($this->owner)->post('/profile/2fa/disable', ['code' => \App\Support\Totp::code($secret)]);
        $this->assertFalse((bool) $this->owner->fresh()->totp_enabled, 'الرمزُ الصحيح لم يُعطّلها');
        $this->assertNull($this->owner->fresh()->totp_secret_cipher, 'بقي السرُّ مخزَّناً بعد التعطيل');
    }

    /** وسرٌّ فارغٌ لا يفتح البابَ — تكاملُ الحارس مع مسار التعطيل */
    public function test_two_factor_disable_refuses_an_empty_secret(): void
    {
        $this->seedCore();

        $this->owner->forceFill(['totp_secret_cipher' => '', 'totp_enabled' => true])->saveQuietly();

        $this->actingAs($this->owner)->post('/profile/2fa/disable',
            ['code' => \App\Support\Totp::code('')]);

        $this->assertTrue((bool) $this->owner->fresh()->totp_enabled,
            'حسابٌ بسرٍّ ضائع عُطّلت حمايتُه برمزٍ يحسبه أيُّ أحد');
    }

    /* ── ٣) قفلُ الطوارئ على مواضع إنفاذه الثلاثة ── */

    public function test_the_emergency_lockdown_holds_on_all_three_surfaces(): void
    {
        $this->seedCore();

        $token = $this->apiToken($this->employee);
        $this->hubSetting('security.lockdown', '1');

        // (١) الدخول: محاولةٌ جديدة لغير المالك تُرفض — **قبل أي `actingAs`**
        // كي تقع على جلسةٍ ضيف فعلاً
        $this->post('/login', ['email' => $this->employee->email, 'password' => 'Secret!2026x'])
            ->assertSessionHasErrors();
        $this->assertGuest();

        // (٢) الويب: جلسةٌ قائمة لغير المالك تُطرد إلى صفحة القفل
        $this->actingAs($this->employee)->get('/')->assertStatus(503);

        // (٣) API: حاملُ الرمز غير المالك يُردّ
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/clients')->assertStatus(503);

        // والمالكُ يمرّ — وإلا صار القفلُ قفلاً على صاحبه
        $this->actingAs($this->owner)->get('/')->assertOk();
    }

    /* ── ٤) باني الحقول المخصّصة: كتابتان متزامنتان لا تُضيّعان حقلاً ── */

    public function test_two_custom_fields_added_in_a_row_both_survive(): void
    {
        $this->seedCore();

        foreach (['حقلٌ أول', 'حقلٌ ثانٍ', 'حقلٌ ثالث'] as $label) {
            $this->actingAs($this->owner)->post('/admin/fields',
                ['m' => 'clients', 'label' => $label, 'type' => 'text'])->assertRedirect();
        }

        $keys = collect(hub_custom_fields('clients'))->pluck('key');
        $this->assertCount(3, $keys, 'ضاع حقلٌ في الكتابة على صفٍّ واحد (اقرأ-عدّل-اكتب)');
        $this->assertCount(3, $keys->unique(), 'مفتاحٌ تكرّر — فحقلان يتقاسمان قيمةً واحدة');
    }

    /** والحذفُ يُصيب المقصودَ وحده */
    public function test_deleting_one_custom_field_keeps_the_others(): void
    {
        $this->seedCore();

        foreach (['أ', 'ب', 'ج'] as $label) {
            $this->actingAs($this->owner)->post('/admin/fields',
                ['m' => 'clients', 'label' => $label, 'type' => 'text']);
        }
        $mid = collect(hub_custom_fields('clients'))->firstWhere('label', 'ب')['key'];

        $this->actingAs($this->owner)->delete('/admin/fields/clients/' . $mid)->assertRedirect();

        $labels = collect(hub_custom_fields('clients'))->pluck('label')->all();
        $this->assertSame(['أ', 'ج'], $labels, 'الحذفُ أصاب غيرَ المقصود');
    }

    /** وباني الحقول للمالكين وحدهم */
    public function test_the_field_builder_is_owner_only(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->post('/admin/fields',
            ['m' => 'clients', 'label' => 'حقلٌ مدسوس', 'type' => 'text'])->assertForbidden();

        $this->assertSame([], hub_custom_fields('clients'));
    }
}
