<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **رابطُ مصدر القيد** (TECH_DEBT #29 · `App\Support\Finance\JournalPosting`).
 *
 * المفتاحُ المتعدّد الأشكال نفسُه `(module, record_id)` — ومعه `source_key` يميّز الحدثَ
 * داخل المصدر (الدفعة رقم ن، عكسُها، اعتمادُ المسيّر…). و`UNIQUE` على الثلاثة هو حاجزُ
 * الترحيل المزدوج على القاعدة: مصدرٌ واحدٌ لا يُنتج قيدين مهما تسابقت المعاملات. والقيودُ
 * اليدويّة بلا مصدر (NULL) — والـNULL لا يتصادم في الفهرس الفريد على المحرّكَين.
 *
 * إضافيّةٌ لا كاسرة: أعمدةٌ قابلةٌ للفراغ، وملءٌ رجعيٌّ للمصادر المعروفة من `meta`
 * (الرواتب والعهدة) — **أوّلُها وحده** إن سبق أن تكرّر المصدرُ (فالتكرارُ القديمُ يبقى
 * مرئيّاً كما هو، ولا يُسقط الهجرةَ فهرسٌ فريد).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('journal_entries') || Schema::hasColumn('journal_entries', 'source_module')) return;

        Schema::table('journal_entries', function (Blueprint $t) {
            $t->string('source_module', 60)->nullable();
            $t->uuid('source_id')->nullable();
            $t->string('source_key', 120)->nullable();
            $t->unique(['source_module', 'source_id', 'source_key'], 'journal_entries_source_unique');
        });

        // ملءٌ رجعيّ: الترتيبُ الدلاليّ من وقت الإنشاء ثم المعرّف — فالأوّلُ يأخذ الرابط
        $seen = [];
        DB::table('journal_entries')->where('state', 'مرحّل')->whereNotNull('meta')
            ->orderBy('created_at')->orderBy('id')
            ->chunk(500, function ($rows) use (&$seen) {
                foreach ($rows as $r) {
                    $meta = json_decode((string) $r->meta, true) ?: [];
                    $src = match (true) {
                        ! empty($meta['payroll_id']) && ($meta['auto'] ?? '') === 'payroll'
                            => ['payroll', $meta['payroll_id'], 'approval'],
                        ! empty($meta['custody_move_id'])
                            => ['custody', $meta['custody_move_id'], 'move'],
                        ! empty($meta['reverses_move_id'])
                            => ['custody', $meta['reverses_move_id'], 'reversal'],
                        default => null,
                    };
                    if (! $src || ! is_string($src[1]) || strlen($src[1]) !== 36) continue;
                    $k = implode('|', $src);
                    if (isset($seen[$k])) continue;
                    $seen[$k] = true;

                    DB::table('journal_entries')->where('id', $r->id)->update([
                        'source_module' => $src[0], 'source_id' => $src[1], 'source_key' => $src[2],
                    ]);
                }
            });
    }

    public function down(): void
    {
        // إضافيّةٌ فقط (CLAUDE.md) — لا هدمَ في الرجوع
    }
};
