<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ترحيل مرفقات السجلات القديمة من القرص العام إلى الخاص —
 * (كانت تُخدم بلا مصادقة). شعار الهوية hub/branding يبقى عاماً لصفحة الدخول.
 * الروابط في الواجهة لا تتغير: بوابة /files تخدم من الموقعين.
 */
class HubPrivatizeFiles extends Command
{
    protected $signature = 'hub:privatize-files {--dry : عرض ما سيُنقل بلا تنفيذ}';
    protected $description = 'نقل مرفقات hub/ من القرص العام إلى الخاص (عدا الشعار)';

    public function handle(): int
    {
        $src = storage_path('app/public/hub');
        $dst = storage_path('app/hub');
        if (! is_dir($src)) { $this->info('لا مجلد hub عام — لا شيء يُنقل'); return self::SUCCESS; }

        $moved = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = ltrim(str_replace($src, '', $file->getPathname()), '/');
            if (str_starts_with($rel, 'branding/')) continue;          // الشعار يبقى عاماً

            $to = $dst . '/' . $rel;
            if ($this->option('dry')) { $this->line("سيُنقل: hub/{$rel}"); $moved++; continue; }

            @mkdir(dirname($to), 0755, true);
            if (@rename($file->getPathname(), $to)) $moved++;
            else $this->error("تعذر نقل: {$rel}");
        }

        $this->info(($this->option('dry') ? 'للنقل: ' : '✓ نُقل: ') . $moved . ' ملف — تُخدم الآن عبر بوابة الملفات المصادَق عليها فقط');

        return self::SUCCESS;
    }
}
