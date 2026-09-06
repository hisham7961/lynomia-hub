<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\OpsController;
use App\Models\KeyResult;
use App\Models\KpiDef;
use App\Models\Objective;
use App\Models\Task;
use App\Support\ApiTokens;
use App\Support\Audit;
use App\Support\Correlation;
use App\Support\DataQuality;
use App\Support\ErrorStats;
use App\Support\ExecutionStats;
use App\Support\Health;
use App\Support\Integrations;
use App\Support\KpiCentre;
use App\Support\Remediation;
use App\Support\SecurityFindings;
use App\Support\SecurityPosture;
use App\Support\Series;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **اختبارُ القبول — مستوى التحكّم كلُّه** (spec §42–§48).
 *
 * ═══ ما هذا الملف ═══
 * المواصفةُ لا تسأل «هل الشيفرةُ مكتوبة؟» بل تسأل **ثلاثةً وستّين سؤالاً بعينها**
 * ثم تشترط: «المالكُ يجب أن يستطيع الجواب». فهذا الملفُّ يطرح تلك الأسئلة على
 * النظام الحيّ — بيانات مبذورة، ثم **صفحةٌ حقيقية أو قارئُها المالك** — ويشترط
 * جواباً بمادّةٍ لا بشكل.
 *
 * ═══ ثلاث قواعدَ تحكم كلَّ جوابٍ هنا ═══
 *  ① **الجوابُ من مصدره لا من نسخةٍ ثانية.** حيث للسؤال محرّكٌ يملكه
 *    (`SecurityPosture` · `Health` · `ErrorStats` · `ExecutionStats` ·
 *    `DataQuality` · `Settings`) يُقارَن ما تعرضه الشاشةُ بنداءٍ مباشرٍ للمحرّك:
 *    فأيُّ إعادةِ حسابٍ في متحكّمٍ تُسقط الاختبار لا تمرّ صامتة.
 *  ② **لا صفرَ مكانَ غياب قياس** (§26). حيث بذرنا واقعةً نشترط عددَها بالضبط،
 *    وحيث لم نبذر نشترط «لا قياس» (`null`) لا صفراً يُقرأ شهادةَ سلامة.
 *  ③ **سؤالٌ بلا جوابٍ عيبٌ يُبلَّغ لا يُطوى.** `ans()` تُسجّل السؤالَ وتشترط
 *    دليلَه؛ وفي ذيل كل قسمٍ تُطابَق قائمةُ ما أُجيب بقائمة المواصفة **كاملةً
 *    وبترتيبها** — فسؤالٌ سقط سهواً يُسقط الاختبار باسمه لا بصمتٍ في التغطية.
 *
 * والقارئُ في كل الأقسام هو **المالك** لأنّ المواصفةَ تسأل عنه («The owner must
 * be able to answer»)؛ وحرّاسُ غيره مُختبَرةٌ في ملفات مراكزها (ControlHomeTest ·
 * SecurityFindingsTest · QualityCenterTabsTest · WorkforceOverviewTest …) ولا
 * تُكرَّر هنا.
 */
class ControlPlaneAcceptanceTest extends TestCase
{
    /* ═════════ أسئلةُ المواصفة — نصّاً وترتيباً ═════════ */

    /** §42 — الأمن */
    private const Q_SECURITY = [
        'ما وضعيّتي الأمنية الآن؟',
        'ما المشكلاتُ الأمنية الحرجة القائمة؟',
        'أيُّ الحسابات أكثرُ انكشافاً؟',
        'أيُّ الحسابات المميّزة بلا تحقّقٍ بخطوتين؟',
        'أيُّ الحسابات ذاتُ سلوكٍ مريب؟',
        'أيُّ الأسرار متقادمة؟',
        'أيُّ اعتمادات API خطرة؟',
        'أيُّ الحوادث الأمنية مفتوحة؟',
        'ماذا جرى في حدثٍ أمنيٍّ بعينه؟',
        'أيُّ قيود التدقيق والأخطاء والطلبات تتّصل به؟',
    ];

    /** §43 — التشغيل */
    private const Q_OPS = [
        'هل لينوميا سليمة؟',
        'هل قاعدةُ البيانات سليمة؟',
        'هل المعالجُ والذاكرةُ والقرص سليمة؟',
        'هل ثمّة اعتماديةٌ ساقطة؟',
        'هل الطوابيرُ متأخّرة؟',
        'هل المجدولُ يعمل؟',
        'أيُّ المسارات بطيئة؟',
        'كم p95؟',
        'هل انحدر الأداء؟',
        'هل تتحقّق أهدافُ مستوى الخدمة؟',
        'هل ارتفعت الأخطاءُ بعد نشرة؟',
    ];

    /** §44 — الأخطاء */
    private const Q_ERRORS = [
        'أيُّ الأخطاء أهمّ؟',
        'كم مرّةً تقع؟',
        'كم مستخدماً تأثّر؟',
        'أيُّ طلبٍ سبّبه؟',
        'أين موضعُه في الشيفرة؟',
        'هل أُصلح من قبل؟',
        'هل هو انحدارٌ (عاد بعد إصلاحه)؟',
        'من يعمل عليه؟',
        'هل له حادثةٌ أو مهمّة؟',
    ];

    /** §45 — التدقيق */
    private const Q_AUDIT = [
        'من غيّر هذا السجل؟',
        'ما الذي تغيّر؟',
        'قبل مقابل بعد؟',
        'متى؟',
        'من أيّ عنوانٍ وجهاز؟',
        'هل كان حسّاساً؟',
        'هل ثمّة معرّفُ طلب؟',
        'هل اتّصل بحادثةٍ أمنية؟',
        'هل سلسلةُ التدقيق سليمة؟',
        'هل ثمّة عملياتٌ مهمّة بلا تدقيق؟',
    ];

    /** §46 — القوى العاملة */
    private const Q_WORKFORCE = [
        'ما الذي أُنجز؟',
        'ما المتأخّر؟',
        'أيُّ الأعمال متعثّرة؟',
        'أيُّ الفرق يعاني اختلالَ حِمل؟',
        'ما نسبةُ الالتزام بالمواعيد؟',
        'أيُّ المجالات التشغيلية اختناقات؟',
        'ويبقى النشاطُ الأمنيّ مفصولاً عن الإنتاجية.',
    ];

    /** §47 — الجودة */
    private const Q_QUALITY = [
        'ما مشكلاتُ جودة البيانات القائمة؟',
        'أيُّ الوحدات تتدهور؟',
        'ما الذي تحسّن؟',
        'أيُّ الأعمال متأخّرة؟',
        'أيُّ المؤشّرات خارج هدفها؟',
        'أيُّ الأهداف متعثّرة؟',
        'أيُّ مهامِّ المعالجة قائمة؟',
    ];

    /** §48 — الإعدادات */
    private const Q_SETTINGS = [
        'ما هذا الإعداد؟',
        'ما افتراضيُّه؟',
        'ما الساري الآن؟',
        'ماذا يحدث إن غيّرتُه؟',
        'هل هو خطر؟',
        'من غيّره آخرَ مرّة؟',
        'هل أستطيع استعادتَه بأمان؟',
        'هل التكاملُ المربوط يعمل؟',
        'هل أستطيع تصديرَ التهيئة بأمان؟',
    ];

    /** ما أُجيب عنه في هذا الاختبار — يُطابَق بقائمة القسم في ذيله */
    private array $asked = [];

    /**
     * جوابٌ واحد: `$evidence` مادّتُه. الفراغُ (`null`/`''`/`[]`/`false`) ليس
     * جواباً — والصفرُ **جوابٌ** حيث يكون عدّاً حقيقياً، فلا يُرفض بالخلط.
     */
    private function ans(string $q, $evidence, string $why): void
    {
        $this->asked[] = $q;
        $empty = $evidence === null || $evidence === '' || $evidence === [] || $evidence === false;
        $this->assertFalse($empty, "سؤالُ القبول «{$q}» بلا جواب — {$why}");
    }

    /** ختمُ القسم: أُجيب عن كلّ أسئلته، بترتيبها، بلا زيادةٍ ولا نقصان */
    private function sealed(array $spec, string $section): void
    {
        $missing = array_values(array_diff($spec, $this->asked));
        $this->assertSame([], $missing,
            "{$section}: أسئلةٌ بلا جواب — " . implode(' · ', $missing));
        $this->assertSame($spec, $this->asked,
            "{$section}: ترتيبُ الأجوبة يخالف ترتيبَ المواصفة أو فيه سؤالٌ ليس منها");
    }

    /* ═════════════════ §42 — الأمن ═════════════════ */

    public function test_section_42_the_owner_can_answer_every_security_question(): void
    {
        $this->seedCore();
        $now = now();
        $rid = (string) Str::uuid();

        // نتيجتان أمنيّتان مفتوحتان: حرجةٌ على مستوى المنظّمة، ومرتفعةٌ على كيان
        foreach ([['debug_mode', 'critical', 'org', ''],
                  ['secret_stale', 'high', 'secret', (string) Str::uuid()]] as [$code, $sev, $type, $eid]) {
            DB::table('security_findings')->insert([
                'id' => (string) Str::uuid(), 'code' => $code, 'entity_type' => $type, 'entity_id' => $eid,
                'severity' => $sev, 'title' => 'نتيجةٌ أمنية: ' . $code, 'status' => 'open',
                'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // سرٌّ متقادم (rotated_at قديم) — «أيُّ الأسرار متقادمة؟»
        $staleDays = max(1, (int) setting('security.secret_stale_days', 180));
        DB::table('vault_secrets')->insert([
            'id' => (string) Str::uuid(), 'title' => 'مفتاحُ بوّابة الدفع', 'type' => 'مفتاح',
            'rotated_at' => now()->subDays($staleDays + 30),
            'created_at' => now()->subDays($staleDays + 60), 'updated_at' => $now,
        ]);

        // رمزُ API خطر: بلا انتهاءٍ ولم يُستعمل قطُّ — «أيُّ اعتمادات API خطرة؟»
        $this->apiToken($this->owner);

        // سلوكٌ مريب: منعُ وصولٍ حقيقيّ (رادار الكشف) بمعرّف طلبٍ يربط الطبقات
        $denialId = DB::table('access_denials')->insertGetId([
            'kind' => 'تخمين رابط', 'user_id' => $this->employee->id, 'ip' => '203.0.113.77',
            'method' => 'GET', 'path' => '/admin/settings', 'detail' => 'محاولةُ فتحِ شاشةِ إعدادات',
            'created_at' => $now, 'request_id' => $rid,
        ]);

        // ما يتّصل بالحدث نفسِه بمعرّف الطلب: قيدُ تدقيقٍ وخطأٌ وحادثةٌ أمنية مفتوحة
        $this->actingAs($this->owner);
        hub_audit('دخول فاشل', null, null, 'محاولةُ دخولٍ مرفوضة', ['request_id' => $rid, 'ip' => '203.0.113.77']);
        DB::table('error_events')->insert([
            'id' => (string) Str::uuid(), 'hash' => str_repeat('a', 64), 'kind' => 'php',
            'message' => 'عطلٌ رافقَ المحاولة', 'severity' => 'HIGH', 'status' => 'جديد', 'count' => 1,
            'request_id' => $rid, 'first_seen' => $now, 'last_seen' => $now,
        ]);
        $incidentId = (string) Str::uuid();
        DB::table('incidents')->insert([
            'id' => $incidentId, 'title' => 'اقتحامٌ مشتبَه به', 'severity' => 'حرج',
            'status' => 'قيد المعالجة', 'kind' => 'أمن', 'request_id' => $rid,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        /* ① الوضعية — الدرجةُ من محرّكها لا من حسابٍ في الشاشة */
        $idx = $this->get(route('security.index'))->assertOk();
        $score = $idx->viewData('summary')['score'] ?? null;
        $this->assertSame(SecurityPosture::summary()['score'], $score,
            'درجةُ الوضعية في الشاشة لا تساوي SecurityPosture::summary — حسابٌ ثانٍ');
        $this->ans(self::Q_SECURITY[0], $score, 'شاشةُ الأمن لا تعرض درجةَ وضعية');

        /* ② المشكلاتُ الحرجة — العددُ والصفُّ معاً */
        $f = $this->get(route('security.findings', ['st' => 'open']))->assertOk();
        $rows = collect($f->viewData('rows')->items());
        $this->assertSame(1, (int) (SecurityFindings::openCounts()['critical'] ?? 0),
            'عدّادُ الحرج لا يساوي النتائجَ المبذورة');
        $this->assertTrue($rows->contains(fn ($r) => $r->code === 'debug_mode'),
            'النتيجةُ الحرجة غائبةٌ عن قائمة مركز النتائج');
        $this->ans(self::Q_SECURITY[1], $rows->all(), 'مركزُ النتائج لا يعرض نتيجةً حرجةً مفتوحة');

        /* ③ الأكثرُ انكشافاً — خريطةُ الهوية مرتّبةٌ بالأعلى خطراً أولاً */
        $ident = $this->get(route('security.identity'))->assertOk();
        $iRows = $ident->viewData('rows')->items();
        $this->assertNotSame([], $iRows, 'خريطةُ الهوية فارغةٌ مع وجود مستخدمين');
        $scores = array_map(fn ($r) => (int) $r['score'], $iRows);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'الترتيبُ ليس بالأعلى انكشافاً أولاً');
        $this->assertNotSame([], $iRows[0]['factors'], 'أعلى صفٍّ بلا عواملَ مفسِّرة — رقمٌ بلا سبب');
        $this->ans(self::Q_SECURITY[2], $iRows, 'لا صفوفَ انكشافٍ للحسابات');

        /* ④ المميّزون بلا MFA — من قارئ الوضعية نفسِه */
        $priv = $this->get(route('security.privileged', ['cat' => 'no_mfa']))->assertOk();
        $noMfa = $priv->viewData('cats')['no_mfa'] ?? [];
        $this->assertSame(
            array_map('strval', SecurityPosture::privilegedNoMfaIds()),
            array_map(fn ($r) => (string) $r['id'], $noMfa),
            'قائمةُ «مميّزٌ بلا MFA» لا تساوي SecurityPosture::privilegedNoMfaIds — قائمةٌ ثانية');
        $this->assertContains((string) $this->owner->id, array_map(fn ($r) => (string) $r['id'], $noMfa),
            'المالكُ بلا تحقّقٍ بخطوتين وغائبٌ عن القائمة');
        $this->ans(self::Q_SECURITY[3], $noMfa, 'مراجعةُ الامتيازات لا تُظهر فئةَ «بلا MFA»');

        /* ⑤ السلوكُ المريب — رادارُ المنع بأدلّته */
        $denials = $idx->viewData('denials');
        $this->assertTrue(collect($denials)->contains(fn ($d) => (string) $d->ip === '203.0.113.77'),
            'المنعُ المبذور غائبٌ عن رادار الكشف');
        $this->ans(self::Q_SECURITY[4], collect($denials)->all(), 'لا رادارَ منعٍ في شاشة الأمن');

        /* ⑥ الأسرارُ المتقادمة */
        $sec = $this->get(route('security.secrets'))->assertOk();
        $this->assertSame(count(SecurityPosture::vaultStaleIds()), (int) $sec->viewData('staleCount'),
            'عدّادُ الأسرار المتقادمة لا يساوي SecurityPosture::vaultStaleIds');
        $this->assertGreaterThanOrEqual(1, (int) $sec->viewData('staleCount'),
            'السرُّ المبذور متقادمٌ ولم يُحصَ');
        $this->ans(self::Q_SECURITY[5], (int) $sec->viewData('staleCount') > 0,
            'صحّةُ الأسرار لا تُظهر متقادماً');

        /* ⑦ اعتماداتُ API الخطرة — بلا قيمةِ رمزٍ ولا بصمة */
        $tok = $this->get(route('security.tokens'))->assertOk();
        $tRows = collect($tok->viewData('rows'));
        $this->assertNotSame([], $tRows->all(), 'مركزُ الرموز فارغٌ مع وجود رمز');
        $this->assertSame(ApiTokens::summary(), $tok->viewData('summary'),
            'ملخّصُ الرموز لا يساوي ApiTokens::summary');
        $this->assertStringNotContainsString('token_hash', $tok->getContent(), 'بصمةُ الرمز مكشوفة');
        $this->ans(self::Q_SECURITY[6], $tRows->all(), 'لا صفوفَ رموزٍ ولا تصنيفَ خطرها');

        /* ⑧ الحوادثُ الأمنية المفتوحة — بسكّة «المفتوح» الواحدة */
        $open = (int) hub_open_scope(hub_scope(DB::table('incidents')->whereNull('deleted_at'), 'incidents'))->count();
        $this->assertSame(1, $open, 'عدّادُ الحوادث المفتوحة لا يساوي المبذور');
        $this->get(route('m.index', ['module' => 'incidents']))->assertOk()->assertSee('اقتحامٌ مشتبَه به');
        $this->ans(self::Q_SECURITY[7], $open, 'قائمةُ الحوادث لا تُظهر الحادثةَ الأمنية المفتوحة');

        /* ⑨ ماذا جرى في حدثٍ بعينه */
        $ev = $this->get(route('security.event', ['source' => 'radar', 'id' => $denialId]))->assertOk();
        $e = $ev->viewData('e');
        $this->assertSame('LINK_GUESS', $e['code'], 'رمزُ الحدث لا يطابق كتالوج SecurityEvents');
        $this->assertNotEmpty($e['at'], 'حدثٌ بلا زمن');
        $this->ans(self::Q_SECURITY[8], $e, 'صفحةُ الحدث لا تحمل تفصيلَه');

        /* ⑩ ما يتّصل به: تدقيقٌ وخطأٌ وحادثةٌ ومنعٌ بمعرّف الطلب الواحد */
        $this->assertSame($incidentId, (string) ($ev->viewData('incident')->id ?? ''),
            'صفحةُ الحدث لا تصل الحادثةَ بمعرّف الطلب');
        $trace = $this->get(route('system.trace', ['rid' => $rid]))->assertOk();
        $kinds = collect($trace->viewData('rows'))->pluck('kind')->unique()->sort()->values()->all();
        foreach (['audit', 'error', 'incident', 'denial'] as $k) {
            $this->assertContains($k, $kinds, "أثرُ الطلب لا يعرض طبقةَ «{$k}»");
        }
        $this->ans(self::Q_SECURITY[9], $kinds, 'أثرُ الطلب لا يجمع الطبقاتِ المتّصلة');

        $this->sealed(self::Q_SECURITY, '§42 الأمن');
    }

    /* ═════════════════ §43 — التشغيل ═════════════════ */

    public function test_section_43_the_owner_can_answer_every_operations_question(): void
    {
        $this->seedCore();
        // عيّنةُ الانحدار صغيرةٌ في الاختبار — العتبةُ مفتاحٌ لا ثابت، فتُضبط لا تُلتَفّ
        $this->hubSetting('ops.regression_min_n', '2');
        $this->hubSetting('slo.availability_pct', '99');
        $this->hubSetting('slo.latency_ms', '500');
        $this->hubSetting('slo.latency_pct', '95');
        $this->hubSetting('slo.error_rate_pct', '1');
        $this->hubSetting('slo.window_days', '7');

        // ① طابورٌ متأخّر: رسالةٌ تنتظر منذ ساعتين ⇒ مكوّنُ الصندوق الصادر غيرُ سليم
        DB::table('outbox')->insert([
            'id' => (string) Str::uuid(), 'kind' => 'تنبيه', 'channel' => 'mail',
            'target' => 'ops@test.local', 'text' => 'رسالةٌ عالقة', 'state' => 'queued',
            'created_at' => now()->subHours(2),
        ]);

        // ② حاوياتُ HTTP في نافذتين متجاورتين — الحاليةُ أبطأُ وأكثرُ خطأً (انحدار)،
        //    ومدرَّجٌ لوغاريتميّ لكل حاوية فيصير p95 مقروءاً.
        $rows = [];
        $mk = function (\Illuminate\Support\Carbon $at, int $n, array $ms, int $e5) use (&$rows) {
            $rows[] = ['bucket_at' => $at->toDateTimeString(), 'surface' => 'web', 'method' => 'GET',
                'route' => 'admin/ops', 'count' => $n, 'err4' => 0, 'err5' => $e5,
                'slow' => 0, 'sum_ms' => (int) array_sum($ms) * (int) ($n / max(1, count($ms))),
                'max_ms' => (int) max($ms), 'hist' => json_encode(Series::hist($ms)),
                'updated_at' => now()->toDateTimeString()];
        };
        // النافذةُ السابقة (بين ١٤ و٧ أيام) — سريعةٌ بلا أخطاء
        foreach ([13, 12, 11, 10, 9] as $d) $mk(now()->subDays($d), 40, [80, 90, 120, 140], 0);
        // النافذةُ الحالية (آخر ٧ أيام، وأوّلُها داخل حدّ تغطية SLO) — أبطأُ وبأخطاء
        foreach ([6, 5, 4, 3, 2, 1] as $d) $mk(now()->subDays($d)->addHours(2), 40, [300, 600, 900, 1200], 3);
        DB::table('http_metric_buckets')->insert($rows);

        // ③ مساراتٌ بطيئة — من بصمات «slow» في مركز الأخطاء
        DB::table('error_events')->insert([
            'id' => (string) Str::uuid(), 'hash' => str_repeat('c', 64), 'kind' => 'slow',
            'message' => 'طلبٌ تجاوز عتبةَ البطء', 'url' => '/admin/quality', 'count' => 12,
            'status' => 'جديد', 'first_seen' => now()->subDays(3), 'last_seen' => now()->subDay(),
        ]);

        // ④ توافرُ المراقبة (SLO) — نقاطُ فحصٍ عبر النافذة، فيها فشلٌ واحد
        for ($i = 0; $i < 20; $i++) {
            hub_metric_put('uptime', 'probe-1', 'up', $i === 3 ? 0.0 : 1.0,
                now()->subDays(7)->addHours(6 + $i * 8), 'auto');
        }

        // ⑤ نشرةٌ لها نافذتا «قبل/بعد»
        DB::table('deployments')->insert([
            'id' => (string) Str::uuid(), 'ver' => 'v2.407.0', 'env' => 'إنتاج',
            'deployed_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($this->owner)->get(route('ops.index'))->assertOk();

        /* ① هل النظامُ سليم — الحالةُ التي يقرؤها /healthz نفسُها */
        $health = $res->viewData('health');
        $this->assertSame(Health::check()['status'], $health['status'],
            'حالةُ الشاشة لا تساوي Health::check');
        $this->assertContains($health['status'], array_keys(Health::LABELS));
        $this->ans(self::Q_OPS[0], $health['status'], 'مركزُ التشغيل بلا حالةٍ عامّة');

        /* ② قاعدةُ البيانات */
        $db = $res->viewData('db');
        $this->assertTrue((bool) $db['ok'], 'قاعدةُ البيانات تُقرأ ساقطةً وهي تعمل');
        $this->assertNotNull($db['ms'], 'لا زمنَ استجابةٍ للقاعدة');
        $this->assertArrayHasKey('db', $health['components']);
        $this->ans(self::Q_OPS[1], $db, 'لا بطاقةَ صحّةٍ لقاعدة البيانات');

        /* ③ المعالجُ والذاكرةُ والقرص */
        $cpu = $res->viewData('cpu');
        $mem = $res->viewData('mem');
        $sys = $res->viewData('sys');
        $this->assertNotSame([], $cpu, 'لا قراءةَ معالج');
        $this->assertNotSame([], $mem, 'لا قراءةَ ذاكرة');
        $this->assertArrayHasKey('disk_pct', $sys);
        $this->ans(self::Q_OPS[2], [$cpu, $mem, $sys], 'لا قراءاتِ موارد');

        /* ④ اعتماديةٌ ساقطة — والقدراتُ التي تموت بموتها بالاسم */
        $this->assertNotSame(Health::HEALTHY, $health['components']['outbox']['status'],
            'رسالةٌ عالقةٌ منذ ساعتين ولم يتدهور مكوّنُ الطابور');
        $hit = array_keys(array_filter(Health::dependencies(),
            fn ($comps) => in_array('outbox', $comps, true)));
        $this->assertNotSame([], $hit, 'خريطةُ الاعتماديات لا تسمّي قدرةً تعتمد على الطابور');
        $cards = OpsController::dependencyCards($health);
        $this->assertTrue(collect($cards)->contains(fn ($c) => $c['id'] === 'dep-core-queue'),
            'لوحةُ الاعتماديات بلا بطاقةِ طابور');
        $this->ans(self::Q_OPS[3], $hit, 'لا خريطةَ اعتمادياتٍ تُترجم العطلَ إلى قدرةٍ معطّلة');

        /* ⑤ تأخّرُ الطوابير — بالدقائق لا بالوصف */
        $oldest = (int) ($health['components']['outbox']['data']['oldest_min'] ?? 0);
        $this->assertGreaterThanOrEqual(60, $oldest, 'عمرُ أقدمِ منتظرةٍ لا يُقاس');
        $this->ans(self::Q_OPS[4], $oldest, 'لا قياسَ لتأخّر الطابور');

        /* ⑥ المجدول */
        $jobs = $health['components']['scheduler']['data']['jobs'] ?? [];
        $this->assertNotSame([], $jobs, 'لا مهامَّ مجدولةً مرصودة');
        $this->assertSame(array_keys(Health::JOBS), array_keys($jobs),
            'قائمةُ المهام المرصودة لا تساوي Health::JOBS — قائمةٌ ثانية');
        $this->assertNotSame([], $res->viewData('beats'), 'لا نبضاتِ مجدولٍ في الشاشة');
        $this->ans(self::Q_OPS[5], $jobs, 'لا رصدَ للمجدول');

        /* ⑦ المساراتُ البطيئة */
        $slow = $res->viewData('consumers')['slow'] ?? [];
        $this->assertTrue(collect($slow)->contains(fn ($s) => $s['url'] === '/admin/quality'),
            'المسارُ البطيء المبذور غائبٌ عن قائمة البطء');
        $this->ans(self::Q_OPS[6], $slow, 'لا قائمةَ مساراتٍ بطيئة');

        /* ⑧ p95 — من المدرَّج، بحدّ خطأٍ معلَن */
        $rp = $res->viewData('rp');
        $this->assertNotNull($rp['rows'], 'جدولُ أداء المسارات غائب');
        $first = $rp['rows']->getCollection()->first();
        $this->assertNotNull($first, 'لا صفوفَ أداءٍ رغم وجود حاويات');
        $this->assertNotNull($first->p95, 'p95 غيرُ محسوب — المدرَّجُ لا يُقرأ');
        $this->assertGreaterThanOrEqual($first->p50, $first->p95, 'p95 دون p50 — مدرَّجٌ مقلوب');
        // و**تُقال تقريبيّةً** (ق٥ · §26): رقمٌ من مدرَّجٍ لوغاريتميّ يُقدَّم دقيقاً
        // دقّةٌ مُختلَقة — حدُّ خطأ الحاوية معلَنٌ في الشاشة نفسِها
        $this->assertStringContainsString('±' . (Series::MAX_REL_ERROR * 100) . '٪', $res->getContent(),
            'النسبُ المئوية تُعرَض بلا حدّ خطأٍ معلَن — دقّةٌ مُختلَقة');
        $this->ans(self::Q_OPS[7], $first->p95, 'لا نسبةَ مئويةً للزمن');

        /* ⑨ الانحدار — نافذتان متساويتان وحكمٌ بعتبةٍ من الإعدادات */
        $reg = $rp['reg'];
        $this->assertTrue((bool) $reg['enough'], 'العيّنةُ كافيةٌ ومع ذلك امتنع الحكم');
        $this->assertNotNull($reg['perf']['pct'], 'لا نسبةَ تغيّرٍ في الزمن بين النافذتين');
        $this->assertTrue((bool) $reg['perf_flag'], 'الزمنُ تضاعف ولم يُرفع علَمُ الانحدار');
        $this->ans(self::Q_OPS[8], $reg, 'لا مقارنةَ نافذتين للأداء');

        /* ⑩ أهدافُ مستوى الخدمة */
        $slo = $res->viewData('slo');
        $this->assertTrue((bool) $slo['on'], 'المفاتيحُ مضبوطةٌ والقسمُ مطفأ');
        $av = collect($slo['objectives'])->firstWhere('key', 'availability');
        $this->assertTrue((bool) $av['enough'], 'التوافرُ بلا قياسٍ رغم النقاط');
        $this->assertNotNull($av['sli'], 'لا مؤشّرَ خدمةٍ محسوب');
        $this->assertNotNull($av['budget'], 'لا ميزانيةَ خطأ');
        $this->ans(self::Q_OPS[9], $slo['objectives'], 'لا أهدافَ مستوى خدمةٍ مقيسة');

        /* ⑪ الأخطاءُ بعد نشرة */
        $rel = $res->viewData('rel');
        $this->assertNotSame([], $rel['rows'], 'لا نشراتٍ مقروءة');
        $row = $rel['rows'][0];
        $this->assertArrayHasKey('before', $row);
        $this->assertArrayHasKey('after', $row);
        $this->ans(self::Q_OPS[10], $rel['rows'], 'لا مقارنةَ «قبل/بعد» لنشرة');

        $this->sealed(self::Q_OPS, '§43 التشغيل');
    }

    /* ═════════════════ §44 — الأخطاء ═════════════════ */

    public function test_section_44_the_owner_can_answer_every_error_question(): void
    {
        $this->seedCore();
        $now = now();
        $rid = (string) Str::uuid();
        $id = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $incidentId = (string) Str::uuid();

        // موضعٌ حقيقيٌّ في الشيفرة — ملفٌّ داخل جذر المشروع (الحارسُ يرفض ما خرج عنه)
        $file = app_path('Support/Health.php');

        Task::create(['id' => $taskId, 'title' => 'إصلاحُ العطل الحرج', 'status' => 'جديدة']);
        DB::table('incidents')->insert([
            'id' => $incidentId, 'title' => 'انقطاعٌ من العطل نفسِه', 'severity' => 'حرج',
            'status' => 'قيد المعالجة', 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('error_events')->insert([
            'id' => $id, 'hash' => str_repeat('d', 64), 'kind' => 'php',
            'message' => 'انقسامٌ على صفر في محرّك الفواتير', 'file' => $file, 'line' => 60,
            'url' => '/fin/invoices', 'method' => 'GET', 'severity' => 'CRITICAL', 'category' => 'منطق',
            'status' => 'جديد', 'count' => 17, 'users' => 2,
            'first_seen' => now()->subDays(9), 'last_seen' => $now,
            'request_id' => $rid,
            'assignee_id' => $this->employee->id,
            'resolved_at' => now()->subDays(5), 'resolved_by' => $this->owner->id,
            'resolved_release' => 'v2.400.0',
            'regressed_at' => now()->subDay(), 'regression_release' => 'v2.407.0',
            'incident_id' => $incidentId,
            'meta' => json_encode(['task_id' => $taskId]),
        ]);

        // وقائعُ (لا بصمات): مستخدمان متمايزان خلال ٢٤ ساعة، وطلبٌ مسبِّبٌ بمعرّفه
        foreach ([[$this->owner->id, $rid], [$this->employee->id, (string) Str::uuid()]] as $i => [$uid, $r]) {
            DB::table('error_occurrences')->insert([
                'error_event_id' => $id, 'occurred_at' => now()->subMinutes(10 + $i),
                'request_id' => $r, 'user_id' => $uid, 'route' => 'fin.invoices',
                'url' => '/fin/invoices', 'method' => 'GET', 'status_code' => 500, 'duration_ms' => 120,
            ]);
        }

        $this->actingAs($this->owner);
        $list = $this->get(route('errors.index', ['sort' => 'count']))->assertOk();
        $stats = $list->viewData('stats');

        /* ① أيُّ الأخطاء أهمّ — الحرجُ المفتوح من قارئه */
        $this->assertSame(ErrorStats::cards()['critical'], $stats['critical'],
            'عدّادُ الحرج في الشاشة لا يساوي ErrorStats::cards');
        $this->assertSame(1, $stats['critical'], 'العطلُ الحرج المبذور لم يُحصَ');
        $this->assertSame($id, (string) $list->viewData('rows')->items()[0]->id,
            'الأكثرُ تكراراً ليس أولَ الصفوف عند الفرز بالتكرار');
        $this->ans(self::Q_ERRORS[0], $stats['critical'], 'لا ترتيبَ أهميةٍ للأخطاء');

        /* ② كم مرّةً تقع */
        $show = $this->get(route('errors.show', ['id' => $id]))->assertOk();
        $occ = $show->viewData('occ');
        $this->assertSame(2, $occ->total(), 'عيّناتُ الوقوع لا تُعَدّ');
        $this->assertSame(ErrorStats::cards()['hits24'], $stats['hits24'],
            'وقائعُ ٢٤ ساعة لا تساوي قارئَها');
        $this->ans(self::Q_ERRORS[1], $occ->total(), 'لا عدّادَ تكرار');

        /* ③ كم مستخدماً تأثّر — مستخدمون متمايزون لا وقائع */
        $this->assertSame(2, $stats['users24'], 'المتأثّرون لا يُعَدّون متمايزين');
        $this->ans(self::Q_ERRORS[2], $stats['users24'], 'لا عدّادَ متأثّرين');

        /* ④ أيُّ طلبٍ سبّبه — ومنه إلى أثر الطلب الكامل */
        $this->assertNotNull($show->viewData('crumbRid'), 'لا معرّفَ طلبٍ للفتات الآمنة');
        $this->assertTrue(collect($occ->items())->contains(fn ($o) => (string) $o->request_id === $rid),
            'الطلبُ المسبِّب غائبٌ عن جدول العيّنات');
        $this->assertStringContainsString(
            htmlspecialchars(route('system.trace', ['rid' => $rid]), ENT_QUOTES),
            $show->getContent(), 'لا رابطَ من الخطأ إلى أثر طلبه');
        $this->ans(self::Q_ERRORS[3], $rid, 'لا ربطَ بين العطل والطلب المسبِّب');

        /* ⑤ أين موضعُه في الشيفرة */
        $snippet = $show->viewData('snippet');
        $this->assertNotSame([], $snippet, 'لا مقتطفَ شيفرةٍ حول السطر');
        $this->assertTrue(collect($snippet)->contains(fn ($l) => $l['hot'] && $l['n'] === 60),
            'السطرُ الحارّ غيرُ مُعلَّم');
        $this->assertSame('app/Support/Health.php', $show->viewData('relPath'),
            'المسارُ يُعرض مطلقاً لا نسبيّاً للجذر');
        $this->ans(self::Q_ERRORS[4], $snippet, 'لا موضعَ في الشيفرة');

        /* ⑥ هل أُصلح من قبل */
        $e = $show->viewData('e');
        $this->assertNotNull($e->resolved_at, 'لا تاريخَ إصلاحٍ سابق');
        $this->assertSame('v2.400.0', (string) $e->resolved_release, 'لا إصدارَ أُصلح فيه');
        $this->ans(self::Q_ERRORS[5], (string) $e->resolved_release, 'لا سجلَّ إصلاحٍ سابق');

        /* ⑦ هل هو انحدار */
        $this->assertNotNull($e->regressed_at, 'لا وسمَ انحدار');
        $this->assertSame(ErrorStats::cards()['regressions'], $stats['regressions'],
            'عدّادُ الانحدارات لا يساوي قارئَه');
        $this->assertSame(1, $stats['regressions'], 'الانحدارُ المبذور لم يُحصَ');
        $this->ans(self::Q_ERRORS[6], $stats['regressions'], 'لا كشفَ انحدار');

        /* ⑧ من يعمل عليه */
        $this->assertSame((string) $this->employee->id, (string) $e->assignee_id, 'لا مسؤولَ مسنَد');
        $this->assertSame($this->employee->name, $show->viewData('users')[$this->employee->id] ?? null,
            'اسمُ المسؤول لا يُحلّ في الشاشة');
        $this->ans(self::Q_ERRORS[7], (string) $e->assignee_id, 'لا إسنادَ لعطل');

        /* ⑨ هل له حادثةٌ أو مهمّة */
        $this->assertSame($incidentId, (string) $e->incident_id, 'لا حادثةَ مربوطة');
        $this->assertSame($taskId, (string) ($e->meta['task_id'] ?? ''), 'لا مهمّةَ إصلاحٍ مربوطة');
        $this->ans(self::Q_ERRORS[8], $incidentId, 'العطلُ بلا حادثةٍ ولا مهمّة');

        $this->sealed(self::Q_ERRORS, '§44 الأخطاء');
    }

    /* ═════════════════ §45 — التدقيق ═════════════════ */

    public function test_section_45_the_owner_can_answer_every_audit_question(): void
    {
        $this->seedCore();
        $rid = (string) Str::uuid();
        $recordId = (string) Str::uuid();

        // حادثةٌ أمنيةٌ بمعرّف الطلب نفسِه — «هل اتّصل بحادثةٍ أمنية؟»
        DB::table('incidents')->insert([
            'id' => (string) Str::uuid(), 'title' => 'تسريبُ إعدادٍ حسّاس', 'severity' => 'حرج',
            'status' => 'قيد المعالجة', 'kind' => 'أمن', 'request_id' => $rid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->owner);
        // قيدٌ حسّاسٌ بفعلٍ له رمزٌ في الكتالوج الأمنيّ، وبفرقٍ حقيقيّ قبل/بعد
        $a = hub_audit('تعديل إعدادات النظام', 'settings', $recordId, 'security.lockdown', [
            'before' => ['value' => '0'], 'after' => ['value' => '1'],
            'request_id' => $rid, 'ip' => '198.51.100.20',
        ]);
        // قيدٌ ثانٍ بالمعرّف نفسِه — «إخوةُ الطلب»
        hub_audit('تعديل', 'tasks', null, 'أثرٌ جانبيٌّ للطلب نفسِه', ['request_id' => $rid]);

        $show = $this->get(route('audit.show', ['id' => $a->id]))->assertOk();

        /* ① من غيّر */
        $actor = $show->viewData('actor');
        $this->assertSame($this->owner->name, (string) $actor->name, 'الفاعلُ غيرُ مسمّى');
        $this->assertNotEmpty($actor->role_name, 'دورُ الفاعل غيرُ ظاهر');
        $this->ans(self::Q_AUDIT[0], $actor, 'القيدُ بلا فاعلٍ مسمّى');

        /* ② ما الذي تغيّر */
        $diff = $show->viewData('diff');
        $this->assertNotSame([], $diff, 'لا حقولَ متغيّرة');
        // والحقولُ من محرّك الفرق الواحد لا من مقارنةٍ ثانيةٍ في المتحكّم
        $this->assertSame(
            array_column(Audit::diff($a->module,
                $a->getAttributes()['before'] ?? null, $a->getAttributes()['after'] ?? null), 'col'),
            array_column($diff, 'col'),
            'الحقولُ المعروضة لا تساوي Audit::diff');
        $this->ans(self::Q_AUDIT[1], $diff, 'لا بيانَ لما تغيّر');

        /* ③ قبل مقابل بعد */
        $row = $diff[0];
        $this->assertSame('0', (string) $row['from'], 'القيمةُ السابقة مفقودة');
        $this->assertSame('1', (string) $row['to'], 'القيمةُ اللاحقة مفقودة');
        $this->ans(self::Q_AUDIT[2], [$row['from'], $row['to']], 'لا مقارنةَ قبل/بعد');

        /* ④ متى */
        $this->assertNotEmpty($a->created_at, 'قيدٌ بلا زمن');
        $this->ans(self::Q_AUDIT[3], (string) $a->created_at, 'لا زمنَ للقيد');

        /* ⑤ من أيّ عنوانٍ وجهاز */
        $this->assertSame('198.51.100.20', (string) $a->ip, 'العنوانُ لم يُحفظ');
        $this->assertNotNull($a->device, 'الجهازُ لم يُحفظ');
        $this->ans(self::Q_AUDIT[4], (string) $a->ip, 'لا عنوانَ ولا جهازَ للقيد');

        /* ⑥ هل كان حسّاساً — التصنيفُ والرمزُ الأمنيّ من كتالوجهما */
        $class = $show->viewData('class');
        $this->assertNotEmpty($class['severity'], 'لا شدّةَ للقيد');
        $this->assertNotNull($show->viewData('secCode'), 'فعلٌ في الكتالوج الأمنيّ بلا رمز');
        $this->ans(self::Q_AUDIT[5], $class, 'لا تصنيفَ حساسيةٍ للقيد');

        /* ⑦ معرّفُ الطلب — ومعه إخوتُه */
        $this->assertSame($rid, (string) $show->viewData('rid'), 'لا معرّفَ طلبٍ في القيد');
        $this->assertSame(1, (int) $show->viewData('siblings'), 'إخوةُ الطلب لا يُعَدّون');
        $this->assertNotSame([], Correlation::forRequestId($rid, $this->owner),
            'أثرُ الطلب لا يجد شيئاً بمعرّفٍ موجود');
        $this->ans(self::Q_AUDIT[6], $rid, 'لا معرّفَ طلبٍ يربط الطبقات');

        /* ⑧ هل اتّصل بحادثة */
        $rel = $show->viewData('relIncidents');
        $this->assertSame(1, $rel->count(), 'الحادثةُ بمعرّف الطلب نفسِه غيرُ موصولة');
        $this->assertSame('تسريبُ إعدادٍ حسّاس', (string) $rel->first()->title);
        $this->ans(self::Q_AUDIT[7], $rel->all(), 'لا وصلَ بين القيد وحادثته');

        /* ⑨ هل السلسلةُ سليمة — تحقّقٌ موضعيٌّ وذيلُ سلسلةٍ لا فحصٌ كامل في طلب */
        $verify = $show->viewData('verify');
        $this->assertSame('ok', (string) $verify['status'],
            'بصمةُ القيد لا تتحقّق: ' . ($verify['why'] ?? ''));
        $this->assertTrue((bool) Audit::verifyTail()['ok'], 'ذيلُ السلسلة مكسور');
        $this->ans(self::Q_AUDIT[8], $verify, 'لا تحقّقَ من نزاهة السلسلة');

        /* ⑩ عملياتٌ مهمّة بلا تدقيق — محلّلُ التغطية */
        $cov = $this->get(route('audit.coverage'))->assertOk();
        $report = $cov->viewData('report');
        $this->assertArrayHasKey('modules', $report, 'لا تغطيةَ وحداتٍ في التقرير');
        $this->assertArrayHasKey('events', $report, 'لا تغطيةَ صيغٍ أمنية في التقرير');
        $this->assertGreaterThan(0, (int) $report['modules']['total'], 'لا وحداتِ مفحوصة');
        $this->ans(self::Q_AUDIT[9], $report, 'لا محلّلَ تغطيةٍ يكشف ما لا يُدقَّق');

        $this->sealed(self::Q_AUDIT, '§45 التدقيق');
    }

    /* ═════════════════ §46 — القوى العاملة ═════════════════ */

    public function test_section_46_management_can_answer_every_workforce_question(): void
    {
        $this->seedCore();
        $today = now()->toDateString();

        // مُنجَزٌ في الموعد، ومُنجَزٌ متأخّراً، ومتأخّرٌ الآن — بقسمين مختلفَي الحِمل
        Task::create(['title' => 'أُنجزت في موعدها', 'status' => 'مكتملة', 'dept' => 'التطوير',
            'assignee_id' => $this->owner->id, 'due' => now()->subDays(3)->toDateString(),
            'completed_at' => now()->subDays(4)]);
        Task::create(['title' => 'أُنجزت متأخّرةً', 'status' => 'مكتملة', 'dept' => 'التطوير',
            'assignee_id' => $this->owner->id, 'due' => now()->subDays(6)->toDateString(),
            'completed_at' => now()->subDays(2)]);
        Task::create(['title' => 'متأخّرةٌ الآن', 'status' => 'جديدة', 'dept' => 'الدعم',
            'assignee_id' => $this->employee->id, 'due' => now()->subDays(2)->toDateString()]);
        // مهمّةٌ راكدةٌ متوقّفة — «أيُّ الأعمال متعثّرة؟»
        $stalled = Task::create(['title' => 'متوقّفةٌ بانتظار طرفٍ ثالث', 'status' => 'متوقفة',
            'dept' => 'الدعم', 'assignee_id' => $this->employee->id]);
        DB::table('tasks')->where('id', $stalled->id)
            ->update(['updated_at' => now()->subDays(20), 'created_at' => now()->subDays(25)]);

        // ملفّاتُ الموظفين — لوحُ الأقسام وميزانُ الحِمل يقرآن `employees` لا عمودَ
        // `tasks.dept`: القسمُ صفةُ شخصٍ لا صفةُ مهمّة، والطاقةُ تُحسب على رأسٍ عامل
        DB::table('employees')->insert([
            ['id' => (string) Str::uuid(), 'name' => 'مطوّرة', 'user_id' => $this->owner->id,
             'dept' => 'التطوير', 'status' => 'نشط', 'version' => 1, 'archived' => 0,
             'created_at' => now()->subMonths(6), 'updated_at' => now()],
            ['id' => (string) Str::uuid(), 'name' => 'مسانِد', 'user_id' => $this->employee->id,
             'dept' => 'الدعم', 'status' => 'نشط', 'version' => 1, 'archived' => 0,
             'created_at' => now()->subMonths(6), 'updated_at' => now()],
        ]);

        // اعتمادٌ ينتظر الحسم — مرحلةُ انتظارٍ باسمها
        DB::table('approvals')->insert([
            'id' => (string) Str::uuid(), 'title' => 'اعتمادُ صرفٍ معلّق', 'type' => 'صرف',
            'status' => 'معلق', 'created_at' => now()->subDays(9), 'updated_at' => now()->subDays(9),
        ]);

        // زياراتٌ لموظّفٍ — الجانبُ العمليّ في شاشة النشاط
        DB::table('page_visits')->insert([
            ['id' => (string) Str::uuid(), 'user_id' => $this->employee->id, 'path' => '/m/tasks',
             'route' => 'm.index', 'at' => now()->subHours(3)],
            ['id' => (string) Str::uuid(), 'user_id' => $this->employee->id, 'path' => '/m/tickets',
             'route' => 'm.index', 'at' => now()->subHours(2)],
        ]);

        $res = $this->actingAs($this->owner)->get(route('workforce.overview', ['range' => '30d']))->assertOk();
        $x = $res->viewData('x');
        $bn = $res->viewData('bn');
        $range = $res->viewData('range');

        // لا رقمَ يُعاد حسابُه: الشاشةُ إسقاطٌ لـ`ExecutionStats::org` حرفياً
        $engine = ExecutionStats::org($range, $this->owner);
        $this->assertSame($engine['overdue'], $x['overdue'], 'المتأخّرُ لا يساوي ExecutionStats::org');
        $this->assertSame($engine['completed']['cur'], $x['completed']['cur'],
            'المنجَزُ لا يساوي ExecutionStats::org');

        /* ① ما الذي أُنجز */
        $this->assertSame(2.0, (float) $x['completed']['cur'], 'المنجَزُ لا يُعَدّ من completed_at');
        $this->ans(self::Q_WORKFORCE[0], $x['completed'], 'لا عدّادَ إنجاز');

        /* ② ما المتأخّر */
        $this->assertSame(1, (int) $x['overdue'], 'المتأخّرُ الآن لا يُعَدّ');
        $this->ans(self::Q_WORKFORCE[1], $x['overdue'], 'لا عدّادَ تأخّر');

        /* ③ الأعمالُ المتعثّرة — الراكدُ ومراحلُ الانتظار */
        $this->assertNotSame([], $bn['stalled'], 'لا كشفَ للمهامّ الراكدة');
        $this->assertNotSame([], $bn['waiting'], 'لا مراحلَ انتظارٍ مقيسة');
        $this->ans(self::Q_WORKFORCE[2], $bn['stalled'], 'لا كشفَ لما هو متعثّر');

        /* ④ اختلالُ الحِمل بين الفرق */
        $depts = collect($x['departments'])->pluck('dept')->all();
        $this->assertContains('التطوير', $depts, 'قسمٌ له مهامٌّ ورأسٌ عامل وغائبٌ عن اللوح');
        $this->assertContains('الدعم', $depts, 'قسمُ الدعم غائبٌ عن اللوح');
        $this->assertNotNull($x['load']['spread'], 'لا مدى تفاوتٍ في الحِمل بين المكلَّفين');
        $this->ans(self::Q_WORKFORCE[3], $x['departments'], 'لا قياسَ لاختلال الحِمل');

        /* ⑤ نسبةُ الالتزام بالمواعيد */
        $this->assertSame(50, (int) $x['on_time']['pct'], 'الالتزامُ لا يُحسب من ذوات المواعيد');
        $this->ans(self::Q_WORKFORCE[4], $x['on_time']['pct'], 'لا نسبةَ التزام');

        /* ⑥ الاختناقات */
        foreach (['dwell', 'blockers', 'reopened', 'signals'] as $k) {
            $this->assertArrayHasKey($k, $bn, "قارئُ الاختناقات بلا محورِ «{$k}»");
        }
        $this->ans(self::Q_WORKFORCE[5], $bn, 'لا قارئَ اختناقات');

        /* ⑦ الأمنُ مفصولٌ عن الإنتاجية */
        $act = $this->get(route('activity.show', ['id' => $this->employee->id]))->assertOk();
        $risk = $act->viewData('risk');
        $work = $act->viewData('work');
        $this->assertArrayHasKey('score', $risk, 'لا درجةَ أمنيةٍ للنشاط');
        $this->assertArrayHasKey('in_h', $work, 'لا قياسَ ساعاتِ عملٍ داخل الدوام');
        // الحدُّ الفاصل بالحرف: الحكمُ الأمنيّ (درجةٌ ومكوّناتُها ومعدّلُ التلاعب)
        // لا يسكن بطاقةَ العمل، وساعاتُ الدوام لا تسكن بطاقةَ الخطر
        foreach (['score', 'parts', 'tamper', 'tone'] as $k) {
            $this->assertArrayNotHasKey($k, $work, "حكمٌ أمنيٌّ «{$k}» تسرّب إلى قياس الإنتاجية");
        }
        $this->assertArrayNotHasKey('in_h', $risk, 'ساعاتُ الدوام تسرّبت إلى الدرجة الأمنية');
        $this->ans(self::Q_WORKFORCE[6], [$risk['score'], $work['in_h']],
            'الأمنُ والإنتاجيةُ في سلّةٍ واحدة');

        $this->sealed(self::Q_WORKFORCE, '§46 القوى العاملة');
    }

    /* ═════════════════ §47 — الجودة ═════════════════ */

    public function test_section_47_management_can_answer_every_quality_question(): void
    {
        $this->seedCore();

        // ① نقصُ بياناتٍ حقيقيّ: مهمّةٌ تشير إلى مسؤولٍ لا وجود له (مرجعٌ مكسور)
        Task::create(['title' => 'مهمّةٌ بمرجعٍ مكسور', 'status' => 'جديدة',
            'assignee_id' => (string) Str::uuid(), 'due' => now()->subDays(5)->toDateString()]);
        Task::create(['title' => 'مهمّةٌ متأخّرةٌ ثانية', 'status' => 'جديدة',
            'assignee_id' => $this->owner->id, 'due' => now()->subDays(3)->toDateString()]);

        // ② لقطتان لكل وحدةٍ ⇒ اتّجاهٌ محسوبٌ لا مُخترَع: واحدةٌ تتدهور وأخرى تتحسّن
        foreach ([['tasks', 4, 9], ['tickets', 8, 2]] as [$mod, $was, $isNow]) {
            hub_metric_put('quality', $mod, 'defects', (float) $was, now()->subDays(10)->startOfDay(), 'auto');
            hub_metric_put('quality', $mod, 'defects', (float) $isNow, now()->startOfDay(), 'auto');
            hub_metric_put('quality', $mod, 'score', 100.0 - $was, now()->subDays(10)->startOfDay(), 'auto');
            hub_metric_put('quality', $mod, 'score', 100.0 - $isNow, now()->startOfDay(), 'auto');
        }

        // ③ مؤشّرٌ خارج هدفه، وهدفٌ متعثّرٌ معلَنٌ في السجل
        $kpi = KpiDef::create(['name' => 'مشاريعُ جارية', 'unit' => 'مشروع', 'target' => 10, 'good' => 'up',
            'formula' => ['a' => ['agg' => 'count', 'module' => 'projects', 'col' => null,
                                  'st' => 'قيد التنفيذ'], 'combine' => 'none'], 'sort' => 0]);
        $obj = Objective::create(['title' => 'هدفٌ متعثّر', 'level' => 'الشركة', 'period' => 'ر١',
            'status' => 'متعثر', 'date_start' => now()->subDays(30)->toDateString(),
            'due' => now()->addDays(30)->toDateString()]);
        KeyResult::create(['objective_id' => $obj->id, 'title' => 'نتيجةٌ رئيسة',
            'start_value' => 0, 'target_value' => 100, 'current_value' => 20,
            'weight' => 1, 'source' => 'manual']);

        $this->actingAs($this->owner);

        /* ① مشكلاتُ جودة البيانات */
        $data = $this->get(route('quality.index', ['tab' => 'data']))->assertOk();
        $checks = $data->viewData('checks');
        $this->assertNotSame([], $checks, 'مسحُ الجودة بلا نتائج رغم مرجعٍ مكسور');
        $this->assertSame(DataQuality::scan()['totals'], $data->viewData('totals'),
            'مجاميعُ الجودة لا تساوي DataQuality::scan');
        $this->ans(self::Q_QUALITY[0], $checks, 'لا مسحَ جودةٍ يُظهر نقصاً');

        /* ② أيُّ الوحدات تتدهور */
        $trends = $this->get(route('quality.index', ['tab' => 'trends']))->assertOk();
        $trend = $trends->viewData('dqTrend');
        $this->assertSame(DataQuality::moduleTrend(), $trend, 'الاتّجاهُ لا يأتي من DataQuality::moduleTrend');
        $this->assertSame('worsening', $trend['tasks']['dir'] ?? null, 'وحدةٌ تدهورت ولم تُوسَم');
        $this->assertSame(5, (int) ($trend['tasks']['delta'] ?? 0), 'الفرقُ لا يُحسب من طرفَي السلسلة');
        $this->ans(self::Q_QUALITY[1], $trend['tasks'], 'لا كشفَ لوحدةٍ تتدهور');

        /* ③ ما الذي تحسّن */
        $this->assertSame('improving', $trend['tickets']['dir'] ?? null, 'وحدةٌ تحسّنت ولم تُوسَم');
        $this->ans(self::Q_QUALITY[2], $trend['tickets'], 'لا كشفَ لوحدةٍ تحسّنت');

        /* ④ ما الأعمالُ المتأخّرة */
        $exec = $this->get(route('quality.index', ['tab' => 'execution']))->assertOk();
        $summary = $exec->viewData('ex')['summary'];
        $range = $exec->viewData('range');
        $this->assertSame(ExecutionStats::executionSummary($range)['overdue'], $summary['overdue'],
            'المتأخّرُ لا يساوي ExecutionStats::executionSummary');
        $this->assertSame(2, (int) $summary['overdue'], 'المتأخّرُ المبذور لا يُعَدّ');
        $this->ans(self::Q_QUALITY[3], $summary['overdue'], 'لا عدّادَ عملٍ متأخّر');

        /* ⑤ المؤشّراتُ خارج الهدف */
        $kpiTab = $this->get(route('quality.index', ['tab' => 'kpi']))->assertOk();
        $off = $kpiTab->viewData('kpiOff');
        $this->assertSame(KpiCentre::offTarget(KpiCentre::rows($this->owner)), $off,
            'قائمةُ ما هو خارج الهدف لا تأتي من KpiCentre');
        $this->assertTrue(collect($off)->contains(fn ($r) => $r['id'] === $kpi->id),
            'مؤشّرٌ قيمتُه صفرٌ وهدفُه عشرةٌ ولم يُعَدّ خارجَ هدفه');
        $this->ans(self::Q_QUALITY[4], $off, 'لا كشفَ لمؤشّرٍ خارج هدفه');

        /* ⑥ الأهدافُ المتعثّرة */
        $okrTab = $this->get(route('quality.index', ['tab' => 'okr']))->assertOk();
        $board = $okrTab->viewData('okr');
        $this->assertSame(1, (int) $board['blocked'], 'الهدفُ المُعلَن متعثّراً لم يُحصَ');
        $this->assertTrue(collect($board['attention'])->contains(fn ($r) => $r['o']->id === $obj->id),
            'الهدفُ المتعثّر غائبٌ عمّا يستدعي نظرة');
        $this->ans(self::Q_QUALITY[5], $board['attention'], 'لا كشفَ لهدفٍ متعثّر');

        /* ⑦ مهامُّ المعالجة — بالمسار القائم لا بجدولٍ ثانٍ */
        $this->post(route('remediation.store'), ['kind' => 'kpi', 'ref' => $kpi->id])
            ->assertRedirect();
        $task = Remediation::existing('kpi:' . $kpi->id);
        $this->assertNotNull($task, 'زرُّ المعالجة لم يفتح مهمّة');
        $this->assertSame('kpi', (string) ($task->meta['origin']['kind'] ?? ''),
            'المهمّةُ بلا رابطٍ عكسيٍّ إلى نتيجتها');
        // والنقرةُ الثانية تفتح الأولى لا نسخةً ثانية
        $this->post(route('remediation.store'), ['kind' => 'kpi', 'ref' => $kpi->id])->assertRedirect();
        $this->assertSame(1, Task::where('meta->origin->key', 'kpi:' . $kpi->id)->count(),
            'النقرةُ الثانية فتحت مهمّةً ثانية');
        $this->ans(self::Q_QUALITY[6], $task->id, 'لا مهامَّ معالجةٍ تُفتح من النتائج');

        $this->sealed(self::Q_QUALITY, '§47 الجودة');
    }

    /* ═════════════════ §48 — الإعدادات ═════════════════ */

    public function test_section_48_the_owner_can_answer_every_settings_question(): void
    {
        $this->seedCore();
        $this->actingAs($this->owner);

        // مفتاحٌ **يُختار من الكتالوج نفسِه** لا يُثبَّت باسمه: أيُّ مفتاحٍ معروضٍ
        // نصّيٍّ غيرِ سرٍّ ولا خطرٍ ولا مملوكٍ لشاشةٍ أخرى — فالاختبارُ يبقى صادقاً
        // ولو أُعيدت تسميةُ مفتاحٍ بعينه.
        $key = null;
        foreach (Settings::catalog() as $items) {
            foreach ($items as $k => $meta) {
                if (! empty($meta['readonly']) || ! empty($meta['sensitive'])) continue;
                if (! in_array((string) ($meta['type'] ?? 'text'), ['text', 'number'], true)) continue;
                if (Settings::isHighRisk($k) || Settings::isState($k)) continue;
                if (($meta['effect'] ?? '') === '' || ! array_key_exists('default', $meta)) continue;
                if (isset($meta['validation']['re']) || isset($meta['depends'])) continue;
                $key = $k;
                break 2;
            }
        }
        $this->assertNotNull($key, 'لا مفتاحَ معروضٌ صالحٌ لسؤال القبول — الكتالوجُ ناقص');
        $entry = Settings::entry($key);

        $edit = $this->get(route('settings.edit'))->assertOk();
        $facts = $edit->viewData('facts');

        /* ① ما هذا الإعداد — تسميةٌ وأثرٌ مكتوبان لا إعادةُ صياغةٍ للاسم */
        $this->assertNotSame('', (string) $entry['label'], 'مفتاحٌ بلا تسمية');
        $this->assertNotSame('', (string) $entry['effect'], 'مفتاحٌ بلا بيانِ أثر');
        $this->ans(self::Q_SETTINGS[0], $entry['effect'], 'الكتالوجُ لا يشرح المفتاح');

        /* ② ما افتراضيُّه — قيمةٌ آليّةٌ لا نثر */
        $this->assertArrayHasKey($key, $facts, 'المفتاحُ بلا بطاقةِ حقائق');
        $this->assertArrayHasKey('default', $facts[$key], 'بطاقةُ الحقائق بلا خانةِ افتراضيّ');
        $this->assertSame(Settings::effective($key)['default'], $facts[$key]['default'],
            'الافتراضيُّ المعروض لا يساوي Settings::effective');
        // والافتراضيُّ **قيمةٌ آليّة** لا نثر: `''` جوابٌ («افتراضيُّه الفراغ») ما
        // دام الكتالوجُ يُعلنه صراحةً — والغيابُ وحدَه هو اللاجواب
        $this->assertSame(Settings::flat($entry['default']), $facts[$key]['default'],
            'الافتراضيُّ المعروض لا يساوي المُعلَن في الكتالوج');
        $this->ans(self::Q_SETTINGS[1], ['default' => $facts[$key]['default']], 'لا افتراضيَّ مُعلَن');

        /* ③ ما الساري ومن أين */
        $this->assertContains($facts[$key]['source'], ['database', 'environment', 'default', 'module'],
            'مصدرُ القيمة غيرُ مصرَّحٍ به');
        $this->ans(self::Q_SETTINGS[2], $facts[$key]['source'], 'لا بيانَ للقيمة السارية ومصدرها');

        /* ④ ماذا يحدث إن غيّرتُه — معاينةٌ تُظهر الفرقَ **قبل** الكتابة */
        $newValue = ((string) (Settings::effective($key)['effective'] ?? '')) === 'قيمةٌ للقبول'
            ? 'قيمةٌ أخرى للقبول' : 'قيمةٌ للقبول';
        $this->post(route('settings.preview'), [str_replace('.', '_', $key) => $newValue])
            ->assertRedirect();
        $plan = session('settings.preview');
        $this->assertNotNull($plan, 'المعاينةُ لم تُحفظ خطّةً');
        $this->assertTrue(collect($plan['rows'])->contains(fn ($r) => $r['key'] === $key),
            'خطّةُ المعاينة لا تذكر المفتاحَ المقصود');
        $this->assertSame(Settings::effective($key)['stored'] ?? null,
            Settings::rows()[$key] ?? null, 'المعاينةُ كتبت — وهي معاينة');
        $this->ans(self::Q_SETTINGS[3], $plan['rows'], 'لا معاينةَ أثرٍ قبل الحفظ');

        /* ⑤ هل هو خطر — من الكتالوج، ومفتاحٌ عاليُ الخطورة يُعرَف كذلك */
        $this->assertFalse(Settings::isHighRisk($key), 'المفتاحُ المختار كان خطراً — اختيارٌ خاطئ');
        $risky = collect(Settings::catalog())->flatMap(fn ($i) => array_keys($i))
            ->first(fn ($k) => Settings::isHighRisk($k));
        $this->assertNotNull($risky, 'لا مفتاحَ يُعرَّف خطراً في الكتالوج كلِّه');
        $this->ans(self::Q_SETTINGS[4], [$key => $entry['risk'] ?? '', 'خطر' => $risky],
            'لا وسمَ خطورةٍ للمفاتيح');

        /* ⑥ من غيّره آخرَ مرّة — بالكاتب الواحد */
        $this->assertTrue(Settings::put($key, $newValue, 'test', 'اختبارُ قبول'),
            'الكاتبُ الواحد لم يكتب');
        $last = Settings::lastChanges([$key])[$key] ?? null;
        $this->assertNotNull($last, 'لا تاريخَ تغييرٍ للمفتاح');
        $this->assertSame((string) $this->owner->id, (string) ($last['user_id'] ?? ''),
            'المُغيِّرُ غيرُ مسمّى');
        $fresh = $this->get(route('settings.edit'))->assertOk();
        $this->assertArrayHasKey($key, $fresh->viewData('lastBy'), 'الشاشةُ لا تعرض من غيّر');
        $this->ans(self::Q_SETTINGS[5], $last, 'لا سجلَّ لمن غيّر المفتاح');

        /* ⑦ الاستعادةُ الآمنة — **كتابةُ الافتراضي لا حذفُ الصفّ** (critic #7) */
        $default = Settings::restoreValue($key);
        $this->post(route('settings.restore'), ['key' => $key])->assertRedirect();
        $this->assertArrayHasKey($key, Settings::rows(),
            'الاستعادةُ حذفت الصفَّ — وحذفُه يُعيد إشعالَ ما أطفأه المالك');
        $this->assertSame($default, (string) (Settings::rows()[$key] ?? ''),
            'الاستعادةُ لم تكتب الافتراضيَّ المُعلَن');
        $this->ans(self::Q_SETTINGS[6], $default === '' ? true : $default, 'لا استعادةَ للافتراضي');

        /* ⑧ هل التكاملُ المربوط يعمل — من سجل التكاملات الواحد */
        $ints = Integrations::installed();
        $this->assertNotSame([], $ints, 'لا سجلَّ تكاملات');
        foreach ($ints as $k => $i) {
            $this->assertArrayHasKey('health', $i, "تكاملُ «{$k}» بلا حالةِ صحّة");
            $this->assertContains($i['health'], array_keys(Integrations::HEALTH_LABELS),
                "حالةُ تكامل «{$k}» خارج المفردات المعلَنة");
        }
        $this->get(route('integrations.index'))->assertOk();
        $this->ans(self::Q_SETTINGS[7], $ints, 'لا حالةَ صحّةٍ للتكاملات');

        /* ⑨ التصديرُ الآمن — بلا سرٍّ، ومُصَدٌّ حين يُجمَّد التصدير */
        $body = $this->get(route('settings.export'))->assertOk()->getContent();
        $payload = json_decode($body, true);
        $this->assertIsArray($payload['settings'] ?? null, 'حمولةُ التصدير بلا مفاتيح');
        $this->assertStringNotContainsString('enc:', $body, 'سرٌّ مشفَّرٌ خرج في التصدير');
        foreach (Settings::secrets() as $s) {
            $this->assertArrayNotHasKey($s, $payload['settings'], "مفتاحُ سرٍّ «{$s}» في التصدير");
        }
        $this->hubSetting('security.freeze_exports', '1');
        $this->get(route('settings.export'))->assertStatus(423);
        $this->ans(self::Q_SETTINGS[8], array_keys($payload['settings']), 'لا تصديرَ آمنٌ للتهيئة');

        $this->sealed(self::Q_SETTINGS, '§48 الإعدادات');
    }

    /* ═════════ ختمُ السَّبع: مستوى التحكّم يجمعها في مفترقٍ واحد ═════════ */

    /**
     * **ونظرةُ التحكّم تُحيل إلى المراكز السبعة كلِّها** (§32): سؤالُ القبول
     * الأخير ليس رقماً بل طريقاً — أن يجد المالكُ من صفحةٍ واحدةٍ بابَ كلِّ مركزٍ
     * سُئل عنه أعلاه. (الأرقامُ نفسُها يحرسها `ControlHomeTest`.)
     */
    public function test_the_control_overview_reaches_every_centre_the_acceptance_asks_about(): void
    {
        $this->seedCore();

        $html = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->getContent();

        $doors = [
            '§42 الأمن'      => route('security.findings'),
            '§43 التشغيل'    => route('ops.index'),
            '§44 الأخطاء'    => route('errors.index'),
            '§45 التدقيق'    => route('audit.coverage'),
            '§47 الجودة'     => route('quality.index', ['tab' => 'data']),
            '§46/§47 التنفيذ' => route('quality.index', ['tab' => 'execution']),
        ];
        $shut = [];
        foreach ($doors as $label => $url) {
            if (! str_contains($html, htmlspecialchars($url, ENT_QUOTES))) $shut[] = $label;
        }
        $this->assertSame([], $shut, 'مراكزُ لا يصلها مستوى التحكّم: ' . implode(' · ', $shut));

        // و§48 الإعدادات بابُها الشريطُ لا البطاقات — والكتالوجُ الواحد يحمله
        $keys = array_column(hub_admin_links($this->owner), 'key');
        foreach (['settings', 'control', 'incidents', 'alerts'] as $k) {
            $this->assertContains($k, $keys, "الكتالوجُ الواحد بلا مدخلٍ لـ«{$k}»");
        }
    }
}
