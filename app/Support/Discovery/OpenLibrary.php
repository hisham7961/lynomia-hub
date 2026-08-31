<?php

namespace App\Support\Discovery;

/** مكتبة Open Library — الكتب: باركود ISBN (يبدأ بـ978/979) فقط */
class OpenLibrary implements Provider
{
    public function key(): string
    {
        return 'openlibrary';
    }

    public function label(): string
    {
        return 'Open Library';
    }

    public function handles(string $gtin): bool
    {
        return strlen($gtin) === 13 && (str_starts_with($gtin, '978') || str_starts_with($gtin, '979'));
    }

    public function url(string $gtin): string
    {
        return 'https://openlibrary.org/isbn/' . rawurlencode($gtin) . '.json';
    }

    public function parse(array $json): ?array
    {
        $title = trim((string) ($json['title'] ?? ''));
        if ($title === '') return null;

        return array_filter([
            'name' => $title,
            'brand' => trim((string) (($json['publishers'][0] ?? '') ?: '')),
            'category' => 'كتاب',
        ], fn ($v) => $v !== '');
    }
}
