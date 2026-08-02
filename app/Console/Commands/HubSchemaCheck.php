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

            return self::SUCCESS;
        }

        $this->warn('⚠️ فروقاتٌ بين ما يقرؤه الكود وما تملكه القاعدة (' . count($gaps) . '):');
        foreach ($gaps as $g) {
            $this->line('  · ' . $g['what'] . ($g['type'] === 'table'
                ? '  ← جدولٌ كامل غائب (شغّل php artisan migrate)'
                : '  ← ' . $g['label']));
        }

        if (! $this->option('fix')) {
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

        return $left ? self::FAILURE : self::SUCCESS;
    }
}
