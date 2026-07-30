<?php

namespace App\Console\Commands;

use App\Models\AuditEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * التحقق من سلسلة تجزئة التدقيق: يمشي السلسلة من البداية ويعيد حساب كل تجزئة.
 * أي تعديل أو حذف أو إدراج مزور في السجلات المسلسلة يكسر المشي أو المطابقة.
 *
 * البصمة الحالية (v2) تشمل الأعمدة الجنائية أيضاً: القسم والجهاز وعنوان IP.
 * السجلات المختومة ببصمة الجيل الأول تبقى مقبولة (فلا إنذار كاذب) لكنها تُحصى
 * وتُعرض بصراحة لأن تغطيتها أضعف — و`--reseal` يرقّيها بعد تحقق نظيف.
 */
class HubAuditVerify extends Command
{
    protected $signature = 'hub:audit-verify {--reseal : ترقية السجلات المختومة ببصمة قديمة إلى البصمة الكاملة (بعد تحقق نظيف)}';
    protected $description = 'التحقق من سلامة سلسلة تجزئة سجل التدقيق';

    public function handle(): int
    {
        $rows = AuditEntry::whereNotNull('hash')->get();
        $legacyRows = AuditEntry::whereNull('hash')->count();

        if ($rows->isEmpty()) {
            $this->info('لا سجلات مسلسلة بعد' . ($legacyRows ? " ({$legacyRows} سجل سابق للسلسلة)" : ''));
            return self::SUCCESS;
        }

        $byPrev = $rows->groupBy('prev_hash');
        $prev = str_repeat('0', 64);
        $ok = 0; $weak = 0;
        $chain = [];                                  // الترتيب الحقيقي للسلسلة — يلزم لإعادة الختم

        while (true) {
            $bucket = $byPrev->get($prev);
            if (! $bucket) break;
            if ($bucket->count() > 1) {
                $this->error("⚠️ تفرع في السلسلة بعد {$ok} سجل — تجزئتان تشيران لنفس السابق (عبث محتمل)");
                return self::FAILURE;
            }

            $row = $bucket->first();
            $strong = hash('sha256', $prev . '|' . $row->canonical());
            $old    = hash('sha256', $prev . '|' . $row->canonical(true));

            if ($strong === $row->hash) {
                // مختوم بالبصمة الكاملة
            } elseif ($old === $row->hash) {
                $weak++;                              // بصمة الجيل الأول: مقبولة لكنها لا تحمي IP/الجهاز
            } else {
                $this->error("❌ سجل معدَّل بعد كتابته: {$row->id} ({$row->action} / {$row->module}) — التجزئة لا تطابق المحتوى");
                return self::FAILURE;
            }

            $ok++;
            $chain[] = $row;
            $prev = $row->hash;
        }

        if ($ok < $rows->count()) {
            $this->error('❌ انقطاع: ' . ($rows->count() - $ok) . ' سجل مسلسل غير موصول بالسلسلة (حذف أو تزوير محتمل) — سلِم منها: ' . $ok);
            return self::FAILURE;
        }

        $head = (string) DB::table('audit_chain')->where('id', 1)->value('head');
        if ($head !== $prev) {
            $this->error('❌ رأس السلسلة المخزن لا يطابق آخر سجل — حُذفت سجلات من الذيل على الأرجح');
            return self::FAILURE;
        }

        $this->info("✅ السلسلة سليمة: {$ok} سجل متحقق"
            . ($legacyRows ? " · {$legacyRows} سجل سابق للسلسلة (خارج التحقق)" : ''));

        if ($weak) {
            $this->warn("⚠️ {$weak} سجل مختوم ببصمة الجيل الأول — لا تحمي عمودَي الجهاز وعنوان IP."
                . ($this->option('reseal') ? '' : ' رقّها بـ: php artisan hub:audit-verify --reseal'));
        }

        if ($this->option('reseal') && $weak) {
            $this->reseal($chain);
        }

        return self::SUCCESS;
    }

    /** إعادة ختم السلسلة كاملةً بالبصمة الحالية — لا تُنفَّذ إلا بعد تحقق نظيف أعلاه */
    protected function reseal($chain): void
    {
        $prev = str_repeat('0', 64);
        DB::transaction(function () use ($chain, &$prev) {
            foreach ($chain as $row) {
                $hash = hash('sha256', $prev . '|' . $row->canonical());
                DB::table('audits')->where('id', $row->id)->update(['prev_hash' => $prev, 'hash' => $hash]);
                $prev = $hash;
            }
            DB::table('audit_chain')->where('id', 1)->update(['head' => $prev]);
        });

        $this->info('🔄 أُعيد ختم ' . count($chain) . ' سجل بالبصمة الكاملة — التحقق التالي سيكون خالياً من التحذير');
    }
}
