<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\ControlController;
use App\Models\Task;
use App\Support\Audit;
use App\Support\DataQuality;
use App\Support\ErrorStats;
use App\Support\ExecutionStats;
use App\Support\Health;
use App\Support\SecurityFindings;
use App\Support\SecurityPosture;
use App\Support\TimeRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * **نظرةُ التحكّم (WP-10.1 · spec §32 · §12).**
 *
 * ما يحرسه هذا الملف — أربعةٌ لا خامسَ لها:
 *
 *  ١) **بطاقةٌ تربط بمركزها.** الصفحةُ مفترقُ طرقٍ لا لوحةٌ عملاقة (§32): كلُّ
 *     بطاقةٍ تقول رقماً واحداً وتحيل إلى المركز الذي يملك التفصيل. بطاقةٌ بلا
 *     رابطٍ طريقٌ مسدود، وتفصيلٌ مكرَّرٌ هنا نسخةٌ ثانيةٌ تتباعد عن أصلها.
 *
 *  ٢) **كلُّ رقمٍ يساوي قارئَه حرفياً.** لا حسابَ في المتحكّم ولا رقمَ مركّب:
 *     الاختبارُ ينادي المحرّكَ نفسَه ويقارن — فأيُّ إعادةِ حسابٍ تُسقطه.
 *
 *  ٣) **الحارس.** الصفحةُ للمالك، ولحاملِ راية المراقبة **ما تمنحه ق١ وحده**
 *     (النتائجُ الأمنية والجودةُ والتنفيذ) — لا التشغيلُ ولا الأخطاءُ ولا
 *     التدقيق. والموظّفُ العاديّ يُصَدّ بـ٤٠٣.
 *
 *  ٤) **ميزانيةُ الاستعلامات دافئةً.** `Health::check` وحدَه عشراتُ الاستعلامات،
 *     فالصفحةُ مخبّأةٌ ٦٠ ثانية بختمٍ ظاهر — والفتحُ الثاني في الدقيقة نفسِها
 *     يجب أن يبقى بالعشرين لا بالمئة.
 *
 *  ٥) **مركزٌ بلا بياناتٍ بعدُ يقول ذلك صادقاً** (§26): «—» لا صفراً — فالصفرُ
 *     يُقرأ شهادةَ سلامةٍ وهو غيابُ قياس.
 */
class ControlHomeTest extends TestCase
{
    /** @return array{0:mixed,1:int} النتيجة وعددُ الاستعلامات */
    private function counted(\Closure $fn): array
    {
        $count = false;
        $n = 0;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $out = $fn();
        $count = false;

        return [$out, $n];
    }

    /** عالمٌ فيه ما يُغذّي البطاقات الست: نتيجةٌ أمنية، وخطأٌ حرج، وتحقّقٌ، ونقصُ جودة، ومهمّةٌ متأخّرة */
    private function seedCentres(): void
    {
        $now = now();

        DB::table('security_findings')->insert([
            'id' => (string) Str::uuid(), 'code' => 'debug_mode', 'entity_type' => 'org', 'entity_id' => '',
            'severity' => 'critical', 'title' => 'وضعُ التنقيح مفعّل', 'description' => 'المكدّسُ مكشوف.',
            'remediation' => 'أطفئ APP_DEBUG.', 'status' => 'open',
            'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('error_events')->insert([
            'id' => (string) Str::uuid(), 'hash' => str_repeat('b', 64), 'kind' => 'php',
            'message' => 'عطلٌ حرج', 'severity' => 'CRITICAL', 'status' => 'جديد', 'count' => 3,
            'first_seen' => $now, 'last_seen' => $now,
        ]);

        DB::table('audit_verifications')->insert([
            'mode' => 'auto', 'started_at' => $now, 'finished_at' => $now, 'duration_ms' => 10,
            'result' => 'ok', 'checked_rows' => 5, 'message' => 'السلسلة سليمة',
        ]);

        // مهمّةٌ فات موعدُها (عدّادُ التنفيذ) ومرجعٌ مكسور (عدّادُ الجودة)
        Task::create(['title' => 'مهمّةٌ متأخّرة', 'status' => 'جديدة',
            'assignee_id' => (string) Str::uuid(), 'due' => now()->subDays(4)->toDateString()]);

        DB::table('incidents')->insert([
            'id' => (string) Str::uuid(), 'title' => 'انقطاعُ خدمةٍ جارٍ', 'status' => 'قيد المعالجة',
            'severity' => 'حرج', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** ① كلُّ بطاقةٍ تربط بمركزها — الروابطُ الستّة حاضرةٌ في صفحة المالك */
    public function test_every_card_links_to_the_centre_that_owns_its_number(): void
    {
        $this->seedCore();
        $this->seedCentres();

        $html = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->getContent();

        $missing = [];
        foreach ([
            'الأمن'    => route('security.findings'),
            'التشغيل'  => route('ops.index'),
            'الأخطاء'  => route('errors.index'),
            'التدقيق'  => route('audit.coverage'),
            'الجودة'   => route('quality.index', ['tab' => 'data']),
            'التنفيذ'  => route('quality.index', ['tab' => 'execution']),
        ] as $label => $url) {
            if (! str_contains($html, htmlspecialchars($url, ENT_QUOTES))) $missing[] = "$label ← $url";
        }

        $this->assertSame([], $missing, 'بطاقاتٌ بلا رابطٍ إلى مركزها: ' . implode(' · ', $missing));
    }

    /** ② كلُّ رقمٍ يساوي قارئَه — لا حسابَ ثانٍ في المتحكّم */
    public function test_every_number_equals_its_engine_called_directly(): void
    {
        $this->seedCore();
        $this->seedCentres();

        $res = $this->actingAs($this->owner)->get(route('control.index'))->assertOk();
        $cards = $res->viewData('cards');

        $this->assertSame(SecurityPosture::summary()['score'], $cards['security']['score'],
            'درجةُ الوضعية لا تساوي SecurityPosture::summary');
        $this->assertSame((int) (SecurityFindings::openCounts()['critical'] ?? 0), $cards['security']['critical'],
            'النتائجُ الحرجة لا تساوي SecurityFindings::openCounts');

        $this->assertSame(Health::check()['status'], $cards['operations']['health'],
            'حالةُ التشغيل لا تساوي Health::check');

        $this->assertSame(ErrorStats::cards()['critical'], $cards['errors']['critical'],
            'الأخطاءُ الحرجة لا تساوي ErrorStats::cards');

        $this->assertSame(DataQuality::scan()['totals']['score'], $cards['quality']['score'],
            'درجةُ الجودة لا تساوي DataQuality::scan');

        $range = TimeRange::fromRequest(null, ControlController::RANGE);
        $this->assertSame(ExecutionStats::executionSummary($range)['overdue'], $cards['execution']['overdue'],
            'المتأخّرُ لا يساوي ExecutionStats::executionSummary');

        $latest = DB::table('audit_verifications')->orderByDesc('started_at')->orderByDesc('id')->first();
        $this->assertSame($latest->result, $cards['audit']['result'],
            'نتيجةُ التدقيق لا تساوي آخرَ صفٍّ في audit_verifications');

        // والحادثةُ النشطة من سكّة «المفتوح» الواحدة لا من عدٍّ مخترَع
        $this->assertSame(
            (int) hub_open_scope(hub_scope(DB::table('incidents')->whereNull('deleted_at'), 'incidents'))->count(),
            $cards['operations']['incidents']);
    }

    /** **ولا فحصَ سلسلةٍ كاملاً في طلب**: بطاقةُ التدقيق تقرأ آخرَ صفِّ تحقّق، وعند غيابه ذيلَ السلسلة */
    public function test_the_audit_card_falls_back_to_the_tail_when_no_verification_ran(): void
    {
        $this->seedCore();

        $cards = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->viewData('cards');

        $this->assertNull($cards['audit']['result'], 'لا تشغيلَ تحقّقٍ بعد — فلا نتيجةَ تُدَّعى');
        $this->assertSame(Audit::verifyTail()['ok'], $cards['audit']['tail_ok'],
            'الارتدادُ يجب أن يكون إلى Audit::verifyTail لا إلى فحصٍ كامل');
    }

    /** ③ الحارس: الموظّفُ يُصَدّ، والمراقبُ يرى ما تمنحه ق١ وحدَه */
    public function test_an_ordinary_employee_is_refused(): void
    {
        $this->seedCore();

        $this->actingAs($this->employee)->get(route('control.index'))->assertForbidden();
    }

    public function test_a_monitor_sees_only_the_subset_decision_one_grants(): void
    {
        $this->seedCore();
        $this->seedCentres();

        $role = \App\Models\Role::create(['name' => 'مراقبٌ عامّ', 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(hub_modules()))->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $mon = \App\Models\User::create(['name' => 'مراقب', 'email' => 'mon@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now()]);

        $res = $this->actingAs($mon)->get(route('control.index'))->assertOk();
        $cards = $res->viewData('cards');
        $html = $res->getContent();

        foreach (['security', 'quality', 'execution'] as $k) {
            $this->assertTrue($cards[$k]['visible'], "بطاقة $k ممنوحةٌ للمراقب بق١ وهي محجوبة");
        }
        foreach (['operations', 'errors', 'audit'] as $k) {
            $this->assertFalse($cards[$k]['visible'], "بطاقة $k للمالك وحدَه وقد ظهرت للمراقب");
        }

        $this->assertStringNotContainsString(htmlspecialchars(route('ops.index'), ENT_QUOTES), $html);
        $this->assertStringNotContainsString(htmlspecialchars(route('errors.index'), ENT_QUOTES), $html);
    }

    /**
     * **العدّادُ لا يتجاوز نطاقَ قارئه** (§41/٤ · ق١ · critic #9): بطاقةُ الأمن
     * تُعرض لحاملِ راية المراقبة، ومركزُ النتائج نفسُه يُنطِّق قائمتَه **وعدّاداتِه**
     * بشركات القارئ. فرقمٌ هنا يعدّ نتائجَ شركةٍ لا يراها هو تسريبٌ صامت — ويكذب
     * مرتين: يقول «ثلاثٌ حرجة» ثم يفتح المركزَ على واحدة.
     */
    public function test_the_security_card_counts_only_the_findings_this_reader_may_see(): void
    {
        $this->seedCore();

        $mine = \App\Models\Company::create(['name_ar' => 'شركتي']);
        $theirs = \App\Models\Company::create(['name_ar' => 'شركةٌ أخرى']);

        $now = now();
        foreach ([['tokens_no_expiry', $mine->id], ['secret_stale', $theirs->id]] as [$code, $cid]) {
            DB::table('security_findings')->insert([
                'id' => (string) Str::uuid(), 'code' => $code, 'entity_type' => 'secret',
                'entity_id' => (string) Str::uuid(), 'severity' => 'critical',
                'title' => 'نتيجةٌ حرجة', 'status' => 'open', 'company_id' => $cid,
                'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $role = \App\Models\Role::create(['name' => 'مراقبٌ معزول', 'scope' => 'all',
            'flags' => ['monitor' => 1],
            'matrix' => collect(array_keys(hub_modules()))->mapWithKeys(fn ($m) => [$m => ['v' => 1]])->all()]);
        $mon = \App\Models\User::create(['name' => 'مراقب معزول', 'email' => 'mon2@test.local',
            'password' => 'Secret!2026x', 'role_id' => $role->id, 'status' => 'نشط',
            'password_changed_at' => now(), 'companies' => [$mine->id]]);

        $cards = $this->actingAs($mon)->get(route('control.index'))->assertOk()->viewData('cards');

        $this->assertTrue($cards['security']['visible'], 'بطاقةُ الأمن ممنوحةٌ للمراقب بق١');
        $this->assertSame(1, $cards['security']['critical'],
            'عدّادُ البطاقة عدَّ نتيجةَ شركةٍ لا يراها القارئ — تسريبٌ عبر رقمٍ مجمَّع');

        // والمالكُ غيرُ المعزول يرى الاثنتين — التنطيقُ لا يُخفي عمّن يملك الرؤية
        $ownerCards = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->viewData('cards');
        $this->assertSame(2, $ownerCards['security']['critical']);
    }

    /** ④ ميزانيةُ الاستعلامات دافئةً — الخبيئةُ ٦٠ ثانية وإلا فالصفحةُ عبءٌ لا أداة */
    public function test_the_warm_screen_stays_within_its_query_budget(): void
    {
        $this->seedCore();
        $this->seedCentres();
        $this->actingAs($this->owner);

        $this->get(route('control.index'))->assertOk();          // فتحٌ بارد يملأ الخبايا
        [, $warm] = $this->counted(fn () => $this->get(route('control.index'))->assertOk());

        $this->assertLessThanOrEqual(25, $warm,
            "نظرةُ التحكّم الدافئة كلّفت {$warm} استعلاماً والميزانية ٢٥ — قسمٌ غيرُ مخبّأ تسلّل");
    }

    /**
     * **ولا يُحسب نموذجُ الصحّة مرّتين في الفتحة الواحدة.**
     *
     * `Health::check()` أثقلُ قارئٍ في هذه الصفحة (عشراتُ الاستعلامات بلا خبيئةٍ
     * داخلية)، ويطلبه اثنان في الطلب نفسِه: بطاقةُ التشغيل، وصفُّ التدخّل
     * (`AttentionQueue` عبر `ActionCenter::signals`). فحسابُه مرّتين إعادةُ حسابٍ
     * صريحةٌ يمنعها انضباطُ هذا الطور — والنمطُ المتّبع في المستودع سلفاً هو
     * تمريرُ النموذج المحسوب (`AlertEngine::detect($rules, $health)`).
     *
     * **والعدُّ ببصمةٍ لا بعدّاد:** `Health::db()` وحدَه يُصدر `select 1` حرفياً،
     * ولا مصدرَ آخرَ لها في هذه الصفحة — فعددُها عددُ تشغيلات الفحص.
     */
    public function test_the_health_model_is_computed_once_per_cold_open(): void
    {
        $this->seedCore();
        $this->seedCentres();
        $this->actingAs($this->owner);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('control.index'))->assertOk();      // فتحةٌ باردة: الخبايا فارغة
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $probes = count(array_filter($log, fn ($q) => trim($q['query']) === 'select 1'));
        $this->assertSame(1, $probes,
            "نموذجُ الصحّة حُسب {$probes} مرّاتٍ في فتحةٍ واحدة — بطاقةُ التشغيل وصفُّ "
            . 'التدخّل يقرآن Health::check كلٌّ على حدة بدل أن يتقاسما نموذجاً واحداً');
    }

    /** ⑤ مركزٌ لم يبدأ بعدُ يقول «—» لا صفراً */
    public function test_a_centre_with_no_data_yet_says_so_instead_of_showing_a_zero(): void
    {
        $this->seedCore();

        $cards = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->viewData('cards');

        $this->assertNull($cards['security']['critical'],
            'لا سجلَّ نتائجَ بعد — العددُ يجب أن يكون «لا قياس» لا صفراً');
        $this->assertSame('—', $cards['security']['findings_value'],
            'صفرٌ معروضٌ مكانَ «لم تُسوَّ النتائجُ بعد»');
        $this->assertNull($cards['audit']['result']);

        $html = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->getContent();
        $this->assertStringContainsString('—', $html);
    }

    /** ولا سرَّ في الشاشة: لا رمزَ ولا بصمةَ ولا مسارَ ملفٍّ مطلق */
    public function test_the_screen_leaks_no_secret(): void
    {
        $this->seedCore();
        $this->seedCentres();

        $html = $this->actingAs($this->owner)->get(route('control.index'))->assertOk()->getContent();

        foreach (['APP_KEY', 'base64:', 'token_hash', 'secret_cipher'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "تسريبٌ محتمل: $needle");
        }
        // `lyn_` وحدَها ليست دليلاً (القشرةُ تكتب `lyn_theme` في التخزين المحلي) —
        // المطلوبُ شكلُ الرمز نفسِه: بادئةٌ يتبعها ذيلٌ طويل
        $this->assertSame(0, preg_match('/lyn_[A-Za-z0-9]{20,}/', $html), 'رمزُ API صريحٌ في الصفحة');
    }
}
