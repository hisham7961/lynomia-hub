<?php

namespace App\Console\Commands;

use App\Support\Ai\Brain\Brain;
use App\Support\Ai\Brain\PhpVectorStore;
use Illuminate\Console\Command;

/**
 * **فهرسةُ العقل الثاني يدويّاً** — والمجدولةُ خطوةٌ في `hub:automation`.
 *
 *     php artisan hub:brain --dry     # كم مقطعاً سيُضمَّن، بلا نداءٍ ولا كتابة
 *     php artisan hub:brain --setup   # يُنشئ جدولَ المتّجهات في القاعدة المضبوطة (BRAIN_DB_CONNECTION)
 */
class HubBrain extends Command
{
    protected $signature = 'hub:brain {--dry : عدُّ ما سيُضمَّن دون نداءٍ ولا كتابة} {--setup : إنشاءُ جدول المتّجهات في قاعدة العقل المضبوطة}';
    protected $description = 'العقلُ الثاني — جولةُ فهرسةٍ دلاليّةٍ للمعرفة والمحاضر والقرارات';

    public function handle(): int
    {
        if ($this->option('setup')) {
            $conn = PhpVectorStore::connection();
            $made = PhpVectorStore::ensureTable($conn);
            $this->info(($made ? 'أُنشئ' : 'موجودٌ أصلاً') . ' جدولُ المتّجهات في «' . ($conn ?? 'القاعدة الرئيسة') . '»');

            return self::SUCCESS;
        }
        if (($why = Brain::whyNot()) !== null) {
            $this->warn('لا فهرسة: ' . $why);

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لا نداءَ ولا كتابة');

        $s = Brain::index($dry);
        $this->info(($dry ? 'سيُضمَّن' : 'ضُمِّن') . " {$s['embedded']} مقطعاً · بلا تغيير {$s['skipped']} · مُحي {$s['removed']} · سجلّات {$s['records']}");
        if ($s['stopped'] !== null) $this->warn('⚠ الجولةُ ناقصة: ' . $s['stopped']);

        return self::SUCCESS;
    }
}
