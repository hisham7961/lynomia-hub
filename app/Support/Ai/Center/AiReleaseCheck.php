<?php

namespace App\Support\Ai\Center;

use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProvider;
use App\Models\AiUsageEvent;
use Illuminate\Support\Facades\Schema;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\Governance\AiCost;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiPurposes;

/**
 * **بوّابةُ الإطلاق — تقرأ ولا تكتب ولا تُنفق** (المرحلة ٥ · W12).
 *
 * ── **لماذا بوّابةٌ آليّةٌ لا قائمةُ مراجعةٍ في وثيقة؟** ──
 *
 * قائمةُ المراجعةِ تُقرَأ مرّةً يومَ كُتبت. والحالةُ تتغيّر بعدها: يُحذَف
 * نموذجٌ، وينفد رصيدٌ، ويُعطَّل مزوّد، وتُنزَع قدرةٌ من نشرٍ عند المنبع. فبعد
 * شهرٍ تصف الوثيقةُ نظاماً لم يعد قائماً — **ومن يقرؤها يطمئنّ إلى ماضٍ**.
 *
 * ── **ثلاثُ درجاتٍ لا اثنتان** ──
 *
 * | الدرجة | معناها | ماذا تفعل |
 * |---|---|---|
 * | **PASS** | الشرطُ متحقّقٌ بدليلٍ مقروء | امضِ |
 * | **WARN** | **قد يمنع الإطلاقَ ولا يُؤكَّد من هنا** | اقرأ وقرِّر |
 * | **FAIL** | شرطٌ إطلاقٍ منقوضٌ بدليل | لا إطلاق |
 *
 * **و`WARN` ليست `PASS`.** وعدُّها نجاحاً بصمتٍ يُفرغ الدرجةَ الوسطى من
 * معناها — وهي أهمُّ الثلاث: تقول «هنا ما لا يعرفه الفحصُ وحدَه».
 *
 * ── **ولا نداءَ شبكةٍ ولا توليدٌ ولا دينار** ──
 *
 * كلُّ فحصٍ هنا يقرأ **حالةً مخزّنةً**: إعداداتٍ وصفوفَ قاعدةٍ ومخطَّطاً
 * وملفّاتِ مستودع. فلا يُستدعى مزوّدٌ ولا تُجرَّب بوّابة — **فالفحصُ يُشغَّل
 * في CI وعلى الإنتاجِ سواءً بلا أثرٍ على الفاتورة**.
 */
final class AiReleaseCheck
{
    public const PASS = 'PASS';
    public const WARN = 'WARN';
    public const FAIL = 'FAIL';

    /** ترتيبُ الشدّة — الأسوأُ يحكم على المجموع */
    public const SEVERITY = [self::PASS => 0, self::WARN => 1, self::FAIL => 2];

    /**
     * **يُجري كلَّ الفحوصِ ويُعيد نتيجةً مقروءةً آليّاً.**
     *
     * @return array{verdict: string, counts: array<string,int>,
     *               checks: list<array{id: string, status: string, title: string,
     *                                  reason: string, fix: ?string}>}
     */
    public static function run(): array
    {
        $checks = [];

        foreach (self::suite() as $id => $fn) {
            $r = $fn();
            $checks[] = [
                'id'     => $id,
                'status' => (string) $r['status'],
                'title'  => (string) $r['title'],
                'reason' => (string) $r['reason'],
                'fix'    => $r['fix'] ?? null,
            ];
        }

        $counts = [self::PASS => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($checks as $c) $counts[$c['status']]++;

        return [
            'verdict' => $counts[self::FAIL] > 0 ? self::FAIL
                : ($counts[self::WARN] > 0 ? self::WARN : self::PASS),
            'counts'  => $counts,
            'checks'  => $checks,
        ];
    }

    /**
     * **الفحوصُ — معرّفٌ ثابتٌ لكلِّ واحدٍ يُقرأ آليّاً.**
     *
     * والمعرّفاتُ `SCREAMING_SNAKE` بالإنجليزيّةِ عمداً: تُفرَز وتُقارَن
     * وتُبحَث في سجلٍّ، والرسالةُ العربيّةُ للقارئِ لا للآلة.
     *
     * @return array<string, callable(): array{status: string, title: string, reason: string, fix?: ?string}>
     */
    private static function suite(): array
    {
        return [
            'SCHEMA_TABLES'      => static fn () => self::schemaTables(),
            'SCHEMA_TELEMETRY'   => static fn () => self::schemaTelemetry(),
            'GATEWAY_CONFIGURED' => static fn () => self::gatewayConfigured(),
            'GATEWAY_LOOPBACK'   => static fn () => self::gatewayLoopback(),
            'GATEWAY_PROBED'     => static fn () => self::gatewayProbed(),
            'PROVIDER_CREDENTIAL' => static fn () => self::providerCredential(),
            'MODELS_REGISTERED'  => static fn () => self::modelsRegistered(),
            'ASK_PROFILE_CHAIN'  => static fn () => self::askProfileChain(),
            'ASK_SUITABILITY'    => static fn () => self::askSuitability(),
            'ASK_FALLBACK_DEPTH' => static fn () => self::fallbackDepth(),
            'WRITE_TOOLS_CLOSED' => static fn () => self::writeToolsClosed(),
            'FAILURE_CODES_LIVE' => static fn () => self::failureCodes(),
            'LEDGER_NO_CONTENT'  => static fn () => self::ledgerNoContent(),
            'LEDGER_STALE_HOLDS' => static fn () => self::staleHolds(),
            'COST_UNKNOWN_NOT_ZERO' => static fn () => self::costUnknown(),
            'BACKUP_COVERAGE'    => static fn () => self::backupCoverage(),
            'OPENAPI_FRESH'      => static fn () => self::openapiFresh(),
            'VERSION_SYNC'       => static fn () => self::versionSync(),
            'DOCS_PRESENT'       => static fn () => self::docsPresent(),
        ];
    }

    // ── المخطَّط ───────────────────────────────────────────────────────

    private static function schemaTables(): array
    {
        $need = ['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models',
                 'ai_policies', 'ai_budgets', 'ai_budget_periods', 'ai_usage_events'];
        $missing = array_values(array_filter($need,
            static fn (string $t) => ! Schema::hasTable($t)));

        return $missing === []
            ? self::pass('جداولُ الذكاءِ الثمانية', 'الثمانيةُ موجودة')
            : self::fail('جداولُ الذكاءِ الثمانية',
                'ناقصٌ: ' . implode(' · ', $missing),
                'php artisan migrate --force');
    }

    private static function schemaTelemetry(): array
    {
        $need = ['finish_reason', 'reasoning_tokens', 'max_output_tokens', 'tool_requested'];
        $missing = array_values(array_filter($need,
            static fn (string $c) => ! Schema::hasColumn('ai_usage_events', $c)));

        return $missing === []
            ? self::pass('تليمتري الدورةِ الواحدة', 'الأعمدةُ الأربعةُ موجودة')
            : self::fail('تليمتري الدورةِ الواحدة',
                'ناقصٌ: ' . implode(' · ', $missing) . ' — فلا يُشخَّص إخفاقٌ إلّا بالتخمين',
                'php artisan migrate --force');
    }

    // ── البوّابة ──────────────────────────────────────────────────────

    private static function gatewayConfigured(): array
    {
        if (! AiGateway::configured()) {
            return self::fail('إعدادُ البوّابة', 'عنوانُ البوّابةِ أو مفتاحُها غيرُ مضبوط',
                'مركزُ الذكاء ← البوّابة');
        }

        return AiGateway::enabled()
            ? self::pass('إعدادُ البوّابة', 'مضبوطةٌ ومُفعَّلة')
            : self::warn('إعدادُ البوّابة', 'مضبوطةٌ و**مُعطَّلة** — لا طلبَ يخرج',
                'مركزُ الذكاء ← البوّابة ← تفعيل');
    }

    /**
     * **البوّابةُ على حلقةٍ محلّيّةٍ أم مكشوفة؟**
     *
     * وهذا فحصُ نشرٍ لا فحصُ شيفرة: عنوانٌ عامٌّ يعني بوّابةً تحمل مفاتيحَ
     * المزوّدين **مكشوفةً على الشبكة**. ولأنّ نشراً مشروعاً خلف وكيلٍ داخليٍّ
     * ممكنٌ نظريّاً، فالدرجةُ `WARN` لا `FAIL` — **وتُقرأ ولا تُبتلَع**.
     */
    private static function gatewayLoopback(): array
    {
        if (! AiGateway::configured()) {
            return self::warn('عزلُ البوّابة', 'لا عنوانَ يُفحَص بعد', null);
        }

        return AiGateway::isLoopback()
            ? self::pass('عزلُ البوّابة', 'مربوطةٌ بحلقةٍ محلّيّةٍ بلا كشف')
            : self::warn('عزلُ البوّابة',
                'عنوانُ البوّابةِ ليس حلقةً محلّيّة — تأكّد أنّها غيرُ مكشوفةٍ للإنترنت',
                'اربطها بـ127.0.0.1 أو أثبِت العزلَ بجدارِ ناريٍّ موثّق');
    }

    private static function gatewayProbed(): array
    {
        if (! AiGateway::probePassed()) {
            return self::warn('فحصُ الاتصال', 'لم يُجرَ فحصُ اتصالٍ ناجحٌ على البصمةِ الحاليّة',
                'مركزُ الذكاء ← البوّابة ← فحصُ الاتصال');
        }

        return AiGateway::generationVerified()
            ? self::pass('فحصُ الاتصال', 'الاتصالُ والتوليدُ كلاهما مُثبَتٌ على البصمةِ الحاليّة')
            : self::warn('فحصُ الاتصال',
                'الاتصالُ مُثبَتٌ والتوليدُ لم يُثبَت بعد — والمساعدُ لا يُرفَع قبل توليدٍ متحقّق',
                'مركزُ الذكاء ← النماذج ← اختبارُ توليد');
    }

    // ── المزوّدون والنماذج ────────────────────────────────────────────

    private static function providerCredential(): array
    {
        $enabled = AiProvider::query()->where('enabled', true)->count();
        if ($enabled === 0) {
            return self::fail('اعتمادُ مزوّد', 'لا مزوّدَ مُفعَّلٌ واحد',
                'مركزُ الذكاء ← المزوّدون');
        }

        $usable = AiProvider::query()->where('enabled', true)
            ->where('credential_state', '!=', 'missing')->count();

        if ($usable === 0) {
            return self::fail('اعتمادُ مزوّد',
                "{$enabled} مزوّداً مُفعَّلاً وبلا اعتمادٍ واحدٍ مُدخَل",
                'مركزُ الذكاء ← المزوّدون ← إضافةُ اعتماد');
        }

        $verified = AiProvider::query()->where('enabled', true)
            ->where('credential_state', 'verified')->count();

        return $verified > 0
            ? self::pass('اعتمادُ مزوّد', "{$verified} من {$usable} اعتماداً متحقَّقٌ منه")
            : self::warn('اعتمادُ مزوّد',
                "{$usable} اعتماداً مُدخَلاً ولا واحدَ **متحقَّقٌ منه** — والإدخالُ ليس قبولاً",
                'مركزُ الذكاء ← المزوّدون ← فحصُ الاعتماد');
    }

    private static function modelsRegistered(): array
    {
        $n = AiModel::query()->where('enabled', true)->count();

        if ($n === 0) {
            return self::fail('نماذجٌ مسجَّلة', 'لا نموذجَ مُفعَّلٌ واحد',
                'مركزُ الذكاء ← النماذج ← تصفّحُ الكتالوج');
        }

        $blocked = AiModel::query()->where('enabled', true)
            ->whereIn('health', AiProfiles::UNROUTABLE)->count();

        return $blocked === 0
            ? self::pass('نماذجٌ مسجَّلة', "{$n} نموذجاً مُفعَّلاً، ولا واحدَ خارجَ التوجيه")
            : self::warn('نماذجٌ مسجَّلة',
                "{$blocked} من {$n} نموذجاً بحالةٍ تمنع التوجيهَ إليه",
                'مركزُ الذكاء ← النماذج ← مصالحة');
    }

    // ── المساعد ───────────────────────────────────────────────────────

    private static function askProfileChain(): array
    {
        $key = AskPolicy::profileKey();
        $p   = AiProfile::query()->where('key', $key)->where('enabled', true)->first();

        if ($p === null) {
            return self::fail('سلسلةُ غرضِ المساعد',
                "لا غرضَ مُفعَّلٌ بالمفتاح «{$key}»",
                'مركزُ الذكاء ← التوجيه ← اجعله غرضَ «اسأل Hub»');
        }

        $n = AiProfiles::chain($p)->count();

        return $n > 0
            ? self::pass('سلسلةُ غرضِ المساعد', "الغرضُ «{$key}» بسلسلةٍ من {$n} نموذجاً")
            : self::fail('سلسلةُ غرضِ المساعد',
                "الغرضُ «{$key}» سلسلتُه فارغةٌ تشغيليّاً — وطلبٌ بلا سلسلةٍ لا يُوجَّه",
                'مركزُ الذكاء ← التوجيه ← ضمُّ نموذجٍ إلى السلسلة');
    }

    /**
     * **أفي السلسلةِ نموذجٌ يُلائم المساعدَ فعلاً؟**
     *
     * وهذا هو الفحصُ الذي لم يكن: سلسلةٌ مملوءةٌ بنماذجَ تُولّد ولا تُصدر
     * طلباتِ أدواتٍ تمرّ بكلِّ فحصٍ قديمٍ **ولا تُجيب سؤالاً واحداً**.
     */
    private static function askSuitability(): array
    {
        $key = AskPolicy::profileKey();
        $p   = AiProfile::query()->where('key', $key)->where('enabled', true)->first();

        if ($p === null) {
            return self::warn('ملاءمةُ نماذجِ المساعد', 'لا غرضَ يُفحَص', null);
        }

        $fit = AiProfiles::chain($p, AiPurposes::ASK);
        if ($fit->isNotEmpty()) {
            $states = $fit->map(static fn (AiModel $m) => AiPurposes::suitability($m, AiPurposes::ASK)['state']);
            $observed = $states->filter(static fn ($s) => $s === AiPurposes::OBSERVED)->count();

            return $observed === 0
                ? self::pass('ملاءمةُ نماذجِ المساعد',
                    $fit->count() . ' نموذجاً بقدراتٍ مُعلَنةٍ تكفي (' . implode(' + ', AiPurposes::needs(AiPurposes::ASK)) . ')')
                : self::warn('ملاءمةُ نماذجِ المساعد',
                    $observed . ' من ' . $fit->count() . ' نموذجاً يمرّ **بدليلِ دفترٍ لا بإعلانِ بوّابة** — '
                    . 'وهو مقبولٌ ويُراجَع',
                    'مركزُ الذكاء ← النماذج ← تحديثُ الحقائقِ من البوّابة');
        }

        $unfit = array_values(array_filter(AiProfiles::excluded($p, AiPurposes::ASK),
            static fn (array $x) => str_contains((string) $x['why'], 'ينقص النموذجَ')));

        return self::fail('ملاءمةُ نماذجِ المساعد',
            $unfit === []
                ? 'لا نموذجَ في السلسلةِ يُلائم المساعد'
                : (string) $unfit[0]['why'],
            'مركزُ الذكاء ← التوجيه — اضمم نموذجاً يدعم '
                . implode(' + ', AiPurposes::needs(AiPurposes::ASK)));
    }

    /** **أللسلسلةِ تكرارٌ حقيقيّ؟** — ثلاثةُ نماذجَ على مزوّدٍ واحدٍ تسقط معاً */
    private static function fallbackDepth(): array
    {
        $key = AskPolicy::profileKey();
        $p   = AiProfile::query()->where('key', $key)->where('enabled', true)->first();

        if ($p === null) return self::warn('تكرارُ السلسلة', 'لا غرضَ يُفحَص', null);

        $chain = AiProfiles::chain($p, AiPurposes::ASK);
        if ($chain->count() < 2) {
            return self::warn('تكرارُ السلسلة',
                'السلسلةُ نموذجٌ واحدٌ — فإخفاقُه إخفاقُ الطلبِ كلِّه، بلا احتياط',
                'مركزُ الذكاء ← التوجيه ← ضمُّ نموذجٍ احتياطيّ');
        }

        $providers = $chain->pluck('provider_id')->unique()->count();

        return $providers > 1
            ? self::pass('تكرارُ السلسلة',
                $chain->count() . ' نماذجَ على ' . $providers . ' مزوّدين')
            : self::warn('تكرارُ السلسلة',
                'كلُّ نماذجِ السلسلةِ على مزوّدٍ واحد — **سلسلةٌ بلا تكرارٍ حقيقيّ**: '
                . 'تهدئةُ المزوّدِ تُسقطها كلَّها معاً',
                'مركزُ الذكاء ← التوجيه ← ضمُّ نموذجٍ من مزوّدٍ آخر');
    }

    // ── الثوابتُ الأمنيّة ─────────────────────────────────────────────

    private static function writeToolsClosed(): array
    {
        if (AskTools::WRITE_TOOLS !== []) {
            return self::fail('أدواتُ الكتابةِ مغلقة',
                '`AskTools::WRITE_TOOLS` لم تعد فارغة — والنموذجُ صار يكتب',
                'قرارُ مالكٍ: أعِدها فارغةً أو وثّق التوسيعَ صراحةً');
        }

        $extra = array_values(array_diff(AskTools::TOOLS,
            ['hub_modules', 'hub_search', 'hub_list', 'hub_record', 'hub_count', 'hub_findings', 'hub_semantic']));

        return $extra === []
            ? self::pass('أدواتُ الكتابةِ مغلقة', 'سبعُ أدواتٍ قارئةٍ مُراجَعة (آخرُها `hub_semantic`) ولا أداةَ تكتب')
            : self::warn('أدواتُ الكتابةِ مغلقة',
                'أداةٌ خارجَ السبعِ المُراجَعة: ' . implode(' · ', $extra) . ' — تُراجَع',
                null);
    }

    /** **ولا رمزَ إخفاقٍ بلا رسالة** — رمزٌ أخرسُ في وجهِ المستخدمِ عطلٌ بذاته */
    private static function failureCodes(): array
    {
        $mute = [];
        foreach (AskFailures::CODES as $code) {
            $m = trim((string) AskFailures::message($code));
            if ($m === '' || $m === $code) $mute[] = $code;
        }

        return $mute === []
            ? self::pass('رموزُ الإخفاق', count(AskFailures::CODES) . ' رمزاً لكلٍّ رسالتُه')
            : self::fail('رموزُ الإخفاق', 'رمزٌ أخرسُ: ' . implode(' · ', $mute),
                'أضِف رسالةً في `AskFailures::MESSAGES`');
    }

    // ── الدفتر ────────────────────────────────────────────────────────

    private static function ledgerNoContent(): array
    {
        $bad = [];
        foreach (Schema::getColumnListing('ai_usage_events') as $c) {
            if (in_array($c, ['question', 'answer', 'prompt', 'content', 'message',
                              'reasoning_content', 'body', 'text', 'args', 'payload'], true)) {
                $bad[] = $c;
            }
        }

        return $bad === []
            ? self::pass('الدفترُ بلا محتوى', 'لا عمودَ يحمل نصَّ سؤالٍ ولا جوابٍ ولا تفكير')
            : self::fail('الدفترُ بلا محتوى',
                'عمودُ محتوًى في دفترِ الاستهلاك: ' . implode(' · ', $bad),
                'الدفترُ يُقرَأ بصلاحيّةِ الرقابةِ لا بصلاحيّةِ السائل — يُزال العمود');
    }

    /** **حجزٌ معلّقٌ فوق عمرِه يخنق ميزانيّةً سليمةً بمالٍ لم يُنفَق** */
    private static function staleHolds(): array
    {
        if (! Schema::hasTable('ai_usage_events')) {
            return self::warn('حجوزٌ معلّقة', 'لا دفترَ يُفحَص', null);
        }

        $ttl = max(1, (int) setting('ai.reservation_ttl_min', 15));
        $n   = AiUsageEvent::query()->where('status', 'reserved')
            ->where('started_at', '<', now()->subMinutes($ttl))->count();

        return $n === 0
            ? self::pass('حجوزٌ معلّقة', 'لا حجزَ فوق عمرِه')
            : self::warn('حجوزٌ معلّقة',
                "{$n} حجزاً معلّقاً فوق {$ttl} دقيقة — يخنق الميزانيّةَ بمالٍ لم يُنفَق",
                'يُفرَج عنها بالمِقصِّ الدوريِّ أو بتشغيلِ المجدوِل');
    }

    private static function costUnknown(): array
    {
        if (! Schema::hasTable('ai_usage_events')) {
            return self::warn('مصدرُ الكلفة', 'لا دفترَ يُفحَص', null);
        }

        // **صفرٌ بمصدرٍ مجهولٍ كذبةٌ على الفاتورة** — «لا نعرف» لا «لم يكلّف»
        $lying = AiUsageEvent::query()
            ->where('cost_source', AiCost::UNKNOWN)
            ->whereNotNull('cost_micro')->count();

        return $lying === 0
            ? self::pass('مصدرُ الكلفة', 'لا رقمَ كلفةٍ بمصدرٍ مجهول')
            : self::fail('مصدرُ الكلفة',
                "{$lying} صفّاً يحمل رقمَ كلفةٍ ومصدرُه «مجهول» — والرقمُ بلا مصدرٍ لا يُجمَع",
                'يُراجَع كاتبُ الصفّ: الكلفةُ تُوسَم بمصدرِها أو تبقى `null`');
    }

    // ── النسخُ والوثائقُ والنسخة ──────────────────────────────────────

    /**
     * **أفي النسخةِ الاحتياطيّةِ جداولُ الذكاءِ الثمانية؟**
     *
     * و`RAW_TABLES` ثابتٌ محميٌّ داخلَ أمرِ النسخ، فلا يُقرأ من هنا برمجيّاً.
     * **فيُقرأ من المصدرِ نصّاً** — وهو أضعفُ من قراءةِ القيمةِ لكنّه يمسك
     * العطلَ الذي يهمّ: جدولٌ أُضيف للمنصّةِ ونُسي في النسخة، فاستعادةٌ تُعيد
     * Hub **بلا ذكاءٍ ولا اعتماداتٍ ولا دفتر**.
     */
    private static function backupCoverage(): array
    {
        $need = ['ai_providers', 'ai_models', 'ai_profiles', 'ai_profile_models',
                 'ai_policies', 'ai_budgets', 'ai_budget_periods', 'ai_usage_events'];

        $src = base_path('app/Console/Commands/HubBackup.php');
        if (! is_file($src)) {
            return self::warn('تغطيةُ النسخِ الاحتياطيّ', 'أمرُ النسخِ غيرُ موجودٍ فلا يُفحَص', null);
        }

        $body    = (string) file_get_contents($src);
        $missing = array_values(array_filter($need,
            static fn (string $t) => ! str_contains($body, "'" . $t . "'")));

        return $missing === []
            ? self::pass('تغطيةُ النسخِ الاحتياطيّ', 'جداولُ الذكاءِ الثمانيةُ كلُّها في النسخة')
            : self::fail('تغطيةُ النسخِ الاحتياطيّ',
                'خارجَ النسخة: ' . implode(' · ', $missing) . ' — فاستعادةٌ تُعيد Hub بلا ذكاء',
                'أضِفها إلى `HubBackup::RAW_TABLES`');
    }

    private static function openapiFresh(): array
    {
        $path = base_path('docs/openapi.json');
        if (! is_file($path)) {
            return self::fail('مواصفةُ OpenAPI', 'الملفُّ غيرُ موجود',
                'php artisan hub:openapi --out=docs/openapi.json');
        }

        $doc = json_decode((string) file_get_contents($path), true);
        $ver = (string) (($doc['info']['version'] ?? '') ?: '');

        return $ver === self::version()
            ? self::pass('مواصفةُ OpenAPI', "مطابقةٌ للنسخة {$ver}")
            : self::fail('مواصفةُ OpenAPI',
                "نسخةُ المواصفةِ «{$ver}» ونسخةُ المنصّةِ «" . self::version() . '»',
                'php artisan hub:openapi --out=docs/openapi.json');
    }

    private static function versionSync(): array
    {
        $v      = self::version();
        $readme = base_path('README.md');
        $first  = is_file($readme)
            ? trim((string) strtok((string) file_get_contents($readme), "\n")) : '';

        if ($v === '') {
            return self::fail('تطابقُ النسخة', 'ملفُّ `VERSION` فارغٌ أو مفقود', null);
        }

        return str_contains($first, $v)
            ? self::pass('تطابقُ النسخة', "‏`VERSION` وسطرُ README كلاهما v{$v}")
            : self::fail('تطابقُ النسخة',
                "‏`VERSION` = {$v} وسطرُ README الأوّلُ لا يطابقه",
                'حرّر السطرَ الأوّلَ في README');
    }

    private static function docsPresent(): array
    {
        $need = [
            'docs/ai-hub/40-phase5-discovery.md',
            'docs/ai-hub/41-phase5-plan.md',
            'docs/ai-hub/42-operations-runbook.md',
            'docs/ai-hub/43-disaster-recovery.md',
            'docs/ai-hub/44-release-acceptance.md',
        ];
        $missing = array_values(array_filter($need,
            static fn (string $p) => ! is_file(base_path($p))));

        return $missing === []
            ? self::pass('وثائقُ الإطلاق', 'الخمسُ موجودة')
            : self::warn('وثائقُ الإطلاق', 'ناقصٌ: ' . implode(' · ', $missing),
                'تُكتَب قبل الإطلاق');
    }

    // ── أدواتٌ ────────────────────────────────────────────────────────

    private static function version(): string
    {
        $p = base_path('VERSION');

        return is_file($p) ? trim((string) file_get_contents($p)) : '';
    }

    private static function pass(string $title, string $reason): array
    {
        return ['status' => self::PASS, 'title' => $title, 'reason' => $reason, 'fix' => null];
    }

    private static function warn(string $title, string $reason, ?string $fix): array
    {
        return ['status' => self::WARN, 'title' => $title, 'reason' => $reason, 'fix' => $fix];
    }

    private static function fail(string $title, string $reason, ?string $fix = null): array
    {
        return ['status' => self::FAIL, 'title' => $title, 'reason' => $reason, 'fix' => $fix];
    }
}
