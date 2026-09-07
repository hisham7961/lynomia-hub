<?php

namespace Tests\Feature;

use App\Models\AlertRule;
use App\Models\IpRule;
use App\Support\AlertEngine;
use App\Support\HubEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **التصعيدُ الآليّ + حدثُ ip_auto_blocked** (Work OS · الطور I · WP-I.2 · §41/§68).
 *
 * يمتدّ هذا الملفُّ سابقتَين معلنتَين:
 *  • `IpIntelTest` — التغذيةُ نفسُها (`access_denials` بالعنوان والزمن) وقارئُ
 *    التجميع الواحد؛ هنا يُثبَت أنّ **المُطلِقَ الآليّ يستهلك إشارةَ الرشق
 *    القائمة** في `AlertEngine::fireSource` (subject=IP بعتبةِ القاعدة) —
 *    لا محرّكَ تجميعٍ ثانٍ: بلا قاعدةِ تنبيهٍ نافذيةٍ لا إشارةَ ولا حظر.
 *  • `AlertWindowTest` — نمطُ التقييم (`evaluate(['components' => []])`)
 *    والقاعدةُ النافذية نفسُها حرفاً.
 *
 * وقواعدُ الطور I الصلبة (من المواصفة — غيرُ قابلةٍ للتفاوض):
 *  ١) **مطفأٌ افتراضياً**: `security.autoblock_enabled` غيرُ مضبوطٍ = لا قاعدةَ
 *     حظرٍ مهما بلغ الرشق — الإشارةُ تُطلق تنبيهاً ولا تحظر.
 *  ٢) **فشلٌ مفردٌ لا يحظر أبداً** ولو فُعِّل المفتاح وضُبطت العتبةُ صفراً —
 *     أرضيةُ ٢ مفروضةٌ في الشيفرة لا في الإعداد.
 *  ٣) رشقٌ فوق العتبة = قاعدةُ حظرٍ **واحدة** (origin=auto، ١٥ دقيقة) وحدثُ
 *     `ip_auto_blocked` **واحد** — الرشقُ المستمرُّ لا يُمطر أحداثاً (dedup
 *     بالقاعدة الحيّة نفسِها).
 *  ٤) العودُ يُصعِّد: ١٥د → ٦٠د → ١٤٤٠د (`escalation_level` ٠→١→٢) بسقفٍ
 *     عند أعلى درجة، وحادثةٌ آليّةٌ واحدة (بصمةً لا عنواناً) عند بلوغها.
 *  ٥) لا حظرَ آليّاً أبداً: لعنوانٍ موثوق (`security.trusted_ips` عبر
 *     المُطابِق الواحد `ip_allowed`)، ولا لعنوانٍ معروفٍ لمالكٍ (`user_ips`)،
 *     ولا لعنوانٍ تحميه قاعدةُ سماحٍ حيّة (allow يفوز دائماً).
 */
class WorkOsIpAutoBlockTest extends TestCase
{
    /** أسماءُ الأحداث الدلالية المرصودة في التشغيلة — عدّادُ «حدثٌ واحد» */
    protected array $seen = [];

    protected function tearDown(): void
    {
        // المشتركون الإضافيون static — لا يتسرّبون لاختبارٍ تالٍ في العملية نفسها
        HubEvents::forgetListeners();
        parent::tearDown();
    }

    /** يرصد كلَّ بثٍّ لحدث ip_auto_blocked (الدلاليّ لا الخام) */
    protected function watchEvents(): void
    {
        HubEvents::forgetListeners();
        HubEvents::listen(function (string $e) {
            if ($e === 'ip_auto_blocked') $this->seen[] = $e;
        });
    }

    /** لا مكوّناتِ صحّةٍ — نمطُ AlertWindowTest حرفياً */
    protected function evaluate(): array
    {
        return (new AlertEngine())->evaluate(['components' => []]);
    }

    /** قاعدةُ الرفض النافذية القائمة — الإشارةُ التي يستهلكها المُطلِقُ الآليّ */
    protected function denialRule(int $thr = 3): AlertRule
    {
        return AlertRule::create(['name' => 'رفض متكرر', 'mod' => '', 'field' => 'x', 'op' => 'يساوي',
            'val' => (string) $thr, 'status' => 'مفعّلة', 'source' => 'security.denials', 'window_min' => 60]);
    }

    /** صفوفُ منعٍ خام من عنوانٍ واحد — التغذيةُ نفسُها التي يقرؤها fireSource */
    protected function denialsFrom(string $ip, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            DB::table('access_denials')->insert(['kind' => 'وصول مرفوض', 'ip' => $ip,
                'method' => 'GET', 'path' => '/admin/x' . $i, 'created_at' => now()->subMinute()]);
        }
    }

    /** ① مطفأٌ افتراضياً: رشقٌ فوق العتبة يُطلق التنبيهَ ولا يُنشئ قاعدةَ حظر */
    public function test_autoblock_is_off_by_default_a_burst_creates_no_rule(): void
    {
        $this->seedCore();
        $rule = $this->denialRule(3);
        $this->denialsFrom('203.0.113.50', 5);
        $this->watchEvents();

        $this->evaluate();

        // الإشارةُ القائمة أُطلقت فعلاً (نستهلكها لا نعيد اشتقاقها) —
        $this->assertSame(1, DB::table('alert_instances')->where('rule_id', $rule->id)
            ->where('subject', '203.0.113.50')->count(), 'إشارةُ الرشق القائمة لم تُطلق أصلاً');
        // — لكنّ المفتاحَ مطفأٌ فلا حظرَ ولا حدث
        $this->assertSame(0, (int) DB::table('ip_rules')->count(), 'قاعدةُ حظرٍ أُنشئت والمفتاحُ مطفأ');
        $this->assertSame([], $this->seen, 'حدثُ ip_auto_blocked بُثّ والمفتاحُ مطفأ');
    }

    /** ② فشلٌ مفرد لا يحظر أبداً — ولو فُعِّل المفتاحُ وضُبطت العتبةُ صفراً */
    public function test_a_single_failure_never_creates_a_block_even_when_enabled(): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '0');   // ضبطٌ عدائيّ — الأرضية ٢ في الشيفرة
        $this->denialRule(1);                                     // القاعدةُ تُطلق على صفٍّ واحد
        $this->denialsFrom('203.0.113.51', 1);
        $this->watchEvents();

        $this->evaluate();

        $this->assertSame(0, (int) DB::table('ip_rules')->count(),
            'فشلٌ مفردٌ أنشأ حظراً — الأرضيةُ الصلبة (٢) مخترقة');
        $this->assertSame([], $this->seen);
    }

    /** ③ مفعَّلٌ + رشقٌ فوق العتبة = قاعدةٌ واحدة (١٥ دقيقة) وحدثٌ واحد — والاستمرارُ لا يُمطر */
    public function test_enabled_burst_creates_one_15min_block_and_exactly_one_event(): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        $this->denialRule(3);
        $this->denialsFrom('203.0.113.52', 5);
        $this->watchEvents();

        $this->evaluate();

        $rows = IpRule::query()->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(1, $rows, 'رشقٌ واحدٌ يجب أن يُنشئ قاعدةً واحدة');
        $r = $rows->first();
        $this->assertSame('203.0.113.52', $r->ip);
        $this->assertSame('block', $r->mode);
        $this->assertSame('auto', $r->origin);
        $this->assertSame(0, (int) $r->escalation_level, 'أوّلُ درجةٍ في السلّم صفر');
        $this->assertNotNull($r->expires_at, 'الحظرُ الآليّ مؤقّتٌ دائماً — لا دائمَ بلا إنسان');
        $this->assertEqualsWithDelta(15, now()->diffInMinutes($r->expires_at, true), 1.0,
            'الدرجةُ الأولى ١٥ دقيقة');
        $this->assertTrue($r->matches('203.0.113.52'), 'القاعدةُ المنشأة لا تُطابق عنوانَها');
        $this->assertCount(1, $this->seen, 'حدثُ ip_auto_blocked يُبثّ مرّةً واحدة عند الإنشاء');

        // الرشقُ مستمرٌّ والقاعدةُ حيّة: تقييمٌ ثانٍ لا يُنشئ ولا يبثّ — لا عاصفةَ أحداث
        $this->evaluate();
        $this->assertSame(1, (int) DB::table('ip_rules')->count(), 'الرشقُ المستمرُّ كرّر القاعدة');
        $this->assertCount(1, $this->seen, 'الرشقُ المستمرُّ أمطر أحداثاً');
    }

    /** ④ العودُ يُصعِّد ١٥←٦٠←١٤٤٠ (المستوى ٠←١←٢) بسقفٍ — وحادثةٌ واحدة عند القمة */
    public function test_repeat_offences_escalate_60_then_1440_minutes(): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        $this->denialRule(3);
        $ip = '203.0.113.53';
        $this->watchEvents();

        // الجولة الأولى — ١٥ دقيقة
        $this->denialsFrom($ip, 5);
        $this->evaluate();

        // انقضى الحظرُ الأوّل والمعتدي عاد — ٦٠ دقيقة
        $this->travel(16)->minutes();
        $this->denialsFrom($ip, 5);
        $this->evaluate();

        // انقضى الثاني وعاد — ١٤٤٠ دقيقة (القمة) + حادثةٌ آليّة
        $this->travel(61)->minutes();
        $this->denialsFrom($ip, 5);
        $this->evaluate();

        $rows = IpRule::query()->where('ip', $ip)->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(3, $rows, 'ثلاثُ جولاتٍ = ثلاثُ قواعدَ متتابعة');
        $this->assertSame([0, 1, 2], $rows->map(fn ($r) => (int) $r->escalation_level)->all(),
            'سلّمُ التصعيد ٠←١←٢ لم يُتسلَّق بالترتيب');
        $this->assertEqualsWithDelta(60, $rows[1]->created_at->diffInMinutes($rows[1]->expires_at, true), 1.0);
        $this->assertEqualsWithDelta(1440, $rows[2]->created_at->diffInMinutes($rows[2]->expires_at, true), 1.0);
        $this->assertCount(3, $this->seen, 'حدثٌ واحدٌ لكل إنشاءٍ — ثلاثةٌ لثلاث');

        // القمةُ سقفٌ لا نقطةَ انطلاقٍ لدرجةٍ رابعة — والحادثةُ واحدةٌ بالبصمة تُثرى لا تُكرَّر
        $this->travel(1441)->minutes();
        $this->denialsFrom($ip, 5);
        $this->evaluate();

        $last = IpRule::query()->where('ip', $ip)->orderBy('created_at')->orderBy('id')->get()->last();
        $this->assertSame(2, (int) $last->escalation_level, 'التصعيدُ تجاوز أعلى درجات السلّم');
        $this->assertSame(1, (int) \App\Models\Incident::query()
            ->where('meta->fingerprint', 'autoblock:' . $ip)->count(),
            'حادثةُ القمة تُفتح مرّةً واحدةً بالبصمة وتُثرى — لا تتكرّر');
    }

    /** ⑤ لا حظرَ آليّاً أبداً: موثوقٌ (CIDR/دقيق) · عنوانُ مالكٍ معروف · قاعدةُ سماحٍ حيّة */
    public function test_trusted_owner_and_allowed_ips_are_never_auto_blocked(): void
    {
        $this->seedCore();
        $this->hubSetting('security.autoblock_enabled', '1');
        $this->hubSetting('security.autoblock_threshold', '3');
        // الموثوقُ يمرّ بالمُطابِق الواحد ip_allowed: شبكةٌ CIDR وعنوانٌ دقيق
        $this->hubSetting('security.trusted_ips', '198.51.100.0/24, 203.0.113.99');
        // عنوانٌ معروفٌ للمالك (ذاكرةُ user_ips القائمة) — قناةُ تعافٍ لا هدفُ حظر
        DB::table('user_ips')->insert(['id' => (string) Str::uuid(), 'user_id' => $this->owner->id,
            'ip' => '192.0.2.10', 'hits' => 7, 'last_seen_at' => now()]);
        // وقاعدةُ سماحٍ حيّةٌ تحمي عنواناً — allow يفوز block دائماً
        IpRule::create(['ip' => '203.0.113.70', 'mode' => 'allow', 'origin' => 'manual',
            'reason' => 'سماحٌ صريح']);

        $this->denialRule(3);
        foreach (['198.51.100.77', '203.0.113.99', '192.0.2.10', '203.0.113.70'] as $shielded) {
            $this->denialsFrom($shielded, 5);
        }
        $this->denialsFrom('203.0.113.60', 5);   // شاهدُ الضبط: هذا وحدَه يُحظر
        $this->watchEvents();

        $this->evaluate();

        $blocks = IpRule::query()->where('mode', 'block')->orderBy('created_at')->orderBy('id')->get();
        $this->assertSame(['203.0.113.60'], $blocks->pluck('ip')->all(),
            'حُظر عنوانٌ محميّ (موثوق/مالك/سماح) أو أفلت الشاهد');
        $this->assertCount(1, $this->seen, 'حدثٌ للشاهد وحده');
    }
}
