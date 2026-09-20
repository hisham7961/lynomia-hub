<?php

namespace App\Console\Commands;

use App\Support\AiProviderRegistry;
use Illuminate\Console\Command;

/**
 * **يُثبِّت شعاراتِ المزوّدين في المستودعِ ويكتب خريطتَها** — مولَّدةً لا تُحرَّر.
 *
 * ── **ولمَ تُثبَّت الملفّاتُ بدل جلبِها وقتَ العرض؟** ──
 *
 * شعارٌ يُجلَب من الإنترنت عند كلِّ فتحٍ للشاشةِ يعني ثلاثةَ أشياء: **صفحةُ
 * إدارةٍ تعتمد على شبكةٍ خارجيّة** فتبطؤ أو تتعطّل بعطلٍ لا يخصّنا، و**أثرٌ
 * يُرسَل إلى طرفٍ ثالثٍ عند كلِّ زيارة**، و**مصدرٌ قد يتبدّل تحتنا** فيصير
 * شعارُ شركةٍ صورةً أخرى بلا أن نعلم. فالملفّاتُ تُنسَخ مرّةً وتُراجَع
 * وتُختبَر، ثمّ لا تتغيّر إلّا بتوليدٍ صريح.
 *
 * ── **والمصدرُ لا يُخمَّن** ──
 *
 * الأمرُ يقرأ مجلَّدَ أيقوناتٍ مُستخرَجاً (`--src`)، ولا ينزل شيئاً بنفسِه.
 * فالتنزيلُ خطوةٌ يقوم بها إنسانٌ ويراها، **ولا يصير جزءاً صامتاً من أمرٍ
 * يُشغَّل في بناءٍ آليّ**.
 *
 * ── **وما لا شعارَ له يبقى بلا شعار** ──
 *
 * لا يُخترَع ملفٌّ ولا يُستعار من مزوّدٍ مجاور. الخريطةُ تحمل من له شعارٌ
 * وحدَه، و`AiProviderRegistry::mark()` تُغطّي الباقي بحرفين. **فالنقصُ
 * مُعلَنٌ لا مُغطّىً بكذب.**
 */
final class HubAiLogos extends Command
{
    protected $signature = 'hub:ai-logos
        {--src= : مجلَّدُ أيقوناتٍ مُستخرَجٌ فيه ملفّاتُ svg}
        {--check : يقارن ولا يكتب}';

    protected $description = 'ينسخ شعاراتِ المزوّدين ويولّد config/ai_logos.php';

    /** حيث تسكن الشعاراتُ داخلَ المستودع */
    public const DIR = 'public/img/ai/providers';

    public function handle(): int
    {
        $map = $this->option('src') !== null && $this->option('src') !== ''
            ? $this->build((string) $this->option('src'))
            : null;

        if ($this->option('check')) {
            return $this->check();
        }

        if ($map === null) {
            $this->error('لا مصدرَ — مرّر `--src=<مجلَّدُ أيقونات> ` أو استعمل `--check`.');

            return self::FAILURE;
        }

        $this->write($map);

        return self::SUCCESS;
    }

    /**
     * **يبني الخريطةَ من المصدرِ بالمطابقةِ المقيسة.**
     *
     * والترتيبُ مقصود: مطابقةٌ آليّةٌ بالاسمِ المُطبَّع أوّلاً، ثمّ الأسماءُ
     * المكتوبةُ بيدٍ في `config/ai_logo_aliases.php`. **والمكتوبُ بيدٍ يغلب**
     * لأنّه قرارٌ بشريٌّ مُراجَع، والآليُّ تخمينٌ محسوب.
     *
     * @return array{logos: array<string,string>, files: list<string>, missing: list<string>}
     */
    private function build(string $src): array
    {
        $dir = rtrim($src, '/');
        if (! is_dir($dir)) {
            $this->error("مجلَّدُ المصدرِ غيرُ موجود: {$dir}");
            exit(self::FAILURE);
        }

        $have = [];
        foreach ((array) scandir($dir) as $f) {
            if (is_string($f) && str_ends_with($f, '.svg')) $have[$f] = true;
        }

        $aliases = (array) config('ai_logo_aliases', []);
        $logos   = [];
        $missing = [];

        foreach (array_keys(AiProviderRegistry::map()) as $slug) {
            $key  = AiProviderRegistry::hubKey($slug);
            $base = isset($aliases[$key]) ? (string) $aliases[$key] : $this->normal($key);

            // **الملوَّنُ أوّلاً ثمّ الأحاديّ** — وشعارٌ بلا لونٍ أصدقُ من لونٍ نخترعه
            $file = match (true) {
                isset($have[$base . '-color.svg']) => $base . '-color.svg',
                isset($have[$base . '.svg'])       => $base . '.svg',
                default                            => null,
            };

            if ($file === null) { $missing[] = $key; continue; }

            $logos[$key] = $file;
        }

        ksort($logos);
        sort($missing);

        return ['logos' => $logos, 'files' => array_values(array_unique(array_values($logos))),
                'missing' => $missing, 'src' => $dir];
    }

    /** الاسمُ المُطبَّع: حروفٌ وأرقامٌ لا غير */
    private function normal(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
    }

    /** ينسخ الملفّاتِ ويكتب الخريطة */
    private function write(array $map): void
    {
        $dest = base_path(self::DIR);
        if (! is_dir($dest)) mkdir($dest, 0o755, true);

        // **يُمسَح ما لم يعد في الخريطة** — فلا يبقى ملفٌّ يتيمٌ لا يشير إليه شيء
        foreach ((array) glob($dest . '/*.svg') as $old) {
            if (! in_array(basename((string) $old), $map['files'], true)) unlink((string) $old);
        }

        $bytes = 0;
        foreach ($map['files'] as $f) {
            $svg = (string) file_get_contents($map['src'] . '/' . $f);
            file_put_contents($dest . '/' . $f, $svg);
            $bytes += strlen($svg);
        }

        file_put_contents(base_path('config/ai_logos.php'), $this->render($map));

        $this->info(sprintf('نُسخ %d ملفّاً (%d KB) يغطّي %d مزوّداً — وبقي %d بلا شعار.',
            count($map['files']), (int) round($bytes / 1024),
            count($map['logos']), count($map['missing'])));
    }

    /** **يقارن ولا يكتب** — بوّابةُ انحرافٍ كأخواتِها */
    private function check(): int
    {
        $logos = (array) config('ai_logos.logos', []);
        $dest  = base_path(self::DIR);
        $bad   = [];

        foreach ($logos as $key => $file) {
            if (! is_file($dest . '/' . $file)) $bad[] = "{$key} → {$file} (الملفُّ مفقود)";
        }

        $declared = array_values(array_unique(array_values($logos)));
        foreach ((array) glob($dest . '/*.svg') as $f) {
            $b = basename((string) $f);
            if (! in_array($b, $declared, true)) $bad[] = "{$b} (ملفٌّ لا تشير إليه الخريطة)";
        }

        if ($bad !== []) {
            $this->error("خريطةُ الشعاراتِ منحرفةٌ عن الملفّات:\n" . implode("\n", $bad));

            return self::FAILURE;
        }

        $this->info('خريطةُ الشعاراتِ مطابقةٌ للملفّات (' . count($logos) . ' مزوّداً · '
            . count($declared) . ' ملفّاً).');

        return self::SUCCESS;
    }

    private function render(array $map): string
    {
        $rows = '';
        foreach ($map['logos'] as $k => $f) {
            $rows .= sprintf("        %-28s => %s,\n", "'" . $k . "'", "'" . $f . "'");
        }

        $missing = '';
        foreach (array_chunk($map['missing'], 5) as $chunk) {
            $missing .= '| ' . implode(' · ', $chunk) . "\n";
        }

        return <<<PHPX
        <?php
        
        /*
        |--------------------------------------------------------------------------
        | خريطةُ شعاراتِ المزوّدين — **مولَّدةٌ لا تُحرَّر**
        |--------------------------------------------------------------------------
        |
        | تُولَّد بـ`php artisan hub:ai-logos --src=<مجلَّدُ أيقونات>` من مفاتيحِ
        | `config/ai_providers.php` والأسماءِ المكتوبةِ بيدٍ في
        | `config/ai_logo_aliases.php`. **وتحريرُها بيدٍ يُمحى عند أوّلِ توليد.**
        |
        | الملفّاتُ نفسُها في `{$this->constDir()}`، وبوّابةُ
        | `php artisan hub:ai-logos --check` تُسقط البناءَ إن انحرفت الخريطةُ عنها.
        |
        | **والإسنادُ والترخيصُ في `docs/ai-hub/26-provider-logos.md`** — وهو
        | ليس تفصيلاً إداريّاً: الشعاراتُ علاماتٌ تجاريّةٌ لأصحابِها.
        |
        | ومزوّدٌ لا سطرَ له هنا **لا يبقى فارغاً**: `AiProviderRegistry::mark()`
        | تُولّد له علامةً حرفيّةً من اسمِه. وهؤلاء بلا شعارٍ اليوم:
        |
        {$missing}*/
        
        return [
        
            'source' => [
                'generator' => 'php artisan hub:ai-logos',
                'providers' => {$this->count($map['logos'])},
                'files'     => {$this->count($map['files'])},
                'missing'   => {$this->count($map['missing'])},
            ],
        
            'logos' => [
        {$rows}    ],
        
        ];
        
        PHPX;
    }

    private function constDir(): string
    {
        return self::DIR;
    }

    private function count(array $a): int
    {
        return count($a);
    }
}
