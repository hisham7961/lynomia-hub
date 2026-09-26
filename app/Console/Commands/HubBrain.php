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
    protected $signature = 'hub:brain {--dry : عدُّ ما سيُضمَّن دون نداءٍ ولا كتابة} {--setup : إنشاءُ جدول المتّجهات في قاعدة العقل المضبوطة} {--purge-main : مع --setup: محوُ المتّجهات القديمة من القاعدة الرئيسة}';
    protected $description = 'العقلُ الثاني — جولةُ فهرسةٍ دلاليّةٍ للمعرفة والمحاضر والقرارات';

    public function handle(): int
    {
        if ($this->option('setup')) {
            $conn = PhpVectorStore::connection();
            $made = PhpVectorStore::ensureTable($conn);
            $this->info(($made ? 'أُنشئ' : 'موجودٌ أصلاً') . ' جدولُ المتّجهات في «' . ($conn ?? 'القاعدة الرئيسة') . '»');
            // الانتقالُ إلى قاعدةٍ مستقلّة لا ينقل المتّجهاتِ القديمة ولا يمحوها — يُقال، والمحوُ بطلبٍ صريح
            if ($conn !== null && \Illuminate\Support\Facades\Schema::hasTable('ai_embeddings')) {
                $left = \Illuminate\Support\Facades\DB::table('ai_embeddings')->count();
                if ($left > 0 && $this->option('purge-main')) {
                    \Illuminate\Support\Facades\DB::table('ai_embeddings')->delete();
                    $this->info("مُحي {$left} مقطعاً قديماً من القاعدة الرئيسة");
                } elseif ($left > 0) {
                    $this->warn("في القاعدة الرئيسة {$left} مقطعاً قديماً لا يُستعمل بعد الآن — لا تُنقل (تُعاد الفهرسةُ في المستقلّة)؛ امحها بـ --purge-main");
                }
            }

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
