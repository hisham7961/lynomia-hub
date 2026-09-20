<?php

namespace App\Console\Commands;

use App\Support\AiProviderRegistry;
use Illuminate\Console\Command;

/**
 * **يولّد خريطةَ المزوّدين من قياسِ مصدرِ البوّابة.** (المرحلة ٢ · إغلاقُ التغطية)
 *
 * المُدخَلُ ملفُّ قياسٍ أنتجه `deploy/litellm/tools/measure_providers.py` من
 * شيفرةِ الإصدارِ المثبَّت؛ والمُخرَجُ `config/ai_providers.php`.
 *
 * **ولماذا أمرٌ أصلاً بدل قراءةِ القياسِ وقتَ التشغيل؟** لأنّ ملفَّ القياسِ
 * مئةُ كيلوبايتٍ من الأدلّةِ الخامّ — يُقرأ مرّةً عند الترقيةِ لا عند كلِّ طلب.
 * والمُخرَجُ إعدادُ PHP يدخل ذاكرةَ الإعداداتِ المخبوءةَ كغيرِه.
 *
 * **والقواعدُ في `AiProviderRegistry` لا هنا** — فالتصنيفُ يُختبَر بلا تشغيلِ
 * أمر، وهذا الصنفُ قشرةُ إدخالٍ وإخراجٍ لا أكثر.
 */
final class HubAiProviderMap extends Command
{
    protected $signature = 'hub:ai-provider-map
        {--from= : ملفُّ القياس (افتراضُه آخرُ ملفٍّ في deploy/litellm/measured)}
        {--out=config/ai_providers.php : وجهةُ الكتابة}
        {--check : يقارن ولا يكتب — للبوّابةِ الآليّة}';

    protected $description = 'يولّد خريطةَ المزوّدين من قياسِ مصدرِ LiteLLM';

    public function handle(): int
    {
        $from = (string) ($this->option('from') ?: $this->latestMeasurement());
        if ($from === '' || ! is_file($from)) {
            $this->error('لا ملفَّ قياس. شغّل deploy/litellm/tools/measure_providers.py أوّلاً.');

            return self::FAILURE;
        }

        $measured = json_decode((string) file_get_contents($from), true);
        if (! is_array($measured) || ! isset($measured['providers'])) {
            $this->error("ملفُّ القياس غيرُ صالح: $from");

            return self::FAILURE;
        }

        $php = AiProviderRegistry::renderMap($measured);
        $out = base_path((string) $this->option('out'));

        if ($this->option('check')) {
            $current = is_file($out) ? (string) file_get_contents($out) : '';
            if (trim($current) !== trim($php)) {
                $this->error('خريطةُ المزوّدين منحرفةٌ عن القياس — أعِد التوليد.');

                return self::FAILURE;
            }
            $this->info('خريطةُ المزوّدين مطابقةٌ للقياس.');

            return self::SUCCESS;
        }

        file_put_contents($out, $php);

        $rows = count($measured['providers']);
        $this->info("وُلِّدت خريطةُ {$rows} مزوّداً من قياسِ الإصدار "
            . ($measured['litellm_version'] ?? '?') . " → " . $this->option('out'));

        return self::SUCCESS;
    }

    private function latestMeasurement(): string
    {
        $files = glob(base_path('deploy/litellm/measured/*.json')) ?: [];
        sort($files);

        return $files === [] ? '' : (string) end($files);
    }
}
