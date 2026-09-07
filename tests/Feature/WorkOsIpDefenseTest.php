<?php

namespace Tests\Feature;

use App\Models\IpRule;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **الفرضُ + حمايةُ المالك + الحالةُ الصادقة** (Work OS · الطور I · WP-I.3 · §39/§42).
 *
 * يمتدّ هذا الملفُّ ثلاثَ سوابقَ معلنة:
 *  • `SupportTest` (ip_allowed) — الفرضُ كلُّه يمرّ بالمُطابِق الواحد: حالاتُ
 *    IPv4/IPv6/CIDR هنا هي حالاتُه نفسُها على طبقة HTTP لا على الدالة وحدها.
 *  • `IpIntelTest` — سكّةُ `access_denials` القائمة: منعُ الدفاع يُسجَّل فيها
 *    (لا سجلَّ منعٍ ثانٍ) فيغذّي التجميعَ والتصعيدَ الآليّ (WP-I.2) بنفسه.
 *  • `ClientOperationsTest`/`WorkOsPortalGuardTest` — دلالةُ ٤٠٤ للعميل وغير
 *    المالك على السطوح الإدارية: شاشةُ `security.blocks` تتبعها حرفياً.
 *
 * القواعدُ الصلبة (المواصفة §39–42 — غيرُ قابلةٍ للتفاوض):
 *  ١) **fail-open**: جدولٌ مكسورٌ/غائب أو أيُّ عطلٍ في التقييم = الطلبُ يمرّ —
 *     انقطاعُ الدفاع لا يصير انقطاعَ موقع. allow يفوز block دائماً.
 *  ٢) **مالكٌ مصادَقٌ لا يُحظر أبداً** — وضربتُه تُشفي القاعدةَ (إلغاءٌ مؤثَّل)
 *     لا تبقى كامنةً تصيد دخولَه التالي.
 *  ٣) **حمايةُ حبس آخر مالك خادميّة**: قاعدةُ حظرٍ تغطي عنوانَ آخرِ مالكٍ
 *     (عنوانه الحاليّ أو المعروف في user_ips) تُرفض مهما أرسل النموذج.
 *  ٤) الإدارةُ owner-only (٤٠٤ لغيره) + step-up + قيدُ تدقيقٍ بدلالة
 *     SECURITY_POLICY_CHANGED؛ والمنتهي يتوقف عن الصدّ فوراً.
 *  ٥) **الحالةُ صادقة**: حظرُ التطبيق فعّالٌ دائماً (authoritative)؛ وحظرُ
 *     حافّة الشبكة «غير مُهيّأ» ما لم يُضبط اعتمادٌ حقيقيّ — لا ادّعاءَ حافّةٍ زائفاً.
 */
class WorkOsIpDefenseTest extends TestCase
{
    /** جلسةُ تصعيدٍ سارية — نمطُ WorkOsInventoryTest حرفياً */
    protected function withStepup()
    {
        return $this->withSession(['stepup.ok_until' => now()->addMinutes(10)->timestamp]);
    }

    /** الطلبُ من عنوانٍ بعينه */
    protected function fromIp(string $ip)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** قاعدةُ حظرٍ يدوية جاهزة */
    protected function block(string $ip, array $extra = []): IpRule
    {
        return IpRule::create(array_merge(['ip' => $ip, 'mode' => 'block', 'origin' => 'manual'], $extra));
    }

    /** حسابُ عميلٍ صلب (account_type=client) — نمطُ WorkOsPortalGuardTest */
    protected function clientUser(): User
    {
        $modules = array_keys(config('hub.modules'));
        $full = collect($modules)->mapWithKeys(fn ($m) => [$m => ['v' => 1, 'a' => 1, 'e' => 1, 'd' => 1]])->all();
        $role = Role::create(['name' => 'دور عميل ' . Str::random(5), 'scope' => 'all',
            'flags' => [], 'matrix' => $full]);

        return User::create(['name' => 'حسابُ عميل', 'email' => Str::random(8) . '@client.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'account_type' => 'client', 'password_changed_at' => now()]);
    }

    /* ────────── ① الفرضُ يعمل على الويب وعلى API — بالردّ المفاوَض ────────── */

    public function test_a_blocked_ip_gets_the_negotiated_deny_on_web_and_api(): void
    {
        $this->seedCore();
        $rule = $this->block('203.0.113.66', ['reason' => 'اختبارُ فرض']);

        // الويب: صفحةُ منعٍ ٤٠٣ (نمطُ HubMaintenance: HTML لطالب HTML)
        $web = $this->fromIp('203.0.113.66')->get('/login');
        $web->assertStatus(403);
        $web->assertSee('محظور');

        // API: الغلافُ الموحَّد JSON بكودٍ آليّ + request_id — لا صفحةَ HTML لعميلٍ آليّ
        $api = $this->fromIp('203.0.113.66')->getJson('/api/v1/me');
        $api->assertStatus(403);
        $this->assertSame('FORBIDDEN', $api->json('code'), 'ردُّ API المحظور بلا كودٍ آليّ');
        $this->assertNotEmpty($api->json('request_id'), 'ردُّ API المحظور بلا معرّفِ طلب');

        // المنعُ يُسجَّل في سكّة access_denials **القائمة** (لا سجلَّ ثانٍ) والعدّادُ يتحرّك
        $this->assertSame(2, (int) DB::table('access_denials')
            ->where('kind', 'حظر IP')->where('ip', '203.0.113.66')->count(),
            'منعُ الدفاع لا يبلغ سكّةَ access_denials القائمة');
        $this->assertSame(2, (int) $rule->fresh()->hits, 'عدّادُ إصابات القاعدة لا يتحرّك');

        // وعنوانٌ نظيفٌ يمرّ كما كان — الدفاعُ صدٌّ مستهدَفٌ لا ستارةٌ عامة
        $this->fromIp('198.51.100.1')->get('/login')->assertOk();
    }

    /* ────────── ② allow يفوز block — دائماً ────────── */

    public function test_an_allow_rule_beats_a_covering_block_rule(): void
    {
        $this->seedCore();
        $this->block('203.0.113.0/24');
        IpRule::create(['ip' => '203.0.113.9', 'mode' => 'allow', 'origin' => 'manual',
            'reason' => 'سماحٌ صريح داخل شبكةٍ محظورة']);

        $this->fromIp('203.0.113.9')->get('/login')->assertOk();       // المحميُّ يمرّ
        $this->fromIp('203.0.113.10')->get('/login')->assertStatus(403); // وجارُه في الشبكة يُصدّ
    }

    /* ────────── ③ المنتهي يتوقف عن الصدّ فوراً — ولو كانت الخبيئةُ تحمله ────────── */

    public function test_an_expired_temporary_block_stops_denying_immediately(): void
    {
        $this->seedCore();
        $this->block('203.0.113.77', ['expires_at' => now()->addMinutes(10)]);

        $this->fromIp('203.0.113.77')->get('/login')->assertStatus(403);

        // انقضت المدةُ — الفحصُ لحظيٌّ لكل طلبٍ لا رهينةَ TTL الخبيئة (حظرٌ شبح = انقطاع)
        $this->travel(11)->minutes();
        $this->fromIp('203.0.113.77')->get('/login')->assertOk();
    }

    /* ────────── ④ مالكٌ مصادَقٌ من عنوانٍ محظور: يمرّ وتُشفى القاعدة ────────── */

    public function test_an_authenticated_owner_from_a_blocked_ip_passes_and_the_rule_heals(): void
    {
        $this->seedCore();
        $rule = $this->block('203.0.113.88');

        $this->actingAs($this->owner)->fromIp('203.0.113.88')->get('/')->assertOk();

        // الضربةُ شفت القاعدةَ: إلغاءٌ صريحٌ بأثرِه لا صمتٌ يصيد دخولَه التالي قبل المصادقة
        $rule->refresh();
        $this->assertNotNull($rule->revoked_at,
            'قاعدةٌ طابقت مالكاً مصادَقاً بقيت حيّةً — ستصدّه عند أول دخولٍ غير مصادَق');
        $this->assertSame(1, (int) DB::table('audits')
            ->where('category', 'SECURITY_POLICY_CHANGED')->where('action', 'like', '%شفاء%')->count(),
            'الشفاءُ الآليّ بلا قيدِ تدقيقٍ بدلالة SECURITY_POLICY_CHANGED');

        // وبعد الشفاء يمرّ حتى الزائرُ من العنوان — القاعدةُ أُلغيت فعلاً
        $guest = $this->fromIp('203.0.113.88');
        auth()->logout();
        $guest->get('/login')->assertOk();
    }

    /* ────────── ⑤ fail-open: جدولٌ مُسقَطٌ لا يُسقط الموقع ────────── */

    public function test_fail_open_the_site_still_serves_with_the_ip_rules_table_dropped(): void
    {
        $this->seedCore();
        $this->block('203.0.113.99');

        Schema::drop('ip_rules');
        Cache::flush();   // انقضاءُ TTL الخبيئة — لا صفوفَ محفوظةً تُقيِّم

        $this->fromIp('203.0.113.99')->get('/login')->assertOk();
        $this->fromIp('198.51.100.3')->getJson('/api/v1/me')->assertStatus(401); // سطحُ API حيٌّ كذلك (رفضُ مفتاحٍ لا رفضُ دفاع)
    }

    /* ────────── ⑥ حمايةُ حبس آخر مالك — خادميّةٌ مهما أرسل النموذج ────────── */

    public function test_a_block_covering_the_last_owner_ip_is_refused_server_side(): void
    {
        $this->seedCore();   // مالكٌ واحد = آخرُ مالك
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'ip' => '192.0.2.10', 'hits' => 5, 'last_seen_at' => now()]);

        $t = $this->actingAs($this->owner)->withStepup()->fromIp('198.51.100.9');

        // (أ) عنوانُ المالك المعروف (user_ips) — يُرفض
        $t->post(route('security.blocks.store'), ['ip' => '192.0.2.10', 'mode' => 'block'])
            ->assertSessionHas('err');
        // (ب) عنوانُ المالك الحاليّ نفسُه — يُرفض
        $t->post(route('security.blocks.store'), ['ip' => '198.51.100.9', 'mode' => 'block'])
            ->assertSessionHas('err');
        // (ج) شبكةُ CIDR تغطي عنوانَه المعروف — يُرفض (المطابقةُ بالمُطابِق الواحد لا بالنصّ)
        $t->post(route('security.blocks.store'), ['ip' => '192.0.2.0/24', 'mode' => 'block'])
            ->assertSessionHas('err');

        $this->assertSame(0, (int) DB::table('ip_rules')->count(),
            'قاعدةٌ تحبس آخرَ مالكٍ كُتبت رغم الرفض — الحمايةُ ليست خادميّة');

        // (د) عنوانٌ أجنبيّ يُنشأ عادياً — الحمايةُ رفضٌ مستهدَفٌ لا تعطيلٌ للشاشة
        $t->post(route('security.blocks.store'), ['ip' => '203.0.113.5', 'mode' => 'block', 'reason' => 'معتدٍ'])
            ->assertSessionHas('ok');
        $this->assertSame(1, (int) DB::table('ip_rules')->where('ip', '203.0.113.5')->count());

        // وقيدُ التدقيق بدلالة SECURITY_POLICY_CHANGED للإنشاء
        $this->assertGreaterThanOrEqual(1, (int) DB::table('audits')
            ->where('category', 'SECURITY_POLICY_CHANGED')->where('action', 'like', '%قاعدة حظر IP%')->count(),
            'إدارةُ القواعد بلا قيدِ تدقيقٍ بدلالة SECURITY_POLICY_CHANGED');
    }

    /* ────────── ⑦ لا إدارةَ بلا step-up ────────── */

    public function test_add_extend_and_revoke_without_stepup_are_refused(): void
    {
        $this->seedCore();
        $rule = $this->block('203.0.113.44', ['expires_at' => now()->addMinutes(15)]);
        $t = $this->actingAs($this->owner)->fromIp('198.51.100.9');   // بلا جلسة تصعيد

        foreach ([
            [route('security.blocks.store'), ['ip' => '203.0.113.41', 'mode' => 'block']],
            [route('security.blocks.extend', $rule->id), ['minutes' => 60]],
            [route('security.blocks.revoke', $rule->id), []],
        ] as [$url, $payload]) {
            $resp = $t->post($url, $payload);
            $resp->assertStatus(302);
            $this->assertStringContainsString('stepup', (string) $resp->headers->get('Location'),
                'فعلٌ حسّاس مرّ بلا تصعيد هوية: ' . $url);
        }

        $this->assertSame(1, (int) DB::table('ip_rules')->count(), 'إضافةٌ بلا تصعيدٍ كُتبت');
        $rule->refresh();
        $this->assertNull($rule->revoked_at, 'إلغاءٌ بلا تصعيدٍ نفذ');
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($rule->expires_at, true), 1.0,
            'تمديدٌ بلا تصعيدٍ نفذ');
    }

    /* ────────── ⑧ العميلُ وغيرُ المالك: ٤٠٤ على شاشة الإدارة ────────── */

    public function test_client_and_non_owner_accounts_get_404_on_the_blocks_screen(): void
    {
        $this->seedCore();
        $rule = $this->block('203.0.113.30');

        // حسابُ عميلٍ (ولو بمصفوفةٍ كاملة — الحارسُ فوق المصفوفة) وموظفٌ ومشاهد: ٤٠٤ لا ٤٠٣
        foreach ([$this->clientUser(), $this->employee, $this->viewer] as $u) {
            $this->actingAs($u)->get(route('security.blocks'))->assertNotFound();
            $this->actingAs($u)->withStepup()
                ->post(route('security.blocks.store'), ['ip' => '203.0.113.31', 'mode' => 'block'])
                ->assertNotFound();
            $this->actingAs($u)->withStepup()
                ->post(route('security.blocks.revoke', $rule->id))->assertNotFound();
        }
        $this->assertSame(1, (int) DB::table('ip_rules')->count());

        // والمالكُ يفتحها
        $this->actingAs($this->owner)->get(route('security.blocks'))->assertOk();
    }

    /* ────────── ⑨ IPv4 وIPv6 وCIDR — حالاتُ المُطابِق الواحد على طبقة HTTP ────────── */

    public function test_ipv4_ipv6_exact_and_cidr_blocks_enforce_on_http(): void
    {
        $this->seedCore();
        $this->block('198.51.100.7');       // v4 دقيق
        $this->block('2001:db8::/32');      // v6 CIDR
        $this->block('2001:db8:ffff::1');   // v6 دقيق (داخل الشبكة أعلاه أيضاً — لا تعارض)

        $this->fromIp('198.51.100.7')->get('/login')->assertStatus(403);
        $this->fromIp('198.51.100.70')->get('/login')->assertOk();   // دقيقٌ لا بادئة (درسُ SupportTest)
        $this->fromIp('2001:db8::5')->get('/login')->assertStatus(403);
        $this->fromIp('2001:dead::1')->get('/login')->assertOk();    // خارج الشبكة
    }

    /* ────────── ⑩ trusted_ips قناةُ تعافٍ تمرّ فوق أي حظر ────────── */

    public function test_a_trusted_ip_passes_even_when_a_block_matches(): void
    {
        $this->seedCore();
        $this->hubSetting('security.trusted_ips', '203.0.113.0/24');
        $this->block('203.0.113.0/24');

        $this->fromIp('203.0.113.5')->get('/login')->assertOk();
    }

    /* ────────── ⑪ التمديدُ والإلغاءُ يعملان — والإلغاءُ يرفع الصدَّ فعلاً ────────── */

    public function test_extend_and_revoke_change_enforcement_and_are_audited(): void
    {
        $this->seedCore();
        $t = $this->actingAs($this->owner)->withStepup()->fromIp('198.51.100.9');
        $rule = $this->block('203.0.113.31', ['expires_at' => now()->addMinutes(10)]);

        $t->post(route('security.blocks.extend', $rule->id), ['minutes' => 120])->assertSessionHas('ok');
        $rule->refresh();
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($rule->expires_at, true), 1.0,
            'التمديدُ لم يدفع أجلَ القاعدة');

        // وتمديدُ قاعدةٍ تحبس آخرَ مالكٍ يُرفض كالإنشاء (rule or edit)
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'ip' => '203.0.113.31', 'hits' => 2, 'last_seen_at' => now()]);
        $t->post(route('security.blocks.extend', $rule->id), ['minutes' => 240])->assertSessionHas('err');
        $rule->refresh();
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($rule->expires_at, true), 1.0,
            'تمديدٌ يحبس آخرَ مالكٍ نفذ رغم الرفض');

        $t->post(route('security.blocks.revoke', $rule->id))->assertSessionHas('ok');
        $rule->refresh();
        $this->assertNotNull($rule->revoked_at);
        $this->assertSame((string) $this->owner->id, (string) $rule->revoked_by);

        // الإلغاءُ يرفع الصدَّ فوراً — زائرٌ من العنوان يمرّ
        auth()->logout();
        $this->fromIp('203.0.113.31')->get('/login')->assertOk();

        $this->assertGreaterThanOrEqual(2, (int) DB::table('audits')
            ->where('category', 'SECURITY_POLICY_CHANGED')
            ->where(fn ($w) => $w->where('action', 'like', '%تمديد قاعدة%')->orWhere('action', 'like', '%إلغاء قاعدة%'))
            ->count(), 'التمديدُ/الإلغاءُ بلا قيود تدقيقٍ بدلالة SECURITY_POLICY_CHANGED');
    }

    /* ────────── ⑫ الحالةُ الصادقة: التطبيقُ فعّال والحافّةُ غيرُ مُهيّأة ────────── */

    public function test_the_card_shows_app_active_and_edge_not_configured_honestly(): void
    {
        $this->seedCore();

        $page = $this->actingAs($this->owner)->get(route('security.blocks'));
        $page->assertOk();
        $page->assertSee('حظرُ التطبيق');
        $page->assertSee('فعّال');
        $page->assertSee('حظرُ حافّة الشبكة');
        $page->assertSee('غير مُهيّأ');

        // محوّلٌ معلَنٌ بمرجعِ سرٍّ مكسور (لا سجلَّ خزنةٍ يقابله) — يتدهور صادقاً
        $this->hubSetting('security.edge_adapter', 'cloudflare');
        $this->hubSetting('security.edge_cloudflare_secret_ref', (string) Str::uuid());
        $this->assertFalse(\App\Support\EdgeDefense::configured(),
            'مرجعُ VaultSecret مكسورٌ وما زال المحوّل يدّعي التهيئة');
        $again = $this->actingAs($this->owner)->get(route('security.blocks'));
        $again->assertSee('غير مُهيّأ');

        // ولا قيمةَ سرٍّ خامّ في مفاتيح الإعداد — المفتاحُ مرجعُ معرّفٍ فقط
        $this->assertMatchesRegularExpression('/^[0-9a-fA-F-]{36}$/',
            (string) setting('security.edge_cloudflare_secret_ref'),
            'مفتاحُ الحافّة يحمل ما ليس معرّفاً — خطرُ سرٍّ خامٍّ في الإعدادات');
    }
}
