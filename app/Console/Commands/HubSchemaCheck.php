<?php

namespace App\Console\Commands;

use App\Support\SchemaGuard;
use Illuminate\Console\Command;

/**
 * **يكشف الفروقات ويرحّلها — ولا يغيّر القاعدة.**
 *
 * يقارن ما يقرؤه الكود (سجل الوحدات `config/hub.php`) بما تملكه القاعدة الحيّة،
 * فيسمّي كل عمودٍ أو جدولٍ ناقص. بلا `--fix` **لا يكتب حرفاً** — يفحص ويُبلّغ.
 * ومع `--fix` يُضيف الناقص **إضافةً فقط**: لا إسقاطَ عمود، ولا تغييرَ نوع، ولا
 * مساسَ ببيانة واحدة.
 */
class HubSchemaCheck extends Command
{
    protected $signature = 'hub:schema-check {--fix : أضِف الأعمدة الناقصة (إضافةً فقط، بلا مساس بالبيانات)}';
    protected $description = 'يقارن ما يقرؤه الكود بما تملكه القاعدة، ويسدّ الناقص بالإضافة وحدها';

    public function handle(): int
    {
        $gaps = SchemaGuard::gaps();

        if (! $gaps) {
            $this->info('✅ القاعدة تطابق ما يقرؤه الكود — لا فروقات.');
            $this->reportDrift();

            return self::SUCCESS;
        }

        $this->warn('⚠️ فروقاتٌ بين ما يقرؤه الكود وما تملكه القاعدة (' . count($gaps) . '):');
        foreach ($gaps as $g) {
            $this->line('  · ' . $g['what'] . ($g['type'] === 'table'
                ? '  ← جدولٌ كامل غائب (شغّل php artisan migrate)'
                : '  ← ' . $g['label']));
        }

        if (! $this->option('fix')) {
            $this->reportDrift();
            $this->newLine();
            $this->line('لم يُغيَّر شيء. لسدّ الناقص بالإضافة وحدها:');
            $this->line('  php artisan migrate            ← الطريق الصحيح أولاً (يشغّل الهجرات)');
            $this->line('  php artisan hub:schema-check --fix   ← لسدّ ما بقي ناقصاً بعدها');

            return self::FAILURE;
        }

        $added = SchemaGuard::fix();
        $this->newLine();
        $this->info($added
            ? '✅ أُضيف ' . count($added) . ' عموداً: ' . implode('، ', $added)
            : 'لم يُضَف شيء — الفروقاتُ الباقية جداولُ كاملة، مكانُها php artisan migrate.');

        $left = SchemaGuard::gaps();
        if ($left) $this->warn('⚠️ بقي ' . count($left) . ' فرقاً يحتاج ترحيلاً.');
        $this->reportDrift();

        return $left ? self::FAILURE : self::SUCCESS;
    }

    /**
     * **انحرافُ الحالات يُقال** — والأمرُ لا يسقط به.
     *
     * الفرقُ في المخطّط عطلٌ يمنع التشغيل، والانحرافُ في القيمة **بيانةٌ
     * قائمة**: صفٌّ صحيحٌ يحمل حالةً لم تعد معلنة. فلو قلب رمزَ الخروج
     * لأسقط بوّابةَ نشرٍ على بيانةِ عميلٍ لا على عيبِ شيفرة. يُقال ولا يُسقِط.
     */
    private function reportDrift(): void
    {
        $drift = SchemaGuard::statusDrift();
        if (! $drift) return;

        $rows = array_sum(array_column($drift, 'count'));
        $this->newLine();
        $this->warn('⚠️ حالاتٌ في القاعدة خارجَ سجلِّ الوحدات — '
            . count($drift) . ' قيمةً في ' . $rows . ' صفّاً:');
        foreach ($drift as $d) {
            $this->line('  · ' . $d['label'] . ' (' . $d['module'] . '.' . $d['col'] . ') '
                . '«' . $d['value'] . '» × ' . $d['count']
                . '  ← المُعلَن: ' . \Illuminate\Support\Str::limit(implode('·', $d['options']), 70));
        }
        $this->newLine();
        $this->line('الصفُّ المنحرفُ ظاهرٌ في لوحةِ كانبان تحت «⚠ غير مصنّفة»، لكنّ');
        $this->line('مُرشِّحَ الحالةِ لا يسمّيه. صحّحه من شاشتِه، أو أعِد الخيارَ إلى السجلّ.');
    }
}
