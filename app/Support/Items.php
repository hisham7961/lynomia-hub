<?php

namespace App\Support;

/** محلل بنود المستندات المشترك (عروض الأسعار وأوامر الشراء): «وصف | كمية | سعر» لكل سطر */
class Items
{
    public static function parse(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($raw)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $cols = array_map('trim', explode('|', $line));
            $qty = isset($cols[1]) && is_numeric($cols[1]) ? (float) $cols[1] : null;
            $prc = isset($cols[2]) && is_numeric($cols[2]) ? (float) $cols[2] : null;
            $rows[] = ['desc' => $cols[0], 'qty' => $qty, 'price' => $prc,
                       'sum' => ($qty !== null && $prc !== null) ? $qty * $prc : null];
        }

        return $rows;
    }
}
