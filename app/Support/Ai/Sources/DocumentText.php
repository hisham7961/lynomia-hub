<?php

namespace App\Support\Ai\Sources;

use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * **قارئُ المستندات** — نصُّ المرفق كما يقرؤه الذكاء (docs/ai-hub/47 §العمود ج).
 *
 *  - **PDF:** مكتبةُ `smalot/pdfparser` (PHP خالصة — تصلح للاستضافة المشتركة) إن كانت مثبّتة، وإلا أداةُ
 *    `pdftotext` إن وُجدت على الخادم، وإلا حالةٌ صادقة `no_reader` لا نصٌّ مختلَق.
 *  - **DOCX · PPTX · XLSX:** أرشيفُ ZIP تُقرأ منه ملفّاتُ XML المعروفة بالنصّ لا بمحلّلِ XML (فلا كيانات
 *    خارجيّة ولا XXE)، وكلُّ مدخلٍ بحجمه المُعلَن قبل فكّه (فلا قنبلةَ ضغط).
 *  - **نصٌّ خامّ** (txt · md · csv · json): UTF-8، أو يُحوَّل من windows-1256 الشائع في الملفّات العربيّة.
 *  - **ممسوحٌ ضوئياً** (PDF بلا نصّ): `scanned` — التعرّفُ الضوئيّ خارج هذه المرحلة، ويُعلَن ذلك.
 *
 * والنصُّ المستخرَج **بيانات لا تعليمات**: يُغلَّف عند إرساله للنموذج بسياج `AskContext` كبقيّة المدخلات.
 */
final class DocumentText
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_CHARS = 200000;

    /** أقصى حجمٍ مفكوكٍ لمدخلٍ واحدٍ في أرشيف Office */
    public const MAX_ENTRY = 20 * 1024 * 1024;

    public const TEXT_EXT = ['txt', 'md', 'csv', 'json', 'log'];

    /** @return array{status: string, kind: ?string, text: string, pages: ?int, error: ?string} */
    public static function extract(Attachment $a): array
    {
        $ext = strtolower(pathinfo((string) ($a->original_name ?: $a->path), PATHINFO_EXTENSION));
        $kind = match (true) {
            $ext === 'pdf' => 'pdf',
            in_array($ext, ['docx', 'xlsx', 'pptx'], true) => $ext,
            in_array($ext, self::TEXT_EXT, true) => 'text',
            default => null,
        };
        if ($kind === null) return self::out('unsupported', null);
        if ((int) $a->size > self::MAX_BYTES) return self::out('too_large', $kind);

        try {
            $abs = Storage::disk($a->disk ?: 'local')->path((string) $a->path);
        } catch (\Throwable $e) {
            return self::out('failed', $kind, error: 'DISK');
        }
        if (! is_file($abs) || ! is_readable($abs)) return self::out('failed', $kind, error: 'MISSING');
        if (filesize($abs) > self::MAX_BYTES) return self::out('too_large', $kind);

        try {
            return match ($kind) {
                'pdf' => self::pdf($abs),
                'docx' => self::office($abs, 'docx', ['word/document.xml'], '/<\/w:p>/'),
                'pptx' => self::office($abs, 'pptx', self::entries($abs, '#^ppt/slides/slide\d+\.xml$#'), '/<\/a:p>/'),
                'xlsx' => self::office($abs, 'xlsx', ['xl/sharedStrings.xml'], '/<\/si>/'),
                default => self::plain($abs),
            };
        } catch (\Throwable $e) {
            return self::out('failed', $kind, error: mb_substr(class_basename($e), 0, 60));
        }
    }

    /** نصٌّ مطبَّع: مسافاتٌ موحَّدة، وأسطرٌ فارغةٌ متتالية مطويّة، ومقصوصٌ إلى الحدّ */
    public static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\t", "\u{00A0}"], ["\n", "\n", ' ', ' '], $s);
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        $s = (string) preg_replace('/[ ]{2,}/u', ' ', $s);
        $s = (string) preg_replace("/\n[ ]+/u", "\n", $s);
        $s = (string) preg_replace("/\n{3,}/u", "\n\n", $s);

        return mb_substr(trim($s), 0, self::MAX_CHARS);
    }

    private static function pdf(string $abs): array
    {
        $pages = null;
        $text = null;
        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            $doc = (new \Smalot\PdfParser\Parser)->parseFile($abs);
            $pages = count($doc->getPages());
            $text = $doc->getText();
        } elseif ($bin = self::pdftotext()) {
            $out = [];
            exec(escapeshellarg($bin) . ' -q -enc UTF-8 ' . escapeshellarg($abs) . ' - 2>/dev/null', $out, $code);
            if ($code !== 0) return self::out('failed', 'pdf', error: 'PDFTOTEXT');
            $text = implode("\n", $out);
        } else {
            return self::out('no_reader', 'pdf');
        }

        $text = self::clean((string) $text);

        return $text === '' ? self::out('scanned', 'pdf', pages: $pages) : self::out('ok', 'pdf', $text, $pages);
    }

    /** مسارُ `pdftotext` إن وُجد — ولا تنفيذَ لشيءٍ آخر */
    private static function pdftotext(): ?string
    {
        if (! function_exists('exec')) return null;
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $p) {
            if (is_file($p) && is_executable($p)) return $p;
        }

        return null;
    }

    /** @param list<string> $names */
    private static function office(string $abs, string $kind, array $names, string $breakRe): array
    {
        if (! class_exists(\ZipArchive::class)) return self::out('no_reader', $kind);
        $zip = new \ZipArchive;
        if ($zip->open($abs) !== true) return self::out('failed', $kind, error: 'ZIP');

        $parts = [];
        $total = 0;
        foreach ($names as $name) {
            $st = $zip->statName($name);
            if ($st === false) continue;
            $total += (int) $st['size'];
            if ((int) $st['size'] > self::MAX_ENTRY || $total > self::MAX_ENTRY * 2) {
                $zip->close();

                return self::out('too_large', $kind);
            }
            $xml = $zip->getFromName($name);
            if (! is_string($xml)) continue;
            // نصٌّ لا محلّلُ XML: الفقرةُ سطر، ثم تُنزع الوسوم وتُفكّ الكيانات الخمس المعروفة وحدَها
            $xml = (string) preg_replace($breakRe, "\n", $xml);
            $xml = (string) preg_replace('/<(w:tab|w:br|a:br)\b[^>]*\/>/', ' ', $xml);
            $parts[] = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $zip->close();

        $text = self::clean(implode("\n\n", $parts));

        return $text === '' ? self::out('empty', $kind) : self::out('ok', $kind, $text, $kind === 'pptx' ? count($names) : null);
    }

    /** @return list<string> مدخلاتُ الأرشيف المطابقة، بترتيبٍ طبيعيّ (slide2 قبل slide10) */
    private static function entries(string $abs, string $re): array
    {
        if (! class_exists(\ZipArchive::class)) return [];
        $zip = new \ZipArchive;
        if ($zip->open($abs) !== true) return [];
        $out = [];
        for ($i = 0; $i < $zip->numFiles && $i < 2000; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match($re, $n)) $out[] = $n;
        }
        $zip->close();
        natsort($out);

        return array_values($out);
    }

    private static function plain(string $abs): array
    {
        $s = (string) file_get_contents($abs, false, null, 0, self::MAX_BYTES);
        if (! mb_check_encoding($s, 'UTF-8')) {
            $conv = function_exists('iconv') ? @iconv('WINDOWS-1256', 'UTF-8//IGNORE', $s) : false;
            $s = is_string($conv) ? $conv : mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $text = self::clean($s);

        return $text === '' ? self::out('empty', 'text') : self::out('ok', 'text', $text);
    }

    private static function out(string $status, ?string $kind, string $text = '', ?int $pages = null, ?string $error = null): array
    {
        return ['status' => $status, 'kind' => $kind, 'text' => $text, 'pages' => $pages, 'error' => $error];
    }
}
