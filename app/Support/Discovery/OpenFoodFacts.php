<?php

namespace App\Support\Discovery;

/** قاعدة Open Food Facts المفتوحة — الأغذية والمستهلكات (بلا مفتاح) */
class OpenFoodFacts implements Provider
{
    public function key(): string
    {
        return 'openfoodfacts';
    }

    public function label(): string
    {
        return 'Open Food Facts';
    }

    public function handles(string $gtin): bool
    {
        return true;
    }

    public function url(string $gtin): string
    {
        return 'https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($gtin) . '.json';
    }

    public function parse(array $json): ?array
    {
        $p = $json['product'] ?? null;
        if (! is_array($p) || (int) ($json['status'] ?? 0) !== 1) return null;
        $name = trim((string) ($p['product_name'] ?? ''));
        if ($name === '') return null;

        return array_filter([
            'name' => $name,
            'brand' => trim((string) ($p['brands'] ?? '')),
            'category' => trim((string) ($p['categories'] ?? '')),
            'origin' => trim((string) ($p['countries'] ?? '')),
            'image' => trim((string) ($p['image_url'] ?? '')),
        ], fn ($v) => $v !== '');
    }
}
