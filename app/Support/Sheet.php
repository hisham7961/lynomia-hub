<?php

namespace App\Support;

/** قارئ جداول بلا مكتبات: CSV (بفاصلة أو فاصلة منقوطة، مع BOM) و Excel xlsx (zip + XML) */
class Sheet
{
    /** يقرأ الملف حسب امتداده ويعيد مصفوفة صفوف (أول صف = العناوين) */
    public static function read(string $path, string $ext): array
    {
        return strtolower($ext) === 'xlsx' ? self::xlsx($path) : self::csv($path);
    }

    protected static function csv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3);   // BOM

        // الفاصل: الفاصلة المنقوطة إن كانت أكثر في أول سطر (تصدير Excel العربي)
        $first = strtok($raw, "\n") ?: '';
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        // معامل escape الفارغ صراحةً: سلوك RFC 4180، ويُسكت تحذير PHP 8.4 لكل سطر
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            if (count($r) === 1 && trim((string) $r[0]) === '') continue;
            $rows[] = array_map(fn ($v) => trim((string) $v), $r);
        }
        fclose($fh);

        return $rows;
    }

    /** xlsx = أرشيف zip: sharedStrings + sheet1 — يكفي للجداول البسيطة المصدّرة من Excel */
    protected static function xlsx(string $path): array
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) return [];

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $ss = @simplexml_load_string($xml);
            if ($ss) foreach ($ss->si as $si) {
                // نص مباشر أو مقاطع منسقة (r)
                $shared[] = isset($si->t) ? (string) $si->t
                    : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r ?? new \ArrayIterator([]), false)));
            }
        }

        // الورقة الأولى من workbook.xml لا sheet1.xml الحرفي: مصنفٌ حُذفت ورقته
        // الأولى يوماً يخزن ورقته الوحيدة باسم sheet2.xml — فكان يُقرأ فارغاً
        // وتظهر رسالة «الملف فارغ» المضللة
        $sheetXml = false;
        if (($wb = $zip->getFromName('xl/workbook.xml')) !== false
            && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            $wbX = @simplexml_load_string($wb);
            $relX = @simplexml_load_string($rels);
            if ($wbX && $relX && isset($wbX->sheets->sheet[0])) {
                $rid = (string) $wbX->sheets->sheet[0]->attributes('r', true)->id;
                foreach ($relX->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rid) {
                        $sheetXml = $zip->getFromName('xl/' . ltrim((string) $rel['Target'], '/'));
                        break;
                    }
                }
            }
        }
        if ($sheetXml === false) $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false || ! ($sheet = @simplexml_load_string($sheetXml))) return [];

        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $out = [];
            foreach ($row->c as $c) {
                // العمود من مرجع الخلية (A1 → 0)
                $ref = (string) $c['r'];
                $col = 0;
                foreach (str_split(preg_replace('/\d/', '', $ref)) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col = max(0, $col - 1);

                $v = isset($c->v) ? (string) $c->v : (string) ($c->is->t ?? '');
                if ((string) $c['t'] === 's') $v = $shared[(int) $v] ?? '';
                $out[$col] = trim($v);
            }
            if ($out === []) continue;
            // ملء الفجوات حتى أقصى عمود
            $max = max(array_keys($out));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = $out[$i] ?? '';
            $rows[] = $line;
        }

        return $rows;
    }
}
