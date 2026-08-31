<?php

namespace App\Support\Discovery;

/** قاعدة UPCitemdb المفتوحة (بوابة التجربة — بلا مفتاح، بمعدلٍ محدود) */
class UpcItemDb implements Provider
{
    public function key(): string
    {
        return 'upcitemdb';
    }

    public function label(): string
    {
        return 'UPCitemdb';
    }

    public function handles(string $gtin): bool
    {
        return true;
    }

    public function url(string $gtin): string
    {
        return 'https://api.upcitemdb.com/prod/trial/lookup?upc=' . rawurlencode($gtin);
    }

    public function parse(array $json): ?array
    {
        $item = $json['items'][0] ?? null;
        if (! is_array($item) || trim((string) ($item['title'] ?? '')) === '') return null;

        return array_filter([
            'name' => trim((string) ($item['title'] ?? '')),
            'brand' => trim((string) ($item['brand'] ?? '')),
            'model' => trim((string) ($item['model'] ?? '')),
            'category' => trim((string) ($item['category'] ?? '')),
            'image' => trim((string) ($item['images'][0] ?? '')),
        ], fn ($v) => $v !== '');
    }
}
