<?php

namespace App\Console\Commands;

use App\Support\OpenApi;
use Illuminate\Console\Command;

/**
 * يكتب مواصفة OpenAPI الكاملة (كل الوحدات، كل الحقول) إلى ملفٍ — للتوثيق وأدوات
 * العملاء. النسخةُ الحيّة على `/api/v1/openapi.json` مقصورةٌ على ما يراه المفتاح.
 */
class HubOpenApi extends Command
{
    protected $signature = 'hub:openapi {--out=docs/openapi.json : مسار الملف الناتج (نسبيّاً لجذر المشروع)}';
    protected $description = 'توليد مواصفة OpenAPI 3.1 لواجهة /api/v1 من سجل الوحدات';

    public function handle(): int
    {
        $out = (string) $this->option('out');
        $path = str_starts_with($out, '/') ? $out : base_path($out);
        $json = json_encode(OpenApi::spec(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->error('✗ تعذّر ترميز المواصفة: ' . json_last_error_msg());

            return self::FAILURE;
        }
        if (! is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
        file_put_contents($path, $json . "\n");
        $spec = OpenApi::spec();
        $this->info('✓ ' . $out . ' — ' . count($spec['paths']) . ' مساراً، ' . count($spec['components']['schemas']) . ' مخطّطاً، الإصدار ' . $spec['info']['version']);

        return self::SUCCESS;
    }
}
