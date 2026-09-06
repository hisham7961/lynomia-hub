<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\SessionLog;
use App\Support\SecurityFindings;
use App\Support\SecurityPosture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WP-4.5 — لوحةُ القيادة الأمنية (§2.1): ١٥ بطاقة `cc/kpis` على مركز الأمان.
 *
 * القواعد المُثبَتة هنا:
 *  - كلُّ بطاقةٍ تساوي استعلامَها المرجعيّ — الأرقامُ من المصادر الواحدة
 *    (SecurityPosture/SecurityExposure/SecurityRadar/SecurityFindings/ApiTokens)
 *    لا من نسخٍ محلية تنحرف.
 *  - كلفةُ الصفحة **ثابتة** مهما نمت الصفوف: استُبدل تصنيفُ SecurityEvents::counts
 *    في PHP (تحميلُ ٢٠٠٠–٦٠٠٠ صفّ لكل فتحة) بعدّاتِ SQL على audits(action, created_at).
 *  - ذيلُ نتائج الكيانات (critic #24): reconcile واحدٌ لكل (رمز/سرّ/مستخدم) بمفتاحٍ
 *    فريد، idempotent، ويُغلق تلقائياً ما زال شرطُه — بلا حذف.
 */
class SecurityDashboardTest extends TestCase
{
    /** عدُّ الاستعلامات حول تنفيذٍ واحد (نمطُ ScreenPerformanceTest) */
    protected function counted(\Closure $fn): array
    {
        $count = false;
        $n = 0;
        DB::listen(function () use (&$n, &$count) { if ($count) $n++; });
        $count = true;
        $out = $fn();
        $count = false;

        return [$out, $n];
    }

    protected function seedSignals(): void
    {
        // جلسةٌ حيّة + محاولاتٌ فاشلة + رفضُ وصول + سرٌّ بائت + رموزٌ بأصنافها + حادثة
        SessionLog::create(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
            'ip' => '10.0.0.5', 'started_at' => now(), 'last_seen_at' => now()]);
        DB::table('audits')->insert(collect(range(1, 3))->map(fn ($i) => [
            'action' => 'دخول فاشل', 'name' => "طرقة {$i}", 'ip' => '203.0.113.7',
            'created_at' => now()->subMinutes($i),
        ])->all());
        DB::table('access_denials')->insert([
            ['kind' => 'وصول مرفوض', 'ip' => '203.0.113.7', 'method' => 'GET', 'path' => '/x', 'created_at' => now()],
            ['kind' => 'تخمين رابط', 'ip' => '203.0.113.8', 'method' => 'GET', 'path' => '/s/zz', 'created_at' => now()],
        ]);
        DB::table('vault_secrets')->insert(['id' => (string) Str::uuid(), 'title' => 'سر بائت',
            'type' => 'خادم', 'version' => 1, 'archived' => 0,
            'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)]);
        ApiToken::create(['user_id' => $this->owner->id, 'name' => 'خامل',
            'token_hash' => hash('sha256', 'a1'), 'created_at' => now()->subDays(100),
            'last_used_at' => now()->subDays(100), 'expires_at' => now()->addDays(30)]);
        ApiToken::create(['user_id' => $this->owner->id, 'name' => 'سليم',
            'token_hash' => hash('sha256', 'a2'), 'created_at' => now(),
            'last_used_at' => now(), 'expires_at' => now()->addDays(30)]);
        hub_security_incident('حادثة اختبار اللوحة', 'حرج', ['src' => 'test']);
    }

    /** كلُّ بطاقةٍ من الخمس عشرة تساوي استعلامَها المرجعيّ */
    public function test_the_fifteen_cards_match_their_reference_queries(): void
    {
        $this->seedCore();
        $this->seedSignals();
        SecurityFindings::reconcile();   // تُملأ بطاقتا النتائج من الجدول الحقيقي

        $resp = $this->actingAs($this->owner)->get('/admin/security')->assertOk();
        $all = collect($resp->original->getData()['cards'] ?? []);
        $this->assertCount(15, $all, 'لوحةُ القيادة خمسَ عشرةَ بطاقةً (§2.1)');
        $cards = $all->keyBy('key');

        // المراجع تُحسب من المصادر نفسِها — أي انحرافٍ يعني نسخةً محليةً ثانية
        $sum = SecurityPosture::summary(SecurityPosture::checks());
        $this->assertSame($sum['score'] . '٪', (string) $cards['score']['value']);
        $this->assertSame($sum['bad'], (int) $cards['bad']['value']);
        $this->assertSame($sum['wn'], (int) $cards['wn']['value']);

        $this->assertSame(SecurityFindings::openCountBySeverity('critical'), (int) $cards['findings_critical']['value']);
        $this->assertSame(SecurityFindings::openCountBySeverity('high'), (int) $cards['findings_high']['value']);

        $this->assertSame(count(SecurityPosture::privilegedNoMfaIds()), (int) $cards['priv_no_mfa']['value']);
        $this->assertSame(\App\Support\SecurityExposure::summary()['high'], (int) $cards['exposed']['value']);

        $live = (int) DB::table('sessions_log')->where('revoked', false)
            ->where('last_seen_at', '>=', now()->subMinutes(\App\Support\Sessions::LIVE_MIN))->count();
        $this->assertSame($live, (int) $cards['live_sessions']['value']);
        $this->assertGreaterThanOrEqual(1, $live);

        $failed = (int) DB::table('audits')
            ->whereIn('action', \App\Support\SecurityEvents::actions('AUTH_FAILURE'))
            ->where('created_at', '>=', now()->subDays(7))->count();
        $this->assertSame($failed, (int) $cards['failed7']['value']);
        $this->assertSame(3, $failed);

        $radar = \App\Support\SecurityRadar::summary();
        $this->assertSame($radar['total'], (int) $cards['denied7']['value']);
        $this->assertSame($radar['ips'], (int) $cards['denied_ips']['value']);

        $this->assertSame(count(SecurityPosture::vaultStaleIds()), (int) $cards['stale_secrets']['value']);
        $this->assertSame(count(SecurityPosture::apiStaleIds()), (int) $cards['tokens_risky']['value']);

        $liveTokens = (int) DB::table('api_tokens')->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        $this->assertSame($liveTokens, (int) $cards['tokens_live']['value']);

        // المرجعُ يقرأ عمودَ kind (الطور ٦) مع احتياطٍ واسع النمط لصفوف ما قبله:
        // MySQL 8 يطبّع JSON بمسافةٍ بعد النقطتين فالنمطُ الضيّق «"kind":"security"»
        // كان يُرجع صفراً عليه وحده (سقطةُ CI على v2.404.0) بينما تمرّ MariaDB/SQLite.
        $secinc = (int) DB::table('incidents')->whereNull('deleted_at')
            ->whereNotIn('status', ['مغلق بتقرير', 'مُستعاد'])
            ->where(fn ($w) => $w->where('kind', 'security')
                ->orWhere(fn ($o) => $o->whereNull('kind')->where('meta', 'like', '%"kind"%security%')))->count();
        $this->assertSame($secinc, (int) $cards['incidents']['value']);
        $this->assertGreaterThanOrEqual(1, $secinc);
    }

    /** كلفةُ الصفحة لا تنمو مع الصفوف — عدّاتُ SQL لا تصنيفَ PHP لكل قيد */
    public function test_dashboard_query_cost_is_flat_as_data_grows(): void
    {
        $this->seedCore();
        $this->seedSignals();

        // إحماء: خبيئةُ الإعدادات والمخطّط تُملأ خارج القياس
        $this->actingAs($this->owner)->get('/admin/security')->assertOk();
        [, $q1] = $this->counted(fn () => $this->get('/admin/security')->assertOk());

        // انفخ البيانات على المحاور التي كان تصنيفُ PHP يحمّلها كلَّها
        DB::table('audits')->insert(collect(range(1, 300))->map(fn ($i) => [
            'action' => $i % 3 === 0 ? 'دخول ناجح' : 'دخول فاشل', 'name' => "قيد {$i}",
            'ip' => '203.0.113.' . ($i % 200), 'created_at' => now()->subMinutes($i),
        ])->all());
        DB::table('access_denials')->insert(collect(range(1, 60))->map(fn ($i) => [
            'kind' => 'وصول مرفوض', 'ip' => '198.51.100.' . ($i % 40), 'method' => 'GET',
            'path' => "/p{$i}", 'created_at' => now()->subMinutes($i),
        ])->all());
        DB::table('vault_secrets')->insert(collect(range(1, 15))->map(fn ($i) => [
            'id' => (string) Str::uuid(), 'title' => "سر {$i}", 'type' => 'خادم', 'version' => 1,
            'archived' => 0, 'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200),
        ])->all());
        foreach (range(1, 20) as $i) {
            ApiToken::create(['user_id' => $this->owner->id, 'name' => "رمز {$i}",
                'token_hash' => hash('sha256', "t{$i}"), 'created_at' => now()->subDays(100),
                'last_used_at' => now()->subDays(100), 'expires_at' => null]);
        }
        foreach (range(1, 10) as $i) {
            SessionLog::create(['id' => (string) Str::uuid(), 'user_id' => $this->employee->id,
                'ip' => "10.0.1.{$i}", 'started_at' => now(), 'last_seen_at' => now()]);
        }

        [, $q2] = $this->counted(fn () => $this->get('/admin/security')->assertOk());

        $this->assertSame($q1, $q2,
            "كلفةُ مركز الأمان نمت مع الصفوف ({$q1} ← {$q2}) — عاد تصنيفٌ في PHP أو حلقةُ استعلامٍ لكل صفّ");
    }

    /** عدُّ الأحداث عدّاتُ SQL صحيحةٌ — لا تحميلَ صفوفٍ وتصنيفَها في PHP */
    public function test_event_counts_are_sql_aggregates_and_match_the_ledger(): void
    {
        $this->seedCore();
        DB::table('audits')->insert(array_merge(
            collect(range(1, 5))->map(fn ($i) => ['action' => 'دخول فاشل', 'module' => null, 'name' => "ف{$i}", 'created_at' => now()->subMinutes($i)])->all(),
            collect(range(1, 2))->map(fn ($i) => ['action' => 'دخول ناجح', 'module' => null, 'name' => "ن{$i}", 'created_at' => now()->subMinutes($i)])->all(),
            [['action' => 'تعديل', 'module' => 'roles', 'name' => 'دور', 'created_at' => now()]],
        ));
        DB::table('access_denials')->insert([
            ['kind' => 'وصول مرفوض', 'ip' => '1.2.3.4', 'method' => 'GET', 'path' => '/x', 'created_at' => now()],
            ['kind' => 'وصول مرفوض', 'ip' => '1.2.3.4', 'method' => 'GET', 'path' => '/y', 'created_at' => now()],
            ['kind' => 'تخمين رابط', 'ip' => '1.2.3.5', 'method' => 'GET', 'path' => '/s/z', 'created_at' => now()],
        ]);

        DB::enableQueryLog();
        $counts = \App\Support\SecurityEvents::counts(7);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $counts['AUTH_FAILURE'] ?? 0);
        $this->assertSame(2, $counts['AUTH_SUCCESS'] ?? 0);
        $this->assertSame(1, $counts['ROLE_CHANGED'] ?? 0, 'وسمُ @module:roles لا يُعَدّ');
        $this->assertSame(2, $counts['ACCESS_DENIED'] ?? 0);
        $this->assertSame(1, $counts['LINK_GUESS'] ?? 0);

        // ثلاثةُ تجميعاتٍ لا أكثر (فحوصُ المخطّط pragma خارج العدّ) — وكلُّها COUNT
        // بلا LIMIT: الشكلُ القديم كان `limit 6000` ثم تصنيفاً في PHP
        $selects = array_values(array_filter($log, function ($q) {
            $sql = strtolower(trim($q['query']));

            return str_starts_with($sql, 'select')
                && ! str_contains($sql, 'sqlite_master') && ! str_contains($sql, 'information_schema');
        }));
        $this->assertLessThanOrEqual(3, count($selects), 'العدُّ تضخّم — يُفترض ٣ تجميعاتٍ لا أكثر');
        foreach ($selects as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('limit', $sql,
                'عدُّ الأحداث ما زال يحمّل صفوفاً محدودةً ويصنّفها في PHP بدل تجميع SQL');
            $this->assertStringContainsString('count', $sql, 'استعلامُ عدٍّ بلا COUNT — صفوفٌ تُحمَّل');
        }
    }

    /** ذيلُ نتائج الكيانات: صفٌّ واحد لكل كيان، idempotent، ويُغلق آلياً عند زوال الشرط */
    public function test_entity_findings_reconcile_once_per_entity_and_auto_resolve(): void
    {
        $this->seedCore();   // المالكُ مميّزٌ بلا تحقّقٍ بخطوتين — كيانُ مستخدمٍ جاهز
        $idle = ApiToken::create(['user_id' => $this->owner->id, 'name' => 'خامل',
            'token_hash' => hash('sha256', 'e1'), 'created_at' => now()->subDays(100),
            'last_used_at' => now()->subDays(100), 'expires_at' => now()->addDays(30)]);
        $stale = (string) Str::uuid();
        DB::table('vault_secrets')->insert(['id' => $stale, 'title' => 'سر الكيان', 'type' => 'خادم',
            'version' => 1, 'archived' => 0,
            'created_at' => now()->subDays(200), 'updated_at' => now()->subDays(200)]);

        SecurityFindings::reconcile();
        $this->travel(1)->days();
        SecurityFindings::reconcile();

        $tok = DB::table('security_findings')->where('code', 'api_stale')
            ->where('entity_type', 'api_token')->orderBy('entity_id')->orderBy('id')->get();
        $this->assertCount(1, $tok, 'تشغيلان كرّرا نتيجةَ الرمز بدل تحديثها');
        $this->assertSame((string) $idle->id, (string) $tok[0]->entity_id);
        $this->assertSame('open', $tok[0]->status);
        $this->assertLessThan($tok[0]->last_seen_at, $tok[0]->first_seen_at, 'first_seen_at تجدّد — ضاع العمر');

        $sec = DB::table('security_findings')->where('code', 'vault_stale')
            ->where('entity_type', 'secret')->orderBy('entity_id')->orderBy('id')->get();
        $this->assertCount(1, $sec);
        $this->assertSame($stale, (string) $sec[0]->entity_id);

        $usr = DB::table('security_findings')->where('code', 'twofa_priv')
            ->where('entity_type', 'user')->where('entity_id', $this->owner->id)->get();
        $this->assertCount(1, $usr, 'ذيلُ المستخدمين لا يمرّ بسكّة IdentityRisk الواحدة');

        // لا سرَّ ولا بصمةَ رمزٍ في أي دليل
        foreach (DB::table('security_findings')->orderBy('id')->get() as $f) {
            $this->assertDoesNotMatchRegularExpression('~lyn_[A-Za-z0-9]{16,}~', (string) $f->evidence);
            $this->assertStringNotContainsString('token_hash', (string) $f->evidence);
            $this->assertStringNotContainsString('secret_cipher', (string) $f->evidence);
        }

        // زوالُ الشروط الثلاثة يُغلق الثلاثة آلياً — بلا حذف
        DB::table('api_tokens')->where('id', $idle->id)->update(['last_used_at' => now()]);
        DB::table('vault_secrets')->where('id', $stale)->update(['rotated_at' => now()]);
        DB::table('users')->where('id', $this->owner->id)->update(['totp_enabled' => 1]);
        $this->travel(1)->days();
        SecurityFindings::reconcile();

        foreach ([['api_stale', 'api_token', (string) $idle->id],
                  ['vault_stale', 'secret', $stale],
                  ['twofa_priv', 'user', (string) $this->owner->id]] as [$code, $type, $eid]) {
            $row = DB::table('security_findings')->where('code', $code)
                ->where('entity_type', $type)->where('entity_id', $eid)->first();
            $this->assertNotNull($row, "نتيجةُ {$code} حُذفت بدل أن تُحَلّ — التاريخُ ضاع");
            $this->assertSame('resolved', $row->status, "زال شرطُ {$code} ولم تُغلق نتيجتُه");
            $this->assertNotNull($row->resolved_at);
        }
        $this->travelBack();
    }
}
