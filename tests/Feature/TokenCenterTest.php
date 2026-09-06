<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-4.5 — مركزُ رموز API (security.tokens) + التصنيفُ الواحد ApiTokens::classify.
 *
 * القواعد المُثبَتة هنا:
 *  - تصنيفٌ **واحد** لكل رمز (مُبطَل/منتهٍ/خامل/بلا انتهاء/قديم/كامل الامتياز/سليم)
 *    وعتبتُه من `security.token_unused_days` — لا ثابتَ منسوخاً ثانياً.
 *  - `SecurityPosture::apiStale` يستهلك التصنيفَ نفسَه (تفويضٌ لا عتبتان)،
 *    والمُبطَل والمنتهي **خارج** الخطر الحيّ — ميّتان لا خاملان.
 *  - `ApiAuth` يرفض الرمزَ المُبطَل (401) — الإبطالُ الإداريّ يقتل فعلاً.
 *  - `last_ip` يُكتب بخنق الدقيقة القائم لـ`last_used_at` نفسِه — لا كتابةَ لكل طلب.
 *  - صفحةُ المركز للمالك وحدَه (بياناتُ اعتماد — critic #9: monitor يُصَدّ لا يُطمَس)
 *    ولا مادةَ رمزٍ (نصٌّ صريح أو بصمة) في مصدرها إطلاقاً.
 *  - الإبطالُ الإداريّ بتصعيدِ اعتمادٍ ومدقَّق برمز API_CREDENTIAL_REVOKED —
 *    ولا مسارَ تدويرٍ لرمز مستخدمٍ آخر أصلاً (ق٧: النصُّ الصريح كان سيصل المدير).
 */
class TokenCenterTest extends TestCase
{
    /** مستخدمٌ بعلم monitor وحده (غير مالك) — يقرأ المراكزَ المنطَّقة لا مركز الرموز */
    protected function monitorUser(): User
    {
        $role = Role::create(['name' => 'مراقب', 'scope' => 'all', 'flags' => ['monitor' => 1], 'matrix' => []]);

        return User::create(['name' => 'مراقب', 'email' => 'mon@test.local', 'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط', 'password_changed_at' => now()]);
    }

    protected function makeToken(array $over = []): ApiToken
    {
        return ApiToken::create($over + [
            'user_id' => $this->owner->id, 'name' => 'رمز اختبار',
            'token_hash' => hash('sha256', 'tok-' . \Illuminate\Support\Str::random(24)),
            'created_at' => now(),
        ]);
    }

    /** تصنيفٌ واحد صحيحٌ لكل رمز — والعتبةُ من الإعداد لا من ثابت */
    public function test_classify_yields_one_status_per_token_with_threshold_from_settings(): void
    {
        $this->seedCore();
        $c = fn (array $a) => \App\Support\ApiTokens::classify((object) ($a + [
            'revoked_at' => null, 'expires_at' => now()->addDays(30), 'last_used_at' => now(),
            'created_at' => now()->subDays(5), 'scopes' => 'tasks:v', 'privileged' => false,
        ]));

        $this->assertSame('revoked', $c(['revoked_at' => now()->subHour()]), 'المُبطَل يتقدّم كلَّ تصنيف');
        $this->assertSame('expired', $c(['expires_at' => now()->subDay()]));
        $this->assertSame('unused', $c(['last_used_at' => now()->subDays(100)]));
        $this->assertSame('unused', $c(['last_used_at' => null]), 'من لم يُستعمل قطّ خامل');
        $this->assertSame('never-expires', $c(['expires_at' => null]));
        $this->assertSame('old', $c(['created_at' => now()->subDays(400)]));
        $this->assertSame('over-privileged', $c(['scopes' => null, 'privileged' => true]),
            'رمزٌ كامل النطاق لمستخدمٍ مميّز = اعتمادٌ يفتح كلَّ شيء');
        $this->assertSame('ok', $c([]));
        $this->assertSame('ok', $c(['scopes' => null]), 'كمالُ النطاق وحدَه لغير المميّز ليس تصنيفَ خطر');

        // العتبةُ من security.token_unused_days — تُقرأ حيّةً لا تُنسخ
        $this->hubSetting('security.token_unused_days', '30');
        $this->assertSame('unused', $c(['last_used_at' => now()->subDays(40)]));
        $this->assertSame('ok', $c(['last_used_at' => now()->subDays(20)]));
    }

    /** فحصُ الوضعية يستهلك التصنيفَ نفسَه — والمُبطَل/المنتهي خارج الخطر الحيّ */
    public function test_posture_api_stale_delegates_to_the_same_classification(): void
    {
        $this->seedCore();
        $idle = $this->makeToken(['last_used_at' => now()->subDays(120), 'expires_at' => now()->addDays(30)]);
        $noexp = $this->makeToken(['last_used_at' => now(), 'expires_at' => null]);
        $expired = $this->makeToken(['last_used_at' => now(), 'expires_at' => now()->subDay()]);
        $fresh = $this->makeToken(['last_used_at' => now(), 'expires_at' => now()->addDays(30)]);
        $revoked = $this->makeToken(['last_used_at' => now()->subDays(120), 'expires_at' => now()->addDays(30)]);
        DB::table('api_tokens')->where('id', $revoked->id)
            ->update(['revoked_at' => now(), 'revoked_by' => $this->owner->id]);

        $ids = array_map('strval', \App\Support\SecurityPosture::apiStaleIds());
        $this->assertContains((string) $idle->id, $ids, 'الخاملُ خطرٌ حيّ');
        $this->assertContains((string) $noexp->id, $ids, 'بلا انتهاءٍ خطرٌ حيّ');
        $this->assertNotContains((string) $expired->id, $ids, 'المنتهي ميتٌ لا خامل');
        $this->assertNotContains((string) $fresh->id, $ids);
        $this->assertNotContains((string) $revoked->id, $ids, 'المُبطَل ميتٌ — عدُّه خطراً ضجيج');

        $check = collect(\App\Support\SecurityPosture::checks())->firstWhere('key', 'api_stale');
        $this->assertSame(2, $check['n'], 'عدُّ الفحص لا يطابق التصنيفَ الواحد (خامل + بلا انتهاء)');
    }

    /** الرمزُ المُبطَل يُرفض على سطح API فوراً */
    public function test_api_auth_rejects_a_revoked_token(): void
    {
        $this->seedCore();
        $plain = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $plain];

        $this->withHeaders($h)->getJson('/api/v1/me')->assertOk();

        DB::table('api_tokens')->where('token_hash', hash('sha256', $plain))
            ->update(['revoked_at' => now(), 'revoked_by' => $this->owner->id]);

        $this->withHeaders($h)->getJson('/api/v1/me')->assertStatus(401);
    }

    /** last_ip يُكتب مرّةً في الدقيقة كحدٍّ أقصى — خنقُ last_used_at نفسُه */
    public function test_last_ip_is_written_with_the_same_minute_throttle_as_last_used(): void
    {
        $this->seedCore();
        $plain = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $plain];
        $find = fn () => DB::table('api_tokens')->where('token_hash', hash('sha256', $plain))->first();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders($h)->getJson('/api/v1/me')->assertOk();
        $this->assertSame('203.0.113.9', $find()->last_ip, 'أولُ طلبٍ يكتب العنوان');

        // في الدقيقة نفسِها من عنوانٍ آخر: لا كتابةَ ثانية — الخنقُ القائم يسري
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders($h)->getJson('/api/v1/me')->assertOk();
        $this->assertSame('203.0.113.9', $find()->last_ip, 'كتابةٌ لكل طلبٍ — الخنقُ لا يسري على last_ip');

        $this->travel(2)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders($h)->getJson('/api/v1/me')->assertOk();
        $this->assertSame('203.0.113.50', $find()->last_ip, 'بعد الدقيقة يتحدّث العنوان');
        $this->travelBack();
    }

    /** المركزُ للمالك وحدَه — ولا مادةَ رمزٍ (نصّ/بصمة) في المصدر (اختبارُ التسريب الصريح) */
    public function test_token_centre_is_owner_only_and_never_leaks_token_material(): void
    {
        $this->seedCore();
        $plain = $this->apiToken($this->owner);
        $this->makeToken(['name' => 'رمز الجرد', 'last_used_at' => now()->subDays(120), 'expires_at' => null]);

        // بياناتُ اعتماد: monitor والموظفُ يُصَدّان ٤٠٣ — لا نسخةَ مطموسة هنا
        $this->actingAs($this->employee)->get('/admin/security/tokens')->assertForbidden();
        $this->actingAs($this->monitorUser())->get('/admin/security/tokens')->assertForbidden();

        $html = $this->actingAs($this->owner)->get('/admin/security/tokens')->assertOk()->getContent();
        $this->assertStringContainsString('رمز الجرد', $html);
        $this->assertStringNotContainsString($plain, $html, 'النصُّ الصريح في مصدر الصفحة');
        $hash = (string) DB::table('api_tokens')->where('token_hash', hash('sha256', $plain))->value('token_hash');
        $this->assertStringNotContainsString($hash, $html, 'بصمةُ الرمز في مصدر الصفحة');
        // لا مادةَ رمزٍ بشكل lyn_<ذيلٍ طويل> إطلاقاً (lyn_theme/lyn_did أسماءٌ قصيرة مباحة)
        $this->assertDoesNotMatchRegularExpression('~lyn_[A-Za-z0-9]{16,}~', $html);
    }

    /** الإبطالُ الإداريّ: مالكٌ + تصعيدُ اعتماد + قيدُ تدقيق — والرمزُ يموت على API فعلاً */
    public function test_admin_revoke_requires_credential_stepup_is_audited_and_kills_the_token(): void
    {
        $this->seedCore();
        $plain = $this->apiToken($this->employee);
        $t = DB::table('api_tokens')->where('token_hash', hash('sha256', $plain))->first();

        // غيرُ المالك يُصَدّ — بما فيه صاحبُ الرمز نفسُه (سكّتُه سكّةُ الملف الشخصي)
        $this->actingAs($this->employee)->post("/admin/security/tokens/{$t->id}/revoke")->assertForbidden();

        // تصعيدُ الاعتماد مفعَّل: بلا ختمٍ حديث يُحوَّل للتأكيد ولا يُبطَل شيء
        $this->hubSetting('security.stepup_credentials', '1');
        $r = $this->actingAs($this->owner)->post("/admin/security/tokens/{$t->id}/revoke");
        $r->assertRedirect();
        $this->assertStringContainsString('/stepup?next=', (string) $r->headers->get('Location'));
        $this->assertNull(DB::table('api_tokens')->where('id', $t->id)->value('revoked_at'),
            'أُبطل الرمزُ قبل تأكيد الهوية');

        $this->actingAs($this->owner)->post('/stepup', ['answer' => 'Secret!2026x', 'next' => '/admin/security/tokens']);
        $this->actingAs($this->owner)->post("/admin/security/tokens/{$t->id}/revoke")->assertRedirect();

        $row = DB::table('api_tokens')->where('id', $t->id)->first();
        $this->assertNotNull($row->revoked_at, 'الإبطالُ لم يُختم');
        $this->assertSame((string) $this->owner->id, (string) $row->revoked_by, 'مَن أبطل؟ — الأثرُ ناقص');
        $this->assertTrue(AuditEntry::where('action', 'إبطال مفتاح API')->exists(),
            'إبطالُ اعتمادٍ حدثٌ أمنيّ (API_CREDENTIAL_REVOKED) — بلا قيدِ تدقيق');

        // والموتُ فعليّ لا وسمٌ للعرض
        $this->withHeaders(['Authorization' => 'Bearer ' . $plain])->getJson('/api/v1/me')->assertStatus(401);
    }
}
