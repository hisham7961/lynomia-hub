<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * WP-10.3 (spec §10) — **البحثُ يفهم ما في يدك**.
 *
 * في يد المشغّل أشياءُ تشغيلٍ لا أسماء: معرّفُ طلبٍ في ترويسة، بصمةُ خطأ في
 * تنبيه، عنوانُ IP في بلاغ، مفتاحُ إعدادٍ في كتيّب، بريدُ موظّف. كلُّها اليوم
 * تُلصَق في البحث فلا تجد شيئاً لأن البحثَ يبحث في السجلات بالاسم.
 *
 * وشرطُ القبول الثاني أهمُّ من الأول: **لا يزداد عبءُ البحث العاديّ بحرف**.
 * كلُّ مطابقةٍ هنا خلفَ **نمطٍ في النصّ** — ونصٌّ عاديّ لا يشبه معرّفاً ولا
 * عنواناً ولا مفتاحاً يخرج من هذا الطريق بلا استعلامٍ واحدٍ زائد (ضغطةُ المفتاح
 * تكلّف ٨١ استعلام LIKE سلفاً، فلا يُضاف إليها).
 */
class OperationalSearchTest extends TestCase
{
    /**
     * ميزانيةُ استعلامات البحث الحيّ لنصٍّ عاديّ لا يطابق شيئاً — **مقيسةٌ على
     * الحزمة قبل هذه الدفعة وبعدها فكانت هي هي**: ٨٨ على SQLite و٨٧ على MySQL.
     * والفرقُ بين المحرّكين سابقٌ لهذه الدفعة (قيس بالشيفرة القديمة نفسِها)، ولذا
     * يُثبَّت لكلِّ محرّكٍ رقمُه لا سقفٌ متساهلٌ يبتلع زيادةً على أحدهما. أيُّ
     * زيادةٍ هنا تعني أنّ مطابقةً تشغيليةً تسرّبت خارجَ نمطها إلى كل ضغطة مفتاح.
     */
    // +1 لكل محرّك مع وحدة `stations` القابلة للبحث (Work OS · الطور F · WP-F.1)،
    // و+1 أخرى مع وحدة `carriers` (Work OS · الطور G · WP-G.2): البحثُ العاديّ يستعلم
    // مرّةً واحدةً لكلّ وحدةٍ قابلةٍ للبحث — ووحدةٌ جديدةٌ = استعلامٌ واحدٌ متوقَّع، لا
    // تسرّبٌ (حرّاسُ error_events/email أعلاه بلا مساس، والزيادةُ نمطُ LIKE للاسم لا استجوابٌ تشغيليّ).
    // و+3 مع وسيط الدفاع التكيّفي `IpDefense` (Work OS · الطور I · WP-I.3): تحميلُ
    // مجموعة القواعد **عند برد الخبيئة فقط** (hasTable + تقليمٌ + جلبُ الحيّة —
    // ٣٠ ثانية TTL في الإنتاج، وخبيئةُ الاختبار array باردةٌ لكل حالة فتُقاس حتماً).
    // ليست كلفةَ ضغطةِ مفتاحٍ — كلفةُ إعادةِ تحميلٍ مُخبّأة، وحرّاسُ التسرّب أعلاه بلا مساس.
    private const PLAIN_BUDGET = ['sqlite' => 93, 'mysql' => 92, 'mariadb' => 92];

    private const RID = '0198f0c2-77aa-4a11-9a1e-5f4d2b7c1e33';
    private const IP = '203.0.113.9';
    private const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function persona(string $name, array $flags): User
    {
        $role = Role::create(['name' => $name, 'scope' => 'all', 'flags' => $flags, 'matrix' => []]);

        return User::create(['name' => $name, 'email' => $name . '@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);
    }

    private function seedError(): ErrorEvent
    {
        return ErrorEvent::create(['hash' => self::HASH, 'kind' => 'php',
            'message' => 'عطلٌ للاختبار', 'file' => 'app/X.php', 'line' => 7,
            'count' => 3, 'status' => 'جديد', 'first_seen' => now()->subDay(), 'last_seen' => now()]);
    }

    private function mini(User $u, string $q): string
    {
        $html = $this->actingAs($u)->get('/search/mini?q=' . rawurlencode($q))->assertOk()->getContent();
        auth()->logout();

        return $html;
    }

    /* ───────────── ١) المالكُ يجد ما في يده ───────────── */

    public function test_owner_reaches_the_request_trace_by_a_request_id(): void
    {
        $this->seedCore();
        $this->assertStringContainsString(route('system.trace', self::RID),
            $this->mini($this->owner, self::RID), 'معرّفُ الطلب لا يوصل إلى أثره');
    }

    public function test_owner_reaches_an_error_by_its_id_and_by_its_fingerprint(): void
    {
        $this->seedCore();
        $e = $this->seedError();

        $this->assertStringContainsString(route('errors.show', $e->id),
            $this->mini($this->owner, self::HASH), 'بصمةُ الخطأ لا توصل إليه');
        $this->assertStringContainsString(route('errors.show', $e->id),
            $this->mini($this->owner, $e->id), 'معرّفُ الخطأ لا يوصل إليه');

        // وبصمةٌ لا صفَّ لها لا تختلق رابطاً
        $this->assertStringNotContainsString('/admin/errors/',
            $this->mini($this->owner, str_repeat('b', 64)), 'رابطُ خطأٍ لا وجودَ له');
    }

    public function test_owner_reaches_audit_and_ip_intel_by_an_address(): void
    {
        $this->seedCore();
        $html = $this->mini($this->owner, self::IP);

        $this->assertStringContainsString(route('audit.index', ['ip' => self::IP]), $html);
        $this->assertStringContainsString(route('security.ip', self::IP), $html);
    }

    public function test_owner_reaches_a_settings_key_by_its_key(): void
    {
        $this->seedCore();
        $html = $this->mini($this->owner, 'app.name');

        $this->assertStringContainsString(route('settings.edit') . '#app.name', $html);

        // ومفتاحٌ ليس في الكتالوج لا يُوعَد بشاشةٍ لا تعرفه
        $this->assertStringNotContainsString(route('settings.edit') . '#',
            $this->mini($this->owner, 'nope.not_a_key'));
    }

    public function test_a_user_admin_reaches_the_account_by_its_email(): void
    {
        $this->seedCore();
        $html = $this->mini($this->owner, $this->employee->email);
        $this->assertStringContainsString(route('users.edit', $this->employee->id), $html);

        // بريدٌ لا حسابَ له لا يفتح شاشةَ تحرير
        $this->assertStringNotContainsString('/edit',
            $this->mini($this->owner, 'ghost@test.local'));
    }

    /* ───────────── ٢) كلٌّ بحارسه ───────────── */

    public function test_the_employee_finds_none_of_the_operational_matches(): void
    {
        $this->seedCore();
        $e = $this->seedError();

        foreach ([self::RID, self::HASH, $e->id, self::IP, 'app.name', $this->owner->email] as $q) {
            $html = $this->mini($this->employee, $q);
            foreach ([route('system.trace', self::RID), route('errors.show', $e->id),
                      route('security.ip', self::IP), route('audit.index', ['ip' => self::IP]),
                      route('settings.edit') . '#app.name', route('users.edit', $this->owner->id)] as $url) {
                $this->assertStringNotContainsString($url, $html,
                    "الموظّفةُ وصلت إلى $url بالبحث عن «{$q}»");
            }
        }
    }

    public function test_the_auditor_gets_the_trace_and_the_address_but_not_the_rest(): void
    {
        $this->seedCore();
        $auditor = $this->persona('مدقق', ['audit' => 1]);
        $e = $this->seedError();

        $this->assertStringContainsString(route('system.trace', self::RID),
            $this->mini($auditor, self::RID), 'حاملُ رايةِ التدقيق لا يصل إلى أثر الطلب');
        $this->assertStringContainsString(route('audit.index', ['ip' => self::IP]),
            $this->mini($auditor, self::IP));

        // ولا يتعدّى ذلك: الأخطاءُ والإعداداتُ والحساباتُ ليست له
        $this->assertStringNotContainsString(route('security.ip', self::IP), $this->mini($auditor, self::IP));
        $this->assertStringNotContainsString(route('errors.show', $e->id), $this->mini($auditor, self::HASH));
        $this->assertStringNotContainsString(route('settings.edit') . '#app.name',
            $this->mini($auditor, 'app.name'));
        $this->assertStringNotContainsString(route('users.edit', $this->employee->id),
            $this->mini($auditor, $this->employee->email));
    }

    /* ───────────── ٣) الميزانية: نصٌّ عاديّ لا يكلّف استعلاماً زائداً ───────────── */

    public function test_a_plain_text_query_costs_not_one_extra_query(): void
    {
        $this->seedCore();
        $this->seedError();
        $this->actingAs($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/search/mini?q=' . rawurlencode('زقزقةٌ لا تطابق شيئاً'))->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = str_replace(['`', '"'], '', implode(' | ', array_column($log, 'query')));
        $this->assertStringNotContainsString('error_events', $sql,
            'نصٌّ عاديّ يستجوب جدولَ الأخطاء في كل ضغطة مفتاح');
        $this->assertStringNotContainsString('email =', $sql,
            'نصٌّ عاديّ يبحث عن حسابٍ بالبريد في كل ضغطة مفتاح');
        $driver = DB::connection()->getDriverName();
        $this->assertArrayHasKey($driver, self::PLAIN_BUDGET, "لا ميزانيةَ مقيسةٌ لمحرّك $driver");
        $this->assertSame(self::PLAIN_BUDGET[$driver], count($log),
            'ميزانيةُ استعلامات البحث العاديّ تغيّرت — المطابقاتُ التشغيلية تسرّبت خارجَ أنماطها');
    }
}
