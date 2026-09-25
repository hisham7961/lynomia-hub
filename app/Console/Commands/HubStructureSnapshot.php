<?php

namespace App\Console\Commands;

use App\Support\Platform\StructureSnapshot;
use Illuminate\Console\Command;

/**
 * يكتب لقطاتِ البنية (المسارات · سجلّ الوحدات · الأصناف · الدوالّ) أو يقارن بها.
 *
 * الكتابةُ **فعلٌ مقصود**: تغييرٌ بنيويٌّ مُراد (مسارٌ جديد، نقلُ صنف) يُتبَع بـ`--write`
 * فيظهر الفرقُ في الالتزام للمراجعة. والحزمةُ (`StructureSnapshotTest`) تُسقط أيَّ
 * تغييرٍ لم تُكتب له لقطة.
 */
class HubStructureSnapshot extends Command
{
    protected $signature = 'hub:structure-snapshot {--write : إعادةُ كتابةِ اللقطات من الحالة الحيّة}';
    protected $description = 'لقطاتُ البنية لإعادة التنظيم الآمنة — مقارنةٌ أو كتابة';

    public function handle(): int
    {
        $drift = 0;
        foreach (StructureSnapshot::PARTS as $part) {
            $live = StructureSnapshot::build($part);
            if ($this->option('write')) {
                $file = StructureSnapshot::path($part);
                if (! is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
                file_put_contents($file, StructureSnapshot::encode($live));
                $this->info("✓ {$part}: " . count($live) . ' عنصراً كُتب');

                continue;
            }
            if (StructureSnapshot::stored($part) === $live) {
                $this->info("✓ {$part}: مطابق");
            } else {
                $drift++;
                $this->error("✗ {$part}: منحرفٌ عن اللقطة");
            }
        }

        return $drift === 0 ? self::SUCCESS : self::FAILURE;
    }
}
