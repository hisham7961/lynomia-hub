<?php

namespace App\Console\Commands;

use App\Models\AuditEntry;
use Illuminate\Console\Command;

/**
 * التحقق من سلسلة تجزئة التدقيق: يمشي السلسلة من البداية ويعيد حساب كل تجزئة.
 * أي تعديل أو حذف أو إدراج مزور في السجلات المسلسلة يكسر المشي أو المطابقة.
 */
class HubAuditVerify extends Command
{
    protected $signature = 'hub:audit-verify';
    protected $description = 'التحقق من سلامة سلسلة تجزئة سجل التدقيق';

    public function handle(): int
    {
        $rows = AuditEntry::whereNotNull('hash')->get();
        $legacy = AuditEntry::whereNull('hash')->count();
        if ($rows->isEmpty()) {
            $this->info('لا سجلات مسلسلة بعد' . ($legacy ? " ({$legacy} سجل سابق للسلسلة)" : ''));
            return self::SUCCESS;
        }

        $byPrev = $rows->groupBy('prev_hash');
        $prev = str_repeat('0', 64);
        $ok = 0;

        while (true) {
            $bucket = $byPrev->get($prev);
            if (! $bucket) break;
            if ($bucket->count() > 1) {
                $this->error("⚠️ تفرع في السلسلة بعد {$ok} سجل — تجزئتان تشيران لنفس السابق (عبث محتمل)");
                return self::FAILURE;
            }
            $row = $bucket->first();
            $expect = hash('sha256', $prev . '|' . $row->canonical());
            if ($expect !== $row->hash) {
                $this->error("❌ سجل معدَّل بعد كتابته: {$row->id} ({$row->action} / {$row->module}) — التجزئة لا تطابق المحتوى");
                return self::FAILURE;
            }
            $ok++;
            $prev = $row->hash;
        }

        if ($ok < $rows->count()) {
            $this->error('❌ انقطاع: ' . ($rows->count() - $ok) . ' سجل مسلسل غير موصول بالسلسلة (حذف أو تزوير محتمل) — سلِم منها: ' . $ok);
            return self::FAILURE;
        }

        $head = (string) \Illuminate\Support\Facades\DB::table('audit_chain')->where('id', 1)->value('head');
        if ($head !== $prev) {
            $this->error('❌ رأس السلسلة المخزن لا يطابق آخر سجل — حُذفت سجلات من الذيل على الأرجح');
            return self::FAILURE;
        }

        $this->info("✅ السلسلة سليمة: {$ok} سجل متحقق" . ($legacy ? " · {$legacy} سجل سابق للسلسلة (خارج التحقق)" : ''));
        return self::SUCCESS;
    }
}
