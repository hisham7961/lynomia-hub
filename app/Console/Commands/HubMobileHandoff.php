<?php

namespace App\Console\Commands;

use App\Support\Mobile\MobileHandoffDoc;
use Illuminate\Console\Command;

/**
 * يولّد خريطةَ نقاطِ الجوال في وثيقة التسليم من المسارات الحيّة (خطّةُ التطبيق · 2.5):
 * `--write` يكتب القسمَ بين العلامتين، و`--check` يُسقط (رمزٌ غيرُ صفريّ) عند الانحراف.
 */
class HubMobileHandoff extends Command
{
    protected $signature = 'hub:mobile-handoff {--write : اكتب القسمَ المولَّد في الوثيقة} {--check : افشل إن انحرفت الوثيقة}';

    protected $description = 'توليد خريطة نقاط /api/mobile/v1 في docs/mobile-readiness/10-mobile-app-handoff.md';

    public function handle(): int
    {
        $path = base_path(MobileHandoffDoc::PATH);
        $doc = (string) @file_get_contents($path);
        $synced = MobileHandoffDoc::sync($doc);
        if ($synced === null) {
            $this->error('✗ علامتا القسم المولَّد غائبتان عن ' . MobileHandoffDoc::PATH);

            return self::FAILURE;
        }

        if ($this->option('write')) {
            file_put_contents($path, $synced);
            $this->info('✓ كُتبت خريطةُ النقاط (' . MobileHandoffDoc::count() . ' مساراً)');

            return self::SUCCESS;
        }

        if ($synced !== $doc) {
            $this->error('✗ خريطةُ النقاط منحرفةٌ عن المسارات — شغّل: php artisan hub:mobile-handoff --write');

            return self::FAILURE;
        }
        $this->info('✓ خريطةُ النقاط مطابقة (' . MobileHandoffDoc::count() . ' مساراً)');

        return self::SUCCESS;
    }
}
