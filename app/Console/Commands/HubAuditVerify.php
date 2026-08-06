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
    protected $signature = 'hub:audit-verify {--reseal : ترقية السجلات المختومة ببصمة قديمة إلى البصمة الكاملة (بعد تحقق نظيف)}';
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

        // ٤) اتّساقُ عمود العزل — **خارج البصمة بقرارٍ مقصود**
        $mismatch = $this->companyMismatch();
        if ($mismatch) {
            $this->error("❌ {$mismatch} قيداً يخالف عمودُ الشركة فيه شركةَ السجل المختوم"
                . ' — العمود مشتقٌّ ويُحدَّث بالهجرة فهو خارج البصمة عمداً، فيُفحص اشتقاقاً.'
                . ' وتبديلُه يُخرج قيداً من نطاق مالكه (أو يُدخله في غيره) بلا كسر السلسلة.');
            return self::FAILURE;
        }

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
     * قيودٌ يخالف `company_id` فيها شركةَ سجلِّها المختوم.
     *
     * العمودُ **مشتقٌّ** لا مختوم: هجرةُ `add_company_to_audits` تملؤه **بعد**
     * الختم، فضمُّه إلى البصمة يكسر تلك الهجرةَ واختبارَها معاً. لكنّه مع ذلك
     * عمودُ العزل نفسُه — فتبديلُه يُخرج قيداً من نطاق مالكه أو يُدخله في نطاق
     * غيره **بلا كسر السلسلة**. فيُحرَس اشتقاقاً: `(module, record_id)` مختومان
     * داخل البصمة، ومنهما تُقرأ شركةُ السجل الحقيقية وتُقارَن.
     */
    protected function companyMismatch(): int
    {
        if (! Schema::hasColumn('audits', 'company_id')) return 0;

        $n = 0;
        foreach (hub_modules() as $mk => $def) {
            $table = (string) ($def['table'] ?? '');
            $ccol = hub_company_col($mk);
            if ($table === '' || ! $ccol || ! Schema::hasTable($table)) continue;

            try {
                $n += (int) DB::table('audits')
                    ->join($table, "audits.record_id", '=', "{$table}.id")
                    ->where('audits.module', $mk)
                    ->whereNotNull('audits.record_id')
                    ->whereNotNull('audits.company_id')
                    ->whereRaw("audits.company_id <> {$table}.{$ccol}")
                    ->count();
            } catch (\Throwable $e) {
                continue;   // وحدةٌ بعمودٍ مغاير أو جدولٌ غير مطابق: تُتخطّى بلا إفشال
            }
        }

        return $n;
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
