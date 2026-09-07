<?php

namespace Tests\Feature;

use App\Models\IpRule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **مخزنُ قواعد IP (ip_rules) — Eloquent Model فوق المُطابِق الواحد**
 * (Work OS · الطور I · WP-I.1 · §41).
 *
 * يمتدّ هذا الملفُّ سابقتَين معلنتَين:
 *  • `SupportTest::test_ip_allowed_is_exact_unless_explicit_wildcard` — حالاتُ
 *    المُطابِق الواحد (`ip_allowed` · helpers:27): دقيقٌ لا بادئة، CIDR رابعة
 *    وسادسة، اختلافُ عائلةٍ لا يرمي. `IpRule::matches` **يفوّض** إليه — فتُثبَت
 *    المساواةُ الحرفيّة مع `ip_allowed` على كل حالة (لا مُحلِّلَ ثانٍ يفترق عنه).
 *  • `ColumnFitsItsWriterTest` — العمودُ يسع ما يُكتب فيه على صرامة MySQL:
 *    `ip` يسع أطولَ IPv6 نصّاً + `/128`، والكاتبُ يقصّ `reason` بـ`mb_substr`
 *    (درسُ `notifications_hub.kind`: SQLite تمرّر والإنتاجُ يرمي).
 *
 * وقواعدُ الطور I الصلبة تبدأ هنا: قاعدةٌ منتهيةٌ (`expires_at` ماضٍ) تتوقف عن
 * المطابقة **فوراً**، وملغاةٌ (`revoked_at`) كذلك — لأنّ `IpDefense` (WP-I.3)
 * سيبني على `matches`/`scopeActive` وحدهما، وأيُّ تراخٍ هنا حظرٌ شبح.
 * mode/origin قائمتا سماحٍ في التطبيق لا `DB enum` (درسُ C10).
 */
class WorkOsIpRulesTest extends TestCase
{
    /** ① `matches` يفوّض للمُطابِق الواحد — مساواةٌ حرفيّةٌ مع `ip_allowed` على كل حالة */
    public function test_matches_delegates_to_the_one_matcher_for_v4_v6_and_cidr(): void
    {
        $cases = [
            // [قاعدة، عنوان، المتوقَّع] — نفسُ حالات SupportTest حرفيّاً
            ['203.0.113.7',     '203.0.113.7',   true,  'المطابقةُ الدقيقة تعمل'],
            ['203.0.113.7',     '203.0.113.70',  false, 'قاعدةٌ دقيقة لا تطابق بالبادئة'],
            ['192.168.1.0/24',  '192.168.1.50',  true,  'CIDR رابعة داخل الشبكة'],
            ['192.168.1.0/24',  '192.168.2.50',  false, 'CIDR رابعة خارج الشبكة'],
            ['2001:db8::/32',   '2001:db8::1',   true,  'IPv6 داخل الشبكة'],
            ['2001:db8::/32',   '2001:dead::1',  false, 'IPv6 خارج الشبكة'],
            ['2001:db8::/32',   '10.0.0.5',      false, 'عائلةٌ مختلفة لا تُطابِق ولا تسقط'],
        ];

        foreach ($cases as $i => [$rule, $ip, $want, $why]) {
            $r = IpRule::create(['ip' => $rule, 'mode' => 'block', 'origin' => 'manual',
                'reason' => 'اختبار ' . $i]);

            $this->assertSame($want, $r->matches($ip), "IpRule::matches: {$why}");
            // جوهرُ WP-I.1: لا مُحلِّلَ ثانٍ — الموديل يساوي ip_allowed حرفيّاً
            $this->assertSame(ip_allowed($ip, $rule), $r->matches($ip),
                "IpRule::matches افترق عن المُطابِق الواحد ip_allowed: {$why}");
            // is_cidr يُشتقّ من القاعدة نفسِها لا من ادّعاء النموذج
            $this->assertSame(str_contains($rule, '/'), $r->is_cidr, 'is_cidr لا يطابق شكلَ القاعدة');
        }
    }

    /** ② القاعدةُ المنتهية تتوقف عن المطابقة فوراً — وnull = دائمةٌ لا تنتهي */
    public function test_an_expired_rule_stops_matching_immediately(): void
    {
        $expired = IpRule::create(['ip' => '192.168.1.0/24', 'mode' => 'block', 'origin' => 'manual',
            'expires_at' => now()->subMinute()]);
        $forever = IpRule::create(['ip' => '192.168.1.0/24', 'mode' => 'block', 'origin' => 'manual',
            'expires_at' => null]);
        $future = IpRule::create(['ip' => '192.168.1.0/24', 'mode' => 'block', 'origin' => 'manual',
            'expires_at' => now()->addHour()]);

        $this->assertFalse($expired->matches('192.168.1.50'), 'قاعدةٌ منتهية ما زالت تُطابِق — حظرٌ شبح');
        $this->assertTrue($forever->matches('192.168.1.50'), 'null = دائمة — يجب أن تُطابِق');
        $this->assertTrue($future->matches('192.168.1.50'), 'قاعدةٌ لمّا تنتهِ بعد يجب أن تُطابِق');

        // scopeActive يستبعد المنتهيةَ ويُبقي الدائمةَ والحيّة — كلُّ الصفوف تُفحَص
        // لا واحدٌ بالقرعة (درسُ الترتيب في CLAUDE.md)، والترتيبُ حتميٌّ بالمعرّف
        $active = IpRule::query()->active()->orderBy('id')->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$forever->id, $future->id], $active,
            'scopeActive أعاد غيرَ [الدائمة، المستقبلية] بالضبط');
    }

    /** ③ القاعدةُ الملغاة (revoked_at) تتوقف عن المطابقة ويستبعدها scopeActive */
    public function test_a_revoked_rule_stops_matching(): void
    {
        $revoked = IpRule::create(['ip' => '203.0.113.7', 'mode' => 'block', 'origin' => 'manual',
            'revoked_at' => now(), 'revoked_by' => null]);
        $live = IpRule::create(['ip' => '203.0.113.7', 'mode' => 'block', 'origin' => 'manual']);

        $this->assertFalse($revoked->matches('203.0.113.7'), 'قاعدةٌ ملغاة ما زالت تُطابِق');
        $this->assertTrue($live->matches('203.0.113.7'), 'القاعدةُ الحيّة يجب أن تُطابِق');

        $active = IpRule::query()->active()->orderBy('id')->pluck('id')->all();
        $this->assertSame([$live->id], $active, 'scopeActive لم يستبعد الملغاةَ وحدَها');
    }

    /** ④ mode/origin قائمتا سماحٍ في التطبيق — قيمةٌ خارجهما تُرمى ولا يُكتب صفٌّ صامت */
    public function test_mode_and_origin_allowlists_reject_off_list_values(): void
    {
        foreach ([['mode' => 'deny', 'origin' => 'manual'],       // mode خارج القائمة
                  ['mode' => 'block', 'origin' => 'system']] as $bad) { // origin خارج القائمة
            try {
                IpRule::create(array_merge(['ip' => '203.0.113.7'], $bad));
                $this->fail('قيمةٌ خارج القائمة كُتبت صامتةً: ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
            } catch (\InvalidArgumentException $e) {
                // المتوقَّع — الرفض قبل الكتابة
            }
        }
        $this->assertSame(0, (int) DB::table('ip_rules')->count(), 'صفٌّ تسرّب رغم الرفض');

        // والقيمُ المشروعة كلُّها تمرّ (لا قائمةَ أضيقَ من العقد)
        foreach ([['block', 'manual'], ['allow', 'manual'], ['block', 'auto'], ['allow', 'auto']] as [$m, $o]) {
            IpRule::create(['ip' => '198.51.100.1', 'mode' => $m, 'origin' => $o]);
        }
        $this->assertSame(4, (int) DB::table('ip_rules')->count());
    }

    /**
     * ⑤ العمودُ يسع ما يُكتب فيه على صرامة MySQL (يمتدّ ColumnFitsItsWriterTest):
     * `ip` يسع أطولَ IPv6 نصّاً + `/128`، والقيمُ المعجميّةُ تسعها أعمدتُها،
     * و`request_id` يسع ما يكتبه `Api::requestId` (uuid ٣٦ ≤ ٦٤).
     */
    public function test_declared_widths_fit_their_writers_on_mysql_strict(): void
    {
        // أطولُ IPv6 نصّاً (٤٥ حرفاً: mapped-IPv4 كامل) + '/128'
        $longestV6 = '0000:0000:0000:0000:0000:ffff:255.255.255.255/128';
        $this->assertGreaterThanOrEqual(mb_strlen($longestV6), hub_col_max('ip_rules', 'ip'),
            'ip_rules.ip أضيقُ من أطول IPv6+CIDR نصّاً');

        foreach (IpRule::MODES as $v) {
            $this->assertGreaterThanOrEqual(mb_strlen($v), hub_col_max('ip_rules', 'mode'),
                "ip_rules.mode أضيقُ من القيمة المشروعة '{$v}'");
        }
        foreach (IpRule::ORIGINS as $v) {
            $this->assertGreaterThanOrEqual(mb_strlen($v), hub_col_max('ip_rules', 'origin'),
                "ip_rules.origin أضيقُ من القيمة المشروعة '{$v}'");
        }
        $this->assertGreaterThanOrEqual(36, hub_col_max('ip_rules', 'request_id'),
            'ip_rules.request_id أضيقُ من uuid الذي يكتبه Api::requestId');

        // والقاعدةُ الأطول تمرّ فعلاً كتابةً وقراءة (لا اكتفاءً بالخريطة)
        $r = IpRule::create(['ip' => $longestV6, 'mode' => 'block', 'origin' => 'auto']);
        $this->assertSame($longestV6, $r->fresh()->ip);
    }

    /** ⑥ `reason` يقصّه الكاتبُ بـmb_substr عند ٤٠٠ — SQLite تمرّر والإنتاجُ يرمي */
    public function test_reason_is_clipped_by_its_writer_with_mb_substr(): void
    {
        $long = str_repeat('سببٌ طويلٌ جداً ', 40);   // > ٤٠٠ حرفاً بأحرفٍ عربية متعددةِ البايتات
        $r = IpRule::create(['ip' => '203.0.113.7', 'mode' => 'block', 'origin' => 'manual',
            'reason' => $long]);

        $stored = (string) DB::table('ip_rules')->where('id', $r->id)->value('reason');
        $this->assertLessThanOrEqual(400, mb_strlen($stored),
            'السببُ تجاوز عرضَ عموده — يمرّ على SQLite ويرمي على MySQL');
        $this->assertSame(mb_substr($long, 0, 400), $stored, 'القصُّ عند الكاتب بـmb_substr لا بترُ بايتات');
    }

    /** ⑦ ip_rules ضمن النسخة الاحتياطية الخام — الاستعادةُ لا تُعيد النظامَ بلا سياج */
    public function test_ip_rules_is_covered_by_the_raw_backup(): void
    {
        $ref = new \ReflectionClassConstant(\App\Console\Commands\HubBackup::class, 'RAW_TABLES');
        $this->assertContains('ip_rules', $ref->getValue(),
            'ip_rules خارج HubBackup::RAW_TABLES — استعادةٌ تُسقط قواعدَ الحظر/السماح كلَّها');
    }
}
