<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\InboundHook;
use App\Support\HardeningReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **رافعتان مطفأتان وقارئٌ يسمّي مَن يتوقّف** — البندان #14 و#20 (§٥).
 *
 * ── **لماذا رافعةٌ لا فرض** ──
 *
 * البندان بقيا «قرارَ مالك» لأنّ كلفتَهما مكتوبةٌ بالكلام: «التكاملاتُ
 * القائمةُ تتوقّف» و«المُرسِلونَ القدامى يُرفَضون». **ودرسُ v2.596.0 أنّ
 * «لا تكاملَ يُكسَر» ليست «لا أحدَ يُكسَر»** — هناك رُفع افتراضُ حدِّ كلمةِ
 * السرّ بعد فحصِ التكاملات، فسقط ٢٨ اختباراً لأنّ القيمةَ القديمةَ كانت
 * عُرفاً لا مجرّدَ بيانات.
 *
 * فلا يُفرَض شيءٌ هنا. المفتاحان **مطفآن افتراضياً** — الترقيةُ لا تغيّر
 * حرفاً — والقارئُ يُخرج **قائمةَ الأسماء** قبل أن يُشعلهما أحد.
 *
 * ── **وما يحرسه هذا الملفّ بالتحديد** ──
 *
 * أنّ الافتراضَ **مطفأ**. فرافعةٌ تُشحَن مشتعلةً بالسهو هي بالضبط الكسرةُ
 * التي بُنيت لتمنعها — والاختبارُ الأوّلُ في كلِّ زوجٍ هنا يُثبت أنّ
 * السلوكَ القديمَ يعمل حرفاً بلا إعدادٍ واحد.
 */
class HardeningLeversTest extends TestCase
{
    /* ═══════════ #14 · اشتراطُ 2FA على مفاتيح API ═══════════ */

    /** **مطفأٌ افتراضياً: مفتاحُ صاحبٍ بلا 2FA يعمل كما كان** */
    public function test_مفتاحُ_API_بلا_ثنائيّةٍ_يعمل_افتراضاً(): void
    {
        $this->seedCore();
        $plain = $this->issueToken($this->owner->id);

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/me')->assertOk();
    }

    /** **وبإشعالِ المفتاح يُردّ برمزٍ يقول السبب** */
    public function test_إشعالُ_الاشتراط_يردّ_مفتاحَ_من_بلا_ثنائيّة(): void
    {
        $this->seedCore();
        $plain = $this->issueToken($this->owner->id);
        $this->hubSetting('security.api_require_2fa', '1');

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'ACCOUNT_RESTRICTED')
            ->assertJsonPath('details.reason', 'token_owner_2fa_required');
    }

    /** **ومن فعّل الثنائيّةَ يمرّ والمفتاحُ مشتعل** */
    public function test_صاحبُ_الثنائيّة_يمرّ_والاشتراطُ_مشتعل(): void
    {
        $this->seedCore();
        DB::table('users')->where('id', $this->owner->id)->update(['totp_enabled' => true]);
        $plain = $this->issueToken($this->owner->id);
        $this->hubSetting('security.api_require_2fa', '1');

        $this->withHeader('Authorization', 'Bearer ' . $plain)
            ->getJson('/api/v1/me')->assertOk();
    }

    /** **والقارئُ يسمّي مَن يتوقّف قبل الإشعال** */
    public function test_القارئُ_يسمّي_المفاتيحَ_التي_تتوقّف(): void
    {
        $this->seedCore();
        DB::table('users')->where('id', $this->employee->id)->update(['totp_enabled' => true]);
        $this->issueToken($this->owner->id, 'مفتاحُ المالك');
        $this->issueToken($this->employee->id, 'مفتاحُ الموظّفة');

        $rows = collect(HardeningReadiness::apiTokens())->keyBy('name');

        $this->assertTrue($rows['مفتاحُ المالك']['breaks'], 'مفتاحُ صاحبٍ بلا ثنائيّةٍ لم يُعَدّ متوقّفاً');
        $this->assertFalse($rows['مفتاحُ الموظّفة']['breaks'], 'مفتاحُ صاحبِ ثنائيّةٍ عُدَّ متوقّفاً');
        $this->assertSame(1, HardeningReadiness::summary()['tokens_break']);
    }

    /* ═══════════ #20 · اشتراطُ الختمِ الزمنيّ على الوارد ═══════════ */

    /** **مطفأٌ افتراضياً: مُرسِلٌ بلا ختمٍ زمنيٍّ يعمل كما كان** */
    public function test_الوارد_بلا_ختمٍ_زمنيٍّ_يُقبَل_افتراضاً(): void
    {
        $this->seedCore();
        [$hook, $body, $sign] = $this->hook();

        $this->postHook($hook, $body, ['HTTP_X_HUB_SIGNATURE' => $sign($body)])->assertOk();
    }

    /** **وبإشعالِ المفتاح يُردّ الناقصُ ويمرّ المختوم** */
    public function test_إشعالُ_الاشتراط_يردّ_الوارد_بلا_ختم(): void
    {
        $this->seedCore();
        [$hook, $body, $sign] = $this->hook();
        $this->hubSetting('security.inbound_require_timestamp', '1');

        $this->postHook($hook, $body, ['HTTP_X_HUB_SIGNATURE' => $sign($body)])->assertStatus(401);

        $ts = (string) time();
        $this->postHook($hook, $body, ['HTTP_X_HUB_TIMESTAMP' => $ts,
            'HTTP_X_HUB_SIGNATURE' => $sign($ts . '.' . $body)])->assertOk();
    }

    /**
     * **والرصدُ يعمل في الحالتين** — فالقائمةُ تُبنى قبل أيِّ إشعال.
     *
     * وهذا هو جوهرُ البند: لا يُقرَّر «أنفرض؟» بل يُقرأ «مَن يتوقّف؟».
     */
    public function test_الرصد_يميّز_المختومَ_من_الناقص(): void
    {
        $this->seedCore();
        [$hook, $body, $sign] = $this->hook();

        $this->postHook($hook, $body, ['HTTP_X_HUB_SIGNATURE' => $sign($body)])->assertOk();

        $row = collect(HardeningReadiness::inboundHooks())->firstWhere('id', $hook->id);
        $this->assertNotNull($row['without'], 'الطلبُ الناقصُ لم يُرصَد');
        $this->assertNull($row['with'], 'رُصد ختمٌ لم يُرسَل');
        $this->assertSame('يتوقّف', $row['state'], 'نقطةٌ وصلها ناقصٌ حديثاً عُدَّت جاهزة');
        $this->assertSame(1, HardeningReadiness::summary()['hooks_break']);
    }

    /** **ونقطةٌ لم يصلها شيءٌ «مجهولة» لا «جاهزة»** — لا يُخمَّن ما لم يُرصَد */
    public function test_نقطةٌ_بلا_رصدٍ_تُعلَن_مجهولةً_لا_جاهزة(): void
    {
        $this->seedCore();
        [$hook] = $this->hook();

        $row = collect(HardeningReadiness::inboundHooks())->firstWhere('id', $hook->id);
        $this->assertSame('لم تُرصَد', $row['state'],
            'نقطةٌ بلا طلبٍ واحدٍ أُعلنت جاهزةً — وذاك تخمينٌ لا قياس');
        $this->assertSame(1, HardeningReadiness::summary()['hooks_unknown']);
    }

    /**
     * **والرصدُ مخنوقٌ بالدقيقة** — رصدٌ يكتب لكلِّ طلبٍ على سطحٍ عامّ هو
     * عيبُ أداءٍ يُضاف باسم القياس. فالكتابةُ واحدةٌ بالدقيقة كحدٍّ أقصى،
     * نمطَ `ApiAuth::last_used_at`.
     */
    public function test_الرصد_يكتب_مرّةً_بالدقيقة_لا_لكلِّ_طلب(): void
    {
        $this->seedCore();
        [$hook, $body, $sign] = $this->hook();

        // أوّلُ طلبٍ يرصد، ويُؤخَّر الختمُ إلى الوراء كي يُقاس ما بعده
        $this->postHook($hook, $body, ['HTTP_X_HUB_SIGNATURE' => $sign($body)])->assertOk();
        $first = DB::table('inbound_hooks')->where('id', $hook->id)->value('ts_missing_at');
        $this->assertNotNull($first, 'الطلبُ الأوّلُ لم يُرصَد');

        // طلبٌ ثانٍ في الدقيقةِ نفسِها — لا يُحرِّك الختم (والمعرّفُ يتغيّر كي يمرّ لا يُردَّ تكراراً)
        $this->postHook($hook, '{"event":"x","n":2}',
            ['HTTP_X_HUB_SIGNATURE' => $sign('{"event":"x","n":2}')])->assertOk();

        $this->assertSame((string) $first,
            (string) DB::table('inbound_hooks')->where('id', $hook->id)->value('ts_missing_at'),
            'الرصدُ كتب مرّتين في دقيقةٍ واحدة — الخنقُ لا يعمل');
    }

    /* ═══════════ الشاشتان — قارئٌ لا يُرى لا يصنع قراراً ═══════════ */

    /**
     * **مركزُ رموز API يسمّي مَن يتوقّف**.
     *
     * القارئُ وحدَه لا يكفي: المالكُ لا يفتح طرفيّةً ليقرأ صنفَ PHP. فما لم
     * تظهر القائمةُ في الشاشةِ التي يفتحها أصلاً، بقي البندُ «قرارَ مالك»
     * كما كان — بقارئٍ إضافيٍّ لا يقرؤه أحد.
     */
    public function test_شاشةُ_الرموز_تعرض_مَن_يتوقّف(): void
    {
        $this->seedCore();
        DB::table('users')->where('id', $this->employee->id)->update(['totp_enabled' => true]);
        $this->issueToken($this->owner->id, 'مفتاحُ المالك');
        $this->issueToken($this->employee->id, 'مفتاحُ الموظّفة');

        $html = $this->actingAs($this->owner)->get('/admin/security/tokens')->assertOk()->getContent();

        $this->assertStringContainsString('بلا ثنائيّة', $html, 'الشاشةُ لا تُميّز صاحبَ رمزٍ بلا ثنائيّة');
        $this->assertStringContainsString('مفعّلة', $html, 'الشاشةُ لا تُميّز صاحبَ الثنائيّة');
        $this->assertStringContainsString('security.api_require_2fa', $html,
            'الشاشةُ لا تسمّي المفتاحَ الذي يُشعله المالك — فالقرارُ يبقى بلا عنوان');
    }

    /** **وشاشةُ الوارد تقول حالةَ كلِّ نقطةٍ بالاسم** */
    public function test_شاشةُ_الوارد_تعرض_حالةَ_الختمِ_الزمنيّ(): void
    {
        $this->seedCore();
        [$hook, $body, $sign] = $this->hook();
        $this->postHook($hook, $body, ['HTTP_X_HUB_SIGNATURE' => $sign($body)])->assertOk();

        $html = $this->actingAs($this->owner)->get('/admin/integrations/hooks')->assertOk()->getContent();

        $this->assertStringContainsString('يتوقّف', $html, 'الشاشةُ لا تقول إنّ النقطةَ تتوقّف');
        $this->assertStringContainsString('security.inbound_require_timestamp', $html,
            'الشاشةُ لا تسمّي مفتاحَ الاشتراط');
        $this->assertStringContainsString('X-Hub-Timestamp', $html, 'الدليلُ لا يذكر الترويسةَ التي يُطلَب تبنّيها');
    }

    /* ═══════════ أدواتُ التهيئة ═══════════ */

    /** مفتاحُ API حيٌّ لصاحبٍ — يُعيد الرمزَ الخامّ */
    protected function issueToken(string $userId, string $name = 'مفتاحُ اختبار'): string
    {
        $plain = Str::random(48);
        ApiToken::create([
            'user_id' => $userId, 'name' => $name,
            'token_hash' => hash('sha256', $plain), 'created_at' => now(),
        ]);

        return $plain;
    }

    /** نقطةُ استقبالٍ بسرٍّ + جسمٌ + مُوقِّع */
    protected function hook(): array
    {
        $hook = InboundHook::create(['name' => 'نقطةُ الرصد', 'token' => Str::random(48),
            'module' => 'clients', 'secret' => 's3cret', 'enabled' => true,
            'created_by' => $this->owner->id]);
        $body = '{"event":"x","n":1}';

        return [$hook, $body, fn (string $d) => 'sha256=' . hash_hmac('sha256', $d, 's3cret')];
    }

    /** طلبُ استقبالٍ خامٌّ — نمطُ `EnterpriseHardeningRound3Test` حرفاً */
    protected function postHook(InboundHook $hook, string $body, array $headers)
    {
        return $this->call('POST', '/hook/' . $hook->token, [], [], [],
            ['CONTENT_TYPE' => 'application/json'] + $headers, $body);
    }
}
