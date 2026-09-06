<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\IdentityRisk;
use App\Support\Risk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.3 — محرّكُ خطر الهويّة (IdentityRisk) وشاشتُه `security.identity`.
 *
 * القواعد المُثبَتة هنا:
 *  - كلُّ عاملٍ في المواصفة يظهر باسمِه العربيّ ونقاطِه — تفسيرٌ لا صندوقٌ أسود.
 *  - فشلُ الدخول/التحقق يُقرأ بمفردات SecurityEvents (AUTH_FAILURE|MFA_FAILURE)
 *    لا بقوائمَ حرفيةٍ متباعدة — صيغةُ QuoteFlow تُحتسب كغيرها.
 *  - ميزانيةُ استعلامات ثابتة: ٦ تجميعاتٍ (GROUP BY user_id) لا حلقةَ لكل مستخدم.
 *  - اختبارُ التسريب الصريح (critic #9): monitor منطَّقٌ بالشركة ومطموسُ البريد
 *    والعنوان؛ المالكُ كامل؛ الموظفُ بلا علمٍ ٤٠٣؛ ولا hash كلمةِ مرورٍ ولا سرَّ
 *    TOTP في أي ردّ.
 */
class IdentityRiskTest extends TestCase
{
    /** دورٌ + مستخدمٌ بمواصفاتٍ دقيقة — عُدّةُ بناء السيناريوهات */
    protected function makeUser(array $attrs = [], array $flags = [], string $scope = 'all',
                                bool $owner = false, array $matrix = []): User
    {
        $role = Role::create(['name' => 'دور ' . Str::random(6), 'is_owner' => $owner,
            'scope' => $scope, 'flags' => $flags, 'matrix' => $matrix]);

        return User::create($attrs + [
            'name' => 'مستخدم ' . Str::random(6),
            'email' => Str::random(10) . '@test.local',
            'password' => 'Secret!2026x',
            'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'last_login_at' => now(),
        ]);
    }

    /** مستخدمٌ بعلم monitor وحدَه (غيرُ مالك) — بشركاتٍ محدَّدة اختيارياً */
    protected function monitorUser(array $companies = []): User
    {
        return $this->makeUser([
            'name' => 'المراقب', 'email' => 'mon@test.local', 'companies' => $companies,
        ], ['monitor' => 1]);
    }

    /** كلُّ عاملٍ من عوامل المواصفة يظهر بنقاطه وتسميته العربية — والدرجةُ من سلّم Risk::bands */
    public function test_every_factor_appears_with_points_and_arabic_labels(): void
    {
        $this->seedCore();

        // حسابٌ يُشعل كلَّ عوامل الخطر عمداً: صلاحياتٌ حسّاسة + نطاقٌ شامل + بلا حصرِ
        // شركات + بلا MFA + خمولٌ +٩٠ + كلمةٌ بائتة + فشلُ دخولٍ + منعُ وصولٍ +
        // أجهزةٌ مُبطَلة بلا موثَّق + عناوينُ كثيرة + جلساتٌ حيّةٌ متزامنة
        $u = $this->makeUser([
            'name' => 'المكشوف', 'totp_enabled' => 0, 'companies' => [],
            'last_login_at' => now()->subDays(120),
            'password_changed_at' => now()->subDays(400),
        ], ['users' => 1, 'secrets' => 1], 'all');

        foreach (range(1, 3) as $i) {
            DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
                'started_at' => now()->subHours(2), 'last_seen_at' => now()->subMinutes(5), 'revoked' => false]);
        }
        DB::table('user_devices')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $u->id, 'cookie_hash' => hash('sha256', 'd1'),
             'trust' => 'مبطَل', 'created_at' => now(), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'user_id' => $u->id, 'cookie_hash' => hash('sha256', 'd2'),
             'trust' => 'معلّق', 'created_at' => now(), 'updated_at' => now()],
        ]);
        foreach (range(1, 7) as $i) {
            DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
                'ip' => "198.51.100.{$i}", 'hits' => 2, 'last_seen_at' => now()]);
        }
        foreach (range(1, 4) as $i) {
            DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'user_id' => $u->id,
                'ip' => '198.51.100.9', 'method' => 'GET', 'path' => '/admin/x', 'created_at' => now()->subDays(2)]);
        }
        foreach (range(1, 3) as $i) {
            DB::table('audits')->insert(['user_id' => $u->id, 'action' => 'دخول فاشل',
                'created_at' => now()->subDays(1)]);
        }
        DB::table('audits')->insert(['user_id' => $u->id, 'action' => 'فشل رمز التحقق',
            'created_at' => now()->subDays(1)]);

        $rows = IdentityRisk::map();
        $row = collect($rows)->firstWhere('id', $u->id);
        $this->assertNotNull($row, 'المستخدمُ المكشوف لم يظهر في خريطة الهويّة');

        $have = collect($row['factors'])->keyBy('label');
        $expected = [
            'صلاحياتٌ حسّاسة: إدارة المستخدمين، كشف أسرار الخزنة' => 20,
            'نطاقٌ شامل — كل الشركات'                              => 10,
            'وصولٌ واسع — بلا حصرِ شركات'                          => 8,
            'مميّزٌ بلا تحقّقٍ بخطوتين'                            => 30,
            'خمولٌ يتجاوز 90 يوماً'                                 => 20,
            'كلمةُ مرورٍ لم تُجدَّد منذ سنة'                        => 10,
            'محاولاتُ دخولٍ أو تحقّقٍ فاشلة (٣٠ يوماً): 4'          => 16,
            'وصولٌ مرفوضٌ مسجَّل (٣٠ يوماً): 4'                     => 8,
            'أجهزةٌ سبق إبطالُها: 1'                                => 8,
            'لا جهازَ موثَّقاً بين أجهزته'                          => 6,
            'عناوينُ شبكةٍ متعدّدة: 7'                              => 7,
            'جلساتٌ حيّةٌ متزامنة: 3'                               => 5,
        ];
        foreach ($expected as $label => $points) {
            $this->assertTrue($have->has($label), "العاملُ «{$label}» غائب — الموجود: " . $have->keys()->implode(' | '));
            $this->assertSame($points, $have[$label]['points'], "نقاطُ «{$label}» لا تطابق");
        }

        // الدرجةُ مقصوصةٌ عند ١٠٠ والنطاقُ من سلّم Risk::bands نفسِه — لا سلّمَ ثانياً
        $this->assertSame(100, $row['score']);
        $this->assertSame(Risk::band($row['score']), $row['band']);
        $this->assertSame('حرج', $row['band']);

        // والمالكُ يظهر بعاملِه، والمُخفِّفاتُ (MFA + مفتاح مرور) نقاطٌ سالبةٌ ظاهرة
        DB::table('webauthn_credentials')->insert(['id' => (string) Str::uuid(),
            'user_id' => $this->owner->id, 'credential_id' => 'cred-' . Str::random(8),
            'public_key' => 'pem', 'created_at' => now(), 'updated_at' => now()]);
        $this->owner->update(['totp_enabled' => 1, 'last_login_at' => now()]);

        $ownerRow = collect(IdentityRisk::map())->firstWhere('id', $this->owner->id);
        $oHave = collect($ownerRow['factors'])->keyBy('label');
        $this->assertSame(25, $oHave['مالكُ النظام — وصولٌ كامل']['points'] ?? null);
        $this->assertSame(-10, $oHave['مخفِّف: تحقّقٌ بخطوتين مفعَّل']['points'] ?? null);
        $this->assertSame(-8, $oHave['مخفِّف: مفتاحُ مرورٍ مسجَّل']['points'] ?? null);
    }

    /** فشلُ الدخول بمفردات SecurityEvents — صيغةُ QuoteFlow التاريخية تُحتسب كغيرها */
    public function test_failed_logins_use_the_security_events_vocabulary(): void
    {
        $this->seedCore();
        $u = $this->makeUser(['name' => 'مجرَّب عليه']);
        DB::table('audits')->insert(['user_id' => $u->id, 'action' => 'محاولة دخول QuoteFlow فاشلة',
            'created_at' => now()->subDays(3)]);

        $row = collect(IdentityRisk::map())->firstWhere('id', $u->id);
        $labels = collect($row['factors'])->pluck('label');
        $this->assertTrue($labels->contains('محاولاتُ دخولٍ أو تحقّقٍ فاشلة (٣٠ يوماً): 1'),
            'صيغةُ QuoteFlow لم تُحتسب — القارئُ لا يستعمل مفردات SecurityEvents');
    }

    /** ميزانيةُ الاستعلامات ثابتة: مضاعفةُ المستخدمين لا تضيف استعلاماً واحداً */
    public function test_query_budget_is_flat_no_per_user_loop(): void
    {
        $this->seedCore();
        $seed = function (int $n) {
            foreach (range(1, $n) as $i) {
                $u = $this->makeUser([]);
                DB::table('sessions_log')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
                    'started_at' => now(), 'last_seen_at' => now(), 'revoked' => false]);
                DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
                    'ip' => "203.0.113.{$i}", 'hits' => 1, 'last_seen_at' => now()]);
                DB::table('audits')->insert(['user_id' => $u->id, 'action' => 'دخول فاشل',
                    'created_at' => now()]);
            }
        };

        $seed(5);
        IdentityRisk::map();            // تسخينُ الخبيئات (الإعدادات وغيرها) خارج القياس

        DB::enableQueryLog();
        IdentityRisk::map();
        $n1 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $seed(15);                       // ثلاثةُ أضعاف المستخدمين
        DB::flushQueryLog();
        DB::enableQueryLog();
        IdentityRisk::map();
        $n2 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($n1, $n2, "عددُ الاستعلامات نما مع المستخدمين ({$n1} ⇒ {$n2}) — حلقةٌ لكل مستخدم");
        $this->assertLessThanOrEqual(16, $n1, "ميزانيةُ الاستعلامات مُتجاوَزة: {$n1}");
    }

    /**
     * اختبارُ التسريب الصريح (critic #9): monitor منطَّقٌ بالشركة ومطموسُ البريد
     * والعنوان؛ المالكُ يقرأ البريدَ كاملاً؛ الموظفُ بلا علمٍ يُصَدّ ٤٠٣.
     */
    public function test_identity_leak_monitor_masked_and_scoped_owner_full_employee_forbidden(): void
    {
        $this->seedCore();
        $co = (string) Str::uuid();
        $other = (string) Str::uuid();
        DB::table('companies')->insert([
            ['id' => $co, 'name_ar' => 'شركة المراقب', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $other, 'name_ar' => 'شركة أخرى', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $inCo = $this->makeUser(['name' => 'موظف الشركة الأولى', 'email' => 'target-a@corp.example',
            'companies' => [$co]]);
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $inCo->id,
            'ip' => '203.0.113.77', 'hits' => 9, 'last_seen_at' => now()]);
        $this->makeUser(['name' => 'موظف الشركة الثانية', 'email' => 'target-b@corp.example',
            'companies' => [$other]]);

        $monitor = $this->monitorUser([$co]);

        // الموظفُ بلا علمٍ يُصَدّ عن الشاشتين
        $this->actingAs($this->employee)->get('/admin/security/identity')->assertForbidden();

        // المراقبُ المنطَّق: يرى مستخدمَ شركتِه بلا بريدٍ ولا عنوانٍ صريحَين —
        // ولا يرى مستخدمَ الشركةِ الأخرى أصلاً
        $resp = $this->actingAs($monitor)->get('/admin/security/identity');
        $resp->assertOk()->assertSee('موظف الشركة الأولى');
        $resp->assertDontSee('target-a@corp.example');
        $resp->assertDontSee('موظف الشركة الثانية');
        $resp->assertDontSee('target-b@corp.example');
        $this->assertStringNotContainsString('203.0.113.77', $resp->getContent(),
            'عنوانُ شبكة المستخدم تسرّب لقارئ المراقبة');

        // المالكُ يقرأ كاملاً — البريدُ ظاهرٌ والمستخدمان كلاهما
        $full = $this->actingAs($this->owner)->get('/admin/security/identity');
        $full->assertOk()->assertSee('target-a@corp.example')->assertSee('موظف الشركة الثانية');
    }

    /** لا hash كلمةِ مرور ولا سرَّ TOTP ولا رمزَ تذكُّرٍ في ردّ الشاشتين — لأي قارئ */
    public function test_no_password_hash_or_totp_secret_in_responses(): void
    {
        $this->seedCore();
        $u = $this->makeUser(['name' => 'صاحب الأسرار', 'totp_enabled' => 1], ['secrets' => 1]);
        // الكتابةُ عبر DB مباشرةً تتجاوز التشفير — القيمةُ المخزَّنة هي الكناري نفسُها
        DB::table('users')->where('id', $u->id)->update([
            'totp_secret_cipher' => 'TOTP-CANARY-JBSWY3DPEHPK3PXP',
            'remember_token' => 'REMEMBER-CANARY-1234567890',
        ]);
        $hash = (string) DB::table('users')->where('id', $u->id)->value('password');
        $this->assertNotSame('', $hash);

        foreach (['/admin/security/identity', '/admin/security/privileged'] as $url) {
            $body = $this->actingAs($this->owner)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString($hash, $body, "hash كلمةِ المرور تسرّب في {$url}");
            $this->assertStringNotContainsString('TOTP-CANARY-JBSWY3DPEHPK3PXP', $body, "سرُّ TOTP تسرّب في {$url}");
            $this->assertStringNotContainsString('REMEMBER-CANARY-1234567890', $body, "رمزُ التذكُّر تسرّب في {$url}");
        }
    }
}
