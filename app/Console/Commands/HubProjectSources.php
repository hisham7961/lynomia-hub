<?php

namespace App\Console\Commands;

use App\Support\Ai\Sources\ProjectSources;
use Illuminate\Console\Command;

/**
 * **مصادرُ فهم المشروع** (docs/ai-hub/47 §العمود ج) — نصُّ ملفّات المشاريع ولقطاتُ مواقعها. خطوةٌ يوميّة في
 * `hub:automation`، وهنا يدويّاً:
 *
 *     php artisan hub:project-sources --dry              # كم ملفّاً سيُستخرج وكم موقعاً سيُقرأ — بلا قراءة
 *     php artisan hub:project-sources --project=<uuid>   # مشروعٌ واحد (وموقعُه يُقرأ الآن ولو كانت لقطتُه حديثة)
 */
class HubProjectSources extends Command
{
    protected $signature = 'hub:project-sources {--project= : معرّفُ مشروعٍ واحد} {--dry : ما سيحدث دون قراءة}'
        . ' {--no-site : الملفّات وحدَها} {--no-files : المواقع وحدَها}';

    protected $description = 'مصادرُ فهم المشروع: استخراجُ نصّ ملفّاته ولقطةُ موقعه';

    public function handle(): int
    {
        if (! ProjectSources::ready()) {
            $this->warn('الجداولُ غيرُ موجودة — شغّل الترحيل أوّلاً.');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry');
        if ($dry) $this->warn('وضعُ المعاينة — لا قراءةَ ولا كتابة');
        if (! class_exists(\Smalot\PdfParser\Parser::class)) {
            $this->warn('قارئُ PDF غيرُ مثبّت — شغّل: composer install --no-dev --optimize-autoloader (ملفّاتُ PDF تُعلَّم no_reader حتى ذلك)');
        }

        $pid = trim((string) $this->option('project'));
        $s = ProjectSources::run($pid !== '' ? $pid : null, $dry, ! $this->option('no-files'), ! $this->option('no-site'));
        $this->info(($dry ? 'سيُستخرج' : 'استُخرج') . " {$s['files']} ملفّاً · " . ($dry ? 'سيُقرأ' : 'قُرئ') . " {$s['sites']} موقعاً"
            . " · تغيّر {$s['changed']} · أخطاء {$s['errors']}");

        return self::SUCCESS;
    }
}
