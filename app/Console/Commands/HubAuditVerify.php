<?php

namespace App\Console\Commands;

use App\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * التحقق من سلسلة تجزئة التدقيق: يمشي السلسلة من البداية ويعيد حساب كل تجزئة.
 * أي تعديل أو حذف أو إدراج مزور في السجلات المسلسلة يكسر المشي أو المطابقة.
 *
 * الذاكرة محدودة مهما كبر الجدول: المشي على فهرس خفيف (id/prev/hash فقط)
 * ثم يُتحقق المحتوى دفعةً دفعة — كان `get()` كاملاً وينفجر على الجداول الناضجة.
 *
 * وسجل بلا بصمة كُتب **بعد** بدء السلسلة (حقبة audit_chain.started_at) فشلُ ختمٍ
 * لا «تاريخ قديم» — يُفشل التحقق صراحةً. قبل الحقبة يُحصى تاريخاً خارج التغطية.
 */
class HubAuditVerify extends Command
{
    protected $signature = 'hub:audit-verify
        {--reseal : ترقية السجلات المختومة ببصمة قديمة إلى البصمة الكاملة (بعد تحقق نظيف)}
        {--rebuild : إعادةُ وصلِ سلسلةٍ مكسورة من البداية بترتيب الإدراج — بعد استعادةٍ موثوقة أو نقلٍ مقصود}
        {--strict : إفشال الأمر عند اختلاف عمود الشركة كذلك (لا تحذيراً)}';
    protected $description = 'التحقق من سلامة سلسلة تجزئة سجل التدقيق';

    protected const BATCH = 400;

    public function handle(): int
    {
        $epoch = Schema::hasColumn('audit_chain', 'started_at')
            ? DB::table('audit_chain')->where('id', 1)->value('started_at') : null;

        $unsealed = AuditEntry::whereNull('hash')->count();
        $suspect = $epoch ? AuditEntry::whereNull('hash')->where('created_at', '>=', $epoch)->count() : 0;
        $legacyRows = $unsealed - $suspect;

        // فهرس خفيف: ثلاث قيم قصيرة لكل سجل — المشي بلا تحميل المحتوى
        $index = DB::table('audits')->whereNotNull('hash')->get(['id', 'prev_hash', 'hash']);

        // **مسارُ الاستعادة**: سلسلةٌ مكسورة (انقطاعٌ أو تفرّعٌ من لينَتين مختلطتين
        // بعد استعادة نسخة) لا يُصلحها `--reseal` — فهو لا يُبلَغ أصلاً، إذ يُفشَل
        // الأمرُ عند الانقطاع قبله. `--rebuild` يُعيد وصلَ السلسلة كاملةً من البداية
        // بترتيب الإدراج (id تصاعديّ = ترتيب الإنشاء الحقيقي، فالعمود bigIncrements)،
        // ويضبط الرأس، ويختم الفعلَ نفسَه في السلسلة الجديدة. فعلٌ مقصودٌ صريح لا
        // يُثبت نظافةَ ما قبله — يُعيد الوصلَ على المحتوى الحالي، فيُستعمل بعد
        // استعادةٍ موثوقة أو نقلٍ مقصود لا لإخفاء عبثٍ حقيقي.
        if ($this->option('rebuild')) {
            return $this->rebuild($index);
        }

        if ($index->isEmpty() && ! $suspect) {
            $this->info('لا سجلات مسلسلة بعد' . ($legacyRows ? " ({$legacyRows} سجل سابق للسلسلة)" : ''));
            return self::SUCCESS;
        }

        // ١) مشي البنية على الفهرس: الترتيب والتفرع والانقطاع
        $byPrev = $index->groupBy('prev_hash');
        $prev = str_repeat('0', 64);
        $order = [];                                   // [id => prev_hash] بترتيب السلسلة الحقيقي
        while (true) {
            $bucket = $byPrev->get($prev);
            if (! $bucket) break;
            if ($bucket->count() > 1) {
                $this->error('⚠️ تفرع في السلسلة بعد ' . count($order) . ' سجل — تجزئتان تشيران لنفس السابق (عبث محتمل)');
                return self::FAILURE;
            }
            $row = $bucket->first();
            $order[$row->id] = $prev;
            $prev = $row->hash;
        }

        if (count($order) < $index->count()) {
            $this->error('❌ انقطاع: ' . ($index->count() - count($order)) . ' سجل مسلسل غير موصول بالسلسلة (حذف أو تزوير محتمل) — سلِم منها: ' . count($order));
            return self::FAILURE;
        }

        $head = (string) DB::table('audit_chain')->where('id', 1)->value('head');
        if ($head !== $prev) {
            $this->error('❌ رأس السلسلة المخزن لا يطابق آخر سجل — حُذفت سجلات من الذيل على الأرجح');
            return self::FAILURE;
        }

        // ٢) تحقق المحتوى دفعةً دفعة — ثلاث صياغات مقبولة كلها تمثل المحتوى نفسه:
        //  v2 (الحالية) · v2raw (قبل توحيد صياغة JSON) · v1 (قبل الأعمدة الجنائية — تُحصى وتُحذَّر)
        $ok = 0; $weak = 0;
        foreach (array_chunk(array_keys($order), self::BATCH) as $ids) {
            $rows = AuditEntry::whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $row = $rows[$id]; $p = $order[$id];
                if (hash('sha256', $p . '|' . $row->canonical()) === $row->hash) {
                    // مختوم بالبصمة الكاملة الحالية
                } elseif (hash('sha256', $p . '|' . $row->canonical('v2raw')) === $row->hash) {
                    // نفس التغطية، صياغة أقدم — يرقّيها reseal بلا ضجيج
                } elseif (hash('sha256', $p . '|' . $row->canonical('v1')) === $row->hash) {
                    $weak++;
                } else {
                    $this->error("❌ سجل معدَّل بعد كتابته: {$row->id} ({$row->action} / {$row->module}) — التجزئة لا تطابق المحتوى");
                    return self::FAILURE;
                }
                $ok++;
            }
        }

        // ٣) سجلات فشل ختمها بعد بدء السلسلة — البنية سليمة لكن التغطية مثقوبة
        if ($suspect) {
            $this->error("❌ {$suspect} سجل بلا بصمة كُتب بعد بدء السلسلة — فشل ختمٍ لا تاريخ قديم."
                . ' راجع مركز الأخطاء (audit-chain) لمعرفة السبب؛ هذه السجلات خارج ضمان كشف العبث.');
            return self::FAILURE;
        }

        /*
         * ٤) اتّساقُ عمود العزل — **تحذيرٌ لا إفشال**.
         *
         * `company_id` مشتقٌّ خارج البصمة، فيُفحص اشتقاقاً من `(module, record_id)`
         * المختومَين. لكنّ **نقلَ سجلٍ بين الشركات فعلٌ مشروع**: قيودُه التاريخية
         * تحتفظ بشركته القديمة (الهجرةُ تملأ الفارغَ ولا تُحدّث المملوء)، فتبدو
         * كلُّها «مخالِفة» بلا عبثٍ وقع. وإفشالُ الأمر عندها يُدرّب صاحبَ النظام
         * على تجاهله — وإنذارٌ كاذبٌ متكرّر يقتل الإنذارَ الحقيقيّ. يُبلَّغ ليُراجَع
         * بعينٍ بشرية، ويُفشَل صراحةً بـ`--strict` لمن أراد بوابةً قاطعة.
         */
        ['diff' => $mismatch, 'blank' => $blank] = $this->companyMismatch();
        $strictFail = false;

        if ($mismatch) {
            $msg = "⚠️ {$mismatch} قيداً يخالف عمودُ الشركة فيه شركةَ سجلِّه الحالية"
                . ' — قد يكون نقلَ سجلٍ مشروعاً (القيودُ التاريخية تحتفظ بالشركة القديمة)'
                . ' وقد يكون تبديلاً يُخرج قيداً من نطاق مالكه بلا كسر السلسلة. راجعها.';
            if ($this->option('strict')) { $this->error($msg); $strictFail = true; }
            else $this->warn($msg);
        }

        // **الشكلُ الذي يُخفي القيد**: عمودٌ مفرَّغ لا يُطابق أيَّ شركة في
        // `whereIn`، فيختفي القيدُ عن كلِّ صاحب نطاق — وكان الفاحصُ يستثنيه
        // صراحةً بـ`whereNotNull`. وهو مألوفٌ مشروعٌ على تركيبةٍ سبقت عمودَ
        // الشركة، فيُفصَل عن الأول بعبارته كي لا يُغرق الإشارةَ الحقيقية.
        if ($blank) {
            $msg = "⚠️ {$blank} قيداً بلا شركة وسجلُّه اليوم داخل شركة"
                . ' — طبيعيٌّ على تركيبةٍ سبقت عمودَ الشركة أو على سجلٍ ضُمَّ لشركةٍ لاحقاً،'
                . ' وقد يكون تفريغاً متعمَّداً يُخفي القيدَ عن كل نطاق. راجعها.';
            if ($this->option('strict')) { $this->error($msg); $strictFail = true; }
            else $this->warn($msg);
        }

        if ($strictFail) return self::FAILURE;

        $this->info("✅ السلسلة سليمة: {$ok} سجل متحقق"
            . ($legacyRows ? " · {$legacyRows} سجل سابق للسلسلة (خارج التحقق)" : ''));

        if ($weak) {
            $this->warn("⚠️ {$weak} سجل مختوم ببصمة الجيل الأول — لا تحمي عمودَي الجهاز وعنوان IP."
                . ($this->option('reseal') ? '' : ' رقّها بـ: php artisan hub:audit-verify --reseal'));
        }

        if ($this->option('reseal') && $weak) {
            $this->reseal(array_keys($order));
        }

        return self::SUCCESS;
    }

    /**
     * إعادةُ بناء السلسلة كاملةً — مسارُ الاستعادة من كسرٍ بنيويّ.
     *
     * الفرق عن `reseal`: ذاك يُعيد ختمَ السلسلة **المتّصلة** بالبصمة الحالية (ترقيةُ
     * صياغة)، ولا يُبلَغ إلا بعد مشيٍ نظيف. أمّا هذا فيُعيد **وصلَ** سلسلةٍ مكسورة:
     * يأخذ كلَّ سجلٍ مختوم بترتيب `id` التصاعديّ (= ترتيب الإدراج، فالعمود
     * bigIncrements) ويُعيد حسابَ `prev_hash`/`hash` من البداية، فتلتئم اللينَتان
     * المختلطتان (أثرُ استعادة نسخة) في سلسلةٍ واحدة متّصلة.
     *
     * **لا يُثبت نظافةَ ما قبله**: يُعيد الوصلَ على المحتوى كما هو الآن. فيُختَم
     * الفعلُ نفسُه في السلسلة الجديدة (سجلُّ تدقيقٍ بمن ومتى وكم) كي لا تكون
     * إعادةُ البناء بابَ عبثٍ صامت: من يعيد البناء يترك بصمتَه فيها.
     */
    protected function rebuild(\Illuminate\Support\Collection $index): int
    {
        $total = $index->count();
        if ($total === 0) {
            $this->info('لا سجلات مسلسلة لإعادة بنائها.');
            return self::SUCCESS;
        }

        // تقريرُ الحالة قبل العلاج: كم يتّصل من البداية فعلاً وكم منقطع
        $byPrev = $index->groupBy('prev_hash');
        $prev = str_repeat('0', 64);
        $connected = 0;
        while (($bucket = $byPrev->get($prev)) && $bucket->count() === 1) {
            $connected++;
            $prev = $bucket->first()->hash;
        }
        $this->warn("قبل: {$total} سجل مختوم، يتّصل من البداية {$connected} فقط، ومنقطعٌ " . ($total - $connected) . '.');

        // إعادةُ الوصل بترتيب الإدراج — معاملةٌ واحدة (انقطاعها يترك سلسلةً مكسورة)
        $ids = DB::table('audits')->whereNotNull('hash')->orderBy('id')->pluck('id')->all();
        $head = str_repeat('0', 64);
        DB::transaction(function () use ($ids, &$head) {
            foreach (array_chunk($ids, self::BATCH) as $chunk) {
                $rows = AuditEntry::whereIn('id', $chunk)->get()->keyBy('id');
                foreach ($chunk as $id) {                 // ترتيب id محفوظٌ داخل الرزمة
                    $hash = hash('sha256', $head . '|' . $rows[$id]->canonical());
                    DB::table('audits')->where('id', $id)->update(['prev_hash' => $head, 'hash' => $hash]);
                    $head = $hash;
                }
            }
            DB::table('audit_chain')->where('id', 1)->update(['head' => $head]);
        });

        // بصمةُ الفعل نفسِه: تُلحَق على الرأس الجديد فتصير آخرَ حلقةٍ في السلسلة
        // الملتئمة — لا إعادةَ بناءٍ بلا أثرٍ يُقرأ في مركز التدقيق.
        hub_audit('إعادة بناء سلسلة التدقيق', null, null, null,
            ['name' => "أُعيد وصلُ {$total} سجل من البداية بترتيب الإدراج"]);

        $this->info("🔧 أُعيد بناءُ السلسلة: {$total} سجل أُعيد ختمُها من البداية، والرأسُ محدَّث. شغّل `php artisan hub:audit-verify` للتأكد.");
        $this->warn('تذكير: إعادةُ البناء تُعيد الوصلَ على المحتوى الحالي ولا تُثبت أنه لم يُعبَث به قبلها — استعملها بعد استعادةٍ موثوقة أو نقلٍ مقصود فقط.');

        return self::SUCCESS;
    }

    /**
     * قيودٌ يخالف `company_id` فيها شركةَ سجلِّها المختوم.
     *
     * العمودُ **مشتقٌّ** لا مختوم: هجرةُ `add_company_to_audits` تملؤه **بعد**
     * الختم، فضمُّه إلى البصمة يكسر تلك الهجرةَ واختبارَها معاً. لكنّه مع ذلك
     * عمودُ العزل نفسُه — فتبديلُه يُخرج قيداً من نطاق مالكه أو يُدخله في نطاق
     * غيره **بلا كسر السلسلة**. فيُحرَس اشتقاقاً: `(module, record_id)` مختومان
     * داخل البصمة، ومنهما تُقرأ شركةُ السجل الحقيقية وتُقارَن.
     */
    /**
     * @return array{diff: int, blank: int}
     *
     * **وثلاثةُ أشكالٍ للانحراف لا شكلٌ واحد.** `company_id <> t.company_id`
     * مقارنةٌ ثلاثيّة القيمة: أيُّ طرفٍ `NULL` يعطيها `NULL` لا `TRUE` —
     * و`whereNotNull('audits.company_id')` كانت تُقصي شكلاً ثالثاً فوق ذلك.
     * فيفلت من الفحص:
     *
     *  · قيدٌ عمودُه مفرَّغ وسجلُّه في شركة — وهو **أخطرُها**: `whereIn` لا
     *    يُطابق `NULL` فيختفي القيدُ عن كلِّ صاحب نطاق. يُعدّ في `blank`
     *    لأنّ له سبباً مشروعاً شائعاً (تركيبةٌ سبقت العمود).
     *  · قيدٌ يحمل شركةً وسجلُّه فُرّغت شركتُه — انحرافُ قيمةٍ كالأوّل،
     *    فيُضمّ إلى `diff`.
     */
    protected function companyMismatch(): array
    {
        if (! Schema::hasColumn('audits', 'company_id')) return ['diff' => 0, 'blank' => 0];

        $diff = 0; $blank = 0;
        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            $ccol = hub_company_col($mk);
            if ($table === '' || ! $ccol || ! Schema::hasTable($table)) continue;

            $base = fn () => DB::table('audits')
                ->join($table, 'audits.record_id', '=', "{$table}.id")
                ->where('audits.module', $mk)
                ->whereNotNull('audits.record_id');

            try {
                // قيمتان مختلفتان، أو قيدٌ بشركةٍ وسجلٌّ بلا شركة
                $diff += (int) $base()
                    ->whereNotNull('audits.company_id')
                    ->whereRaw("({$table}.{$ccol} is null or audits.company_id <> {$table}.{$ccol})")
                    ->count();

                $blank += (int) $base()
                    ->whereNull('audits.company_id')
                    ->whereNotNull("{$table}.{$ccol}")
                    ->count();
            } catch (\Throwable $e) {
                continue;   // وحدةٌ بعمودٍ مغاير أو جدولٌ غير مطابق: تُتخطّى بلا إفشال
            }
        }

        return ['diff' => $diff, 'blank' => $blank];
    }

    /**
     * إعادة ختم السلسلة كاملةً بالبصمة الحالية — لا تُنفَّذ إلا بعد تحقق نظيف أعلاه.
     * معاملة واحدة (انقطاعها في المنتصف يترك سلسلة مكسورة) لكن التحميل دفعةً دفعة.
     */
    protected function reseal(array $orderedIds): void
    {
        $prev = str_repeat('0', 64);
        DB::transaction(function () use ($orderedIds, &$prev) {
            foreach (array_chunk($orderedIds, self::BATCH) as $ids) {
                $rows = AuditEntry::whereIn('id', $ids)->get()->keyBy('id');
                foreach ($ids as $id) {
                    $hash = hash('sha256', $prev . '|' . $rows[$id]->canonical());
                    DB::table('audits')->where('id', $id)->update(['prev_hash' => $prev, 'hash' => $hash]);
                    $prev = $hash;
                }
            }
            DB::table('audit_chain')->where('id', 1)->update(['head' => $prev]);
        });

        $this->info('🔄 أُعيد ختم ' . count($orderedIds) . ' سجل بالبصمة الكاملة — التحقق التالي سيكون خالياً من التحذير');
    }
}
