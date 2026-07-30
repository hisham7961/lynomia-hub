<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;

/** PWA: بيان التثبيت وأيقونة وصفحة بلا اتصال — كلها تتبع هوية النظام من الإعدادات */
class PwaController extends Controller
{
    public function manifest()
    {
        $name = (string) setting('app.name', config('app.name', 'Lynomia Hub'));
        $color = (string) setting('app.color', '#6d28d9');

        return response()->json([
            'name' => $name, 'short_name' => mb_substr($name, 0, 12),
            'dir' => 'rtl', 'lang' => 'ar',
            'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
            'background_color' => '#ffffff', 'theme_color' => $color,
            'icons' => [
                ['src' => '/pwa-icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => '/pwa-icon.svg?maskable=1', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'maskable'],
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    public function icon()
    {
        $name = trim((string) setting('app.name', 'L'));
        $letter = mb_substr($name !== '' ? $name : 'L', 0, 1);
        $color = (string) setting('app.color', '#6d28d9');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
            . '<rect width="512" height="512" rx="96" fill="' . e($color) . '"/>'
            . '<text x="256" y="256" dy="0.36em" text-anchor="middle" '
            . 'font-family="Tajawal, Arial, sans-serif" font-weight="800" font-size="280" fill="#fff">'
            . e($letter) . '</text></svg>';

        return response($svg, 200)
            ->header('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function offline()
    {
        return view('offline');
    }
}
