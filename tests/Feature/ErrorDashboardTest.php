<?php

namespace Tests\Feature;

use App\Models\ErrorEvent;
use App\Models\User;
use App\Support\TimeRange;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * (WP-3.4) لوحةُ الأخطاء التنفيذية + التفصيل + الفتات:
 *
 *   — **تثبيتُ الأرقام قبل التوحيد**: خمسُ نسخٍ متباعدة كانت تعدّ «وضع الأخطاء»
 *     (مركزُ الأخطاء، OpsController، MorningController، Health::errors، النبض).
 *     قبل جمعها في قارئٍ واحد (`ErrorStats`) تُثبَّت مخرجاتُ المستهلكين حرفياً —
 *     فالتوحيدُ إصلاحُ بنيةٍ لا تغييرُ دلالة.
 *   — بطاقةُ «حرجة» تطابق دلالةَ الصحّة: المحلولُ يسقط والمتجاهَلُ **يبقى** —
 *     إخفاءُ عطلٍ حرج قرارُ عرضٍ لا شفاء.
 *   — رسمُ «الأخطاء عبر الزمن» من عيّنات الوقوع الحقيقية، لا من `last_seen`
 *     الذي ينسب عدَّ خمسين وقوعاً لساعةٍ واحدة (كما يفعل SysMonitor::pulse).
 *   — الفتاتُ آمنة: زياراتُ المستخدم المتأثّر وحدَه وقبل لحظة الوقوع فقط.
 *   — كلفةُ صفحة التفصيل لا تنمو مع عدد المستخدمين (whereIn على المعروض
 *     لا User::pluck للجدول كلّه).
 */
class ErrorDashboardTest extends TestCase
{
    protected function err(array $extra = []): ErrorEvent
    {
        return ErrorEvent::create(array_merge([
            'hash' => hash('sha256', uniqid('', true)), 'kind' => 'php',
            'message' => 'عطل اختباري في لوحة الأخطاء',
            'file' => base_path('app/Support/helpers.php'), 'line' => 42,
            'url' => 'https://hub.test/m/fin/list', 'method' => 'GET',
            'count' => 1, 'status' => 'جديد',
            'category' => 'APPLICATION', 'severity' => 'ERROR',
            'first_seen' => now()->subDays(3), 'last_seen' => now(),
        ], $extra));
    }

    /** صفُّ وقوعٍ خام في جدول العيّنات */
    protected function occurrence(ErrorEvent $e, array $extra = []): void
    {
        DB::table('error_occurrences')->insert(array_merge([
            'error_event_id' => $e->id, 'occurred_at' => now(),
            'request_id' => null, 'user_id' => null, 'route' => 'm.index',
            'url' => 'https://hub.test/m/fin/list', 'method' => 'GET',
            'release' => '2.402.0', 'status_code' => 500, 'duration_ms' => 120,
        ], $extra));
    }

    /** @return array{0:int,1:array<string>} عددُ الاستعلامات ونصوصُها (نمط OpsBudgetTest) */
    protected function counted(\Closure $fn): array
    {
        $count = false;
        $sqls = [];
        DB::listen(function ($q) use (&$sqls, &$count) { if ($count) $sqls[] = $q->sql; });
        $count = true;
        $fn();
        $count = false;

        return [count($sqls), $sqls];
    }

    /**
     * **التثبيت قبل التوحيد** (يخضرّ قبل بناء ErrorStats ويبقى أخضرَ بعده):
     * أرقامُ الصحّة ومركز التشغيل والموجز الصباحي حرفياً كما كانت.
     */
    public function test_legacy_numbers_stay_identical_across_all_consumers(): void
    {
        $this->seedCore();
        // أربعُ بصماتٍ تفصل الدلالات: حرجٌ داخل الساعة، عالٍ داخل الساعة،
        // محلولٌ داخل الساعة (يسقط من الصحّة)، وعالٍ خارج الساعة (يسقط من نافذتها)
        $this->err(['severity' => 'CRITICAL', 'status' => 'جديد', 'count' => 7213,
            'last_seen' => now()->subMinutes(10), 'message' => 'عطل حرج في مولد البصمة الاختباري']);
        $this->err(['kind' => 'slow', 'severity' => 'HIGH', 'status' => 'قيد المعالجة', 'count' => 40031,
            'last_seen' => now()->subMinutes(30), 'message' => 'بطء استثنائي في لوحة المبيعات']);
        $this->err(['kind' => 'api', 'severity' => 'ERROR', 'status' => 'محلول', 'count' => 6089,
            'last_seen' => now()->subMinutes(10), 'message' => 'خطأ API محلول']);
        $this->err(['severity' => 'HIGH', 'status' => 'جديد', 'count' => 317,
            'last_seen' => now()->subHours(3), 'message' => 'عطل قديم خارج نافذة الساعة']);

        // ١) Health::errors — النافذةُ ساعة، غيرُ المحلول فقط، والأعداد حرفية
        $errs = \App\Support\Health::check()['components']['errors'];
        $this->assertSame(\App\Support\Health::UNAVAILABLE, $errs['status'], 'حرجٌ خلال ساعة = غير متاح');
        $this->assertSame(1, (int) $errs['data']['critical_1h']);
        $this->assertSame(1, (int) $errs['data']['high_1h']);
        $this->assertSame(47244, (int) $errs['data']['hits_1h'], 'مجموع تكرارات غير المحلول خلال ساعة');

        // ٢) مركز التشغيل — أسبوعُ التكرارات والبطيء وAPI كما كانت (تشمل المحلول)
        $ops = $this->actingAs($this->owner)->get('/admin/ops')->assertOk()->getContent();
        $this->assertStringContainsString('53650', $ops, 'مجموع تكرارات ٧ أيام = 7213+40031+6089+317');
        $this->assertStringContainsString('40031', $ops, 'تكرارات البطيء');
        $this->assertStringContainsString('6089', $ops, 'تكرارات API');

        // ٣) الموجز الصباحي — الجديدُ بأسمائه والأعلى تكراراً أولاً
        $morning = $this->actingAs($this->owner)->get('/morning')->assertOk()->getContent();
        $this->assertStringContainsString('عطل حرج في مولد البصمة الاختباري', $morning);
        $this->assertStringContainsString('تكرّر 7213 مرة', $morning);
        $a = strpos($morning, 'عطل حرج في مولد البصمة الاختباري');
        $b = strpos($morning, 'عطل قديم خارج نافذة الساعة');
        $this->assertNotFalse($b, 'الخطأ الثاني الجديد يظهر أيضاً');
        $this->assertLessThan($b, $a, 'الأعلى تكراراً يتقدّم');
    }

    /**
     * بطاقةُ «حرجة» على اللوحة تطابق دلالةَ Health::errors: المحلولُ لا يُعدّ،
     * والمتجاهَلُ **يُعدّ** — فلا تقول اللوحةُ «صفر حرج» والمراقبةُ «النظام يحترق».
     */
    public function test_critical_card_matches_health_error_semantics(): void
    {
        $this->seedCore();
        $this->err(['severity' => 'CRITICAL', 'status' => 'جديد', 'count' => 3, 'last_seen' => now()->subMinutes(5)]);
        $this->err(['severity' => 'CRITICAL', 'status' => 'محلول', 'count' => 9, 'last_seen' => now()->subMinutes(5)]);
        $this->err(['severity' => 'CRITICAL', 'status' => 'متجاهَل', 'count' => 4, 'last_seen' => now()->subMinutes(5)]);

        $html = $this->actingAs($this->owner)->get('/admin/errors')->assertOk()->getContent();
        $this->assertStringContainsString('data-card="critical">2<', $html,
            'بطاقة «حرجة» = المفتوح + المتجاهَل، بلا المحلول — دلالة Health::errors نفسها');

        $data = \App\Support\Health::check()['components']['errors']['data'];
        $this->assertSame(2, (int) $data['critical_1h'], 'والصحّة تعدّ العددَ نفسه');
    }

    /**
     * الرسمُ من العيّنات لا من «آخر ظهور»: خطأٌ بعدّ ٥٠ موزّعٍ على ٥ ساعات
     * يرسم ٥ أعمدة بعشرةٍ في كلٍّ — لا عموداً واحداً بخمسين (تهمة last_seen).
     */
    public function test_over_time_chart_reads_occurrences_not_last_seen(): void
    {
        $this->seedCore();
        $e = $this->err(['count' => 50, 'last_seen' => now()]);
        for ($h = 4; $h >= 0; $h--) {
            for ($i = 0; $i < 10; $i++) {
                $this->occurrence($e, ['occurred_at' => now()->subHours($h)->subMinutes(2)]);
            }
        }

        $range = TimeRange::fromRequest(Request::create('/admin/errors', 'GET', ['range' => '24h']));
        $chart = \App\Support\ErrorStats::overTime($range);

        $this->assertTrue($chart['ok']);
        $this->assertSame('hour', $chart['unit']);
        $this->assertSame(50, $chart['total'], 'كل العيّنات داخل المدى');
        $nonZero = array_values(array_filter($chart['buckets'], fn ($b) => $b['n'] > 0));
        $this->assertCount(5, $nonZero, '٥٠ وقوعاً على ٥ ساعات = ٥ أعمدة');
        foreach ($nonZero as $b) $this->assertSame(10, $b['n']);
        $this->assertSame(10, $chart['max'], 'لا عمودَ واحداً يحمل الخمسين — لا إسنادَ لساعة last_seen');

        // والشاشة ترسمه فعلاً مع كبسولات المدى
        $html = $this->actingAs($this->owner)->get('/admin/errors?range=24h')->assertOk()->getContent();
        $this->assertStringContainsString('data-chart="errors-over-time"', $html);
        $this->assertStringContainsString('آخر ٢٤ ساعة', $html, 'كبسولات partials/timerange حاضرة');
    }

    /**
     * الفتاتُ آمنة: زياراتُ المستخدم المتأثّر وحدَه، وقبل لحظة الوقوع فقط،
     * وقيودُ التدقيق بنفس معرّف الطلب فقط — لا تلصُّصَ على تنقّل مستخدمٍ آخر.
     */
    public function test_breadcrumbs_show_only_the_affected_users_trail(): void
    {
        $this->seedCore();
        $rid = 'rid-bc-777';
        $e = $this->err(['user_id' => $this->employee->id, 'request_id' => $rid]);
        $this->occurrence($e, ['occurred_at' => now()->subMinutes(5),
            'user_id' => $this->employee->id, 'request_id' => $rid]);

        $visit = fn (string $uid, string $path, $at) => DB::table('page_visits')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $uid, 'path' => $path, 'route' => null, 'at' => $at]);
        $visit($this->employee->id, '/m/fin/before-crash', now()->subMinutes(10));
        $visit($this->viewer->id, '/m/hr/other-user-page', now()->subMinutes(10));
        $visit($this->employee->id, '/m/after-crash', now()->subMinutes(1));

        DB::table('audits')->insert([
            ['user_id' => $this->employee->id, 'action' => 'تعديل سجل', 'name' => 'قيد الطلب المرتبط',
             'request_id' => $rid, 'created_at' => now()->subMinutes(5)],
            ['user_id' => $this->viewer->id, 'action' => 'تعديل سجل', 'name' => 'قيد طلب آخر لا علاقة له',
             'request_id' => 'rid-zz-999', 'created_at' => now()->subMinutes(5)],
        ]);

        $html = $this->actingAs($this->owner)->get('/admin/errors/' . $e->id)->assertOk()->getContent();

        $this->assertStringContainsString('/m/fin/before-crash', $html, 'زيارات المتأثّر قبل الوقوع تظهر');
        $this->assertStringNotContainsString('/m/hr/other-user-page', $html, 'زيارة مستخدمٍ آخر لا تظهر أبداً');
        $this->assertStringNotContainsString('/m/after-crash', $html, 'ما بعد الوقوع ليس سبباً — لا يظهر');
        $this->assertStringContainsString('قيد الطلب المرتبط', $html, 'قيود التدقيق بنفس معرّف الطلب تظهر');
        $this->assertStringNotContainsString('قيد طلب آخر لا علاقة له', $html, 'قيدُ طلبٍ آخر لا يظهر');
        $this->assertStringContainsString('system/trace/' . $rid, $html, 'معرّف الطلب يربط لصفحة الأثر');
    }

    /**
     * كلفةُ صفحة التفصيل ثابتة: الأسماء بـwhereIn على المعروض لا بجلب جدول
     * المستخدمين كلّه — فعددُ الاستعلامات لا ينمو بعدد المستخدمين، واسمُ
     * مستخدمٍ موقوفٍ لا علاقةَ له لا يُشحن للصفحة.
     */
    public function test_show_query_count_does_not_grow_with_user_count(): void
    {
        $this->seedCore();
        $e = $this->err(['user_id' => $this->employee->id]);
        $this->occurrence($e, ['occurred_at' => now()->subMinutes(3), 'user_id' => $this->employee->id, 'request_id' => 'rid-q-1']);
        $this->occurrence($e, ['occurred_at' => now()->subMinutes(2), 'user_id' => $this->viewer->id, 'request_id' => 'rid-q-2']);

        // مستخدمٌ موقوفٌ لا يمسّه الخطأ: كان اسمُه يصل الشاشةَ عبر User::pluck الكامل
        User::create(['name' => 'موقوف لا علاقة له إطلاقاً', 'email' => 'frozen@test.local',
            'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'موقوف',
            'password_changed_at' => now()]);

        // تدفئتان: بعض الخبايا لا تمتلئ إلا في الفتحة الثانية — القياس على حالة مستقرة
        $this->actingAs($this->owner)->get('/admin/errors/' . $e->id)->assertOk();
        $this->get('/admin/errors/' . $e->id)->assertOk();
        [$before] = $this->counted(fn () => $this->get('/admin/errors/' . $e->id)->assertOk());

        $html = $this->get('/admin/errors/' . $e->id)->assertOk()->getContent();
        $this->assertStringNotContainsString('موقوف لا علاقة له إطلاقاً', $html,
            'لا جلبَ لجدول المستخدمين كلّه — الأسماء بـwhereIn على المعروض والمسؤولون النشطون فقط');

        // عشرةُ مستخدمين جدد لكلٍّ وقوعٌ وزيارة — الاستعلامات لا تزيد
        for ($i = 0; $i < 10; $i++) {
            $u = User::create(['name' => "مستخدم إضافي {$i}", 'email' => "extra{$i}@test.local",
                'password' => 'Secret!2026x', 'role_id' => $this->employee->role_id, 'status' => 'نشط',
                'password_changed_at' => now()]);
            $this->occurrence($e, ['occurred_at' => now()->subMinutes(1), 'user_id' => $u->id,
                'request_id' => "rid-x{$i}"]);
            DB::table('page_visits')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id,
                'path' => "/m/x/{$i}", 'route' => null, 'at' => now()->subMinutes(4)]);
        }

        // تدفئةٌ بعد الإنشاء: إنشاءُ المستخدمين يُبطل خبايا الهيكل (شارات الشريط
        // الجانبي) فتُعاد تعبئتُها مرّةً — والمقارنةُ العادلة بين فتحتين دافئتين
        $this->get('/admin/errors/' . $e->id)->assertOk();
        $this->get('/admin/errors/' . $e->id)->assertOk();
        [$after] = $this->counted(fn () => $this->get('/admin/errors/' . $e->id)->assertOk());
        $this->assertSame($before, $after,
            "استعلامات صفحة التفصيل نمت من {$before} إلى {$after} مع نموّ المستخدمين");
    }

    /** اللوحة تعرض العشرَ بطاقات ومنها ما يقرأ من العيّنات بعدّها الحقيقي */
    public function test_dashboard_shows_the_ten_cards(): void
    {
        $this->seedCore();
        $e = $this->err(['kind' => 'php', 'status' => 'جديد', 'count' => 6,
            'first_seen' => now()->subHours(2), 'last_seen' => now()]);
        $this->err(['kind' => 'slow', 'status' => 'قيد المعالجة', 'severity' => 'WARNING', 'count' => 2]);
        $this->err(['kind' => 'api', 'status' => 'محلول', 'severity' => 'HIGH',
            'resolved_at' => now()->subHours(2), 'resolved_release' => '2.400.0']);
        $this->err(['kind' => 'js', 'status' => 'جديد', 'severity' => 'WARNING',
            'first_seen' => now()->subHour(), 'regressed_at' => now()->subHour(), 'regression_release' => '2.401.0']);
        $this->occurrence($e, ['occurred_at' => now()->subMinutes(30), 'user_id' => $this->employee->id]);
        $this->occurrence($e, ['occurred_at' => now()->subMinutes(20), 'user_id' => $this->viewer->id]);

        $html = $this->actingAs($this->owner)->get('/admin/errors')->assertOk()->getContent();

        $this->assertStringContainsString('data-card="open">3<', $html, 'مفتوحة = غير المحسوم (بلا محلول ومتجاهَل)');
        $this->assertStringContainsString('data-card="new24">2<', $html, 'جديدة اليوم = أول ظهور خلال ٢٤ ساعة');
        $this->assertStringContainsString('data-card="hits24">2<', $html, 'وقوعات ٢٤س من جدول العيّنات لا من count');
        $this->assertStringContainsString('data-card="users24">2<', $html, 'مستخدمان متأثران من العيّنات');
        $this->assertStringContainsString('data-card="regressions">1<', $html, 'انحدار واحد مفتوح');
        foreach (['php', 'api', 'js', 'slow'] as $k) {
            $this->assertStringContainsString('data-card="kind-' . $k . '"', $html, "بطاقة النوع {$k}");
        }
        $this->assertStringContainsString('خطأ جديد لم يُراجَع', $html);
        $this->assertStringContainsString('مرة تكرار', $html);
    }
}
