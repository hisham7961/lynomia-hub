<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * عميل أودو للعرض فقط — JSON-RPC بلا أي اعتماديات.
 * البيانات من الإعدادات: odoo.url · odoo.db · odoo.user · odoo.key
 * كل الاستدعاءات قراءة (search_read) — لا كتابة محاسبية إطلاقاً.
 */
class Odoo
{
    /** هل اكتملت بيانات الربط في الإعدادات؟ */
    public static function configured(): bool
    {
        return setting('odoo.url') && setting('odoo.db') && setting('odoo.user') && setting('odoo.key');
    }

    /** نداء JSON-RPC خام */
    protected static function rpc(string $service, string $method, array $args)
    {
        $url = rtrim((string) setting('odoo.url'), '/') . '/jsonrpc';
        $resp = Http::timeout(12)->post($url, [
            'jsonrpc' => '2.0', 'method' => 'call', 'id' => rand(1, 99999),
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('تعذر الوصول لخادم أودو (' . $resp->status() . ') — تحقق من الرابط');
        }
        $j = $resp->json();
        if (isset($j['error'])) {
            throw new \RuntimeException('أودو رفض الطلب: ' . ($j['error']['data']['message'] ?? $j['error']['message'] ?? 'خطأ غير معروف'));
        }

        return $j['result'] ?? null;
    }

    /** هوية المستخدم — مخبأة ١٠ دقائق */
    public static function uid(): int
    {
        return Cache::remember('odoo:uid', 600, function () {
            $uid = self::rpc('common', 'authenticate',
                [setting('odoo.db'), setting('odoo.user'), setting('odoo.key'), []]);
            if (! $uid) throw new \RuntimeException('فشل الدخول لأودو — تحقق من اسم القاعدة والمستخدم ومفتاح الـ API');

            return (int) $uid;
        });
    }

    /** إصدار الخادم — لاختبار الاتصال */
    public static function version(): string
    {
        $v = self::rpc('common', 'version', []);

        return (string) ($v['server_version'] ?? '؟');
    }

    /** تنفيذ قراءة على موديل */
    public static function exec(string $model, string $method, array $args = [], array $kw = [])
    {
        return self::rpc('object', 'execute_kw',
            [setting('odoo.db'), self::uid(), setting('odoo.key'), $model, $method, $args, $kw]);
    }

    /** بحث شركاء بالاسم — للربط الذكي */
    public static function searchPartners(string $q): array
    {
        return (array) self::exec('res.partner', 'search_read',
            [[['name', 'ilike', $q]]],
            ['fields' => ['id', 'name', 'email'], 'limit' => 8]);
    }

    /** أرقام شريك (عميل/جهة) — مبيعات وفواتير ومتبقٍ ومشتريات، مخبأة ١٠ دقائق */
    public static function partnerStats(int $pid, bool $fresh = false): array
    {
        $key = 'odoo:stats:' . $pid;
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, 600, function () use ($pid) {
            $inv = (array) self::exec('account.move', 'search_read',
                [[['partner_id', '=', $pid], ['move_type', '=', 'out_invoice'], ['state', '=', 'posted']]],
                ['fields' => ['amount_total', 'amount_residual'], 'limit' => 1000]);

            $bills = (array) self::exec('account.move', 'search_read',
                [[['partner_id', '=', $pid], ['move_type', '=', 'in_invoice'], ['state', '=', 'posted']]],
                ['fields' => ['amount_total'], 'limit' => 1000]);

            try {
                $orders = (array) self::exec('sale.order', 'search_read',
                    [[['partner_id', '=', $pid], ['state', 'in', ['sale', 'done']]]],
                    ['fields' => ['amount_total'], 'limit' => 1000]);
            } catch (\Throwable $e) {
                $orders = [];   // قاعدة بلا تطبيق مبيعات
            }

            return [
                'sales'     => array_sum(array_column($orders, 'amount_total')),
                'salesN'    => count($orders),
                'invoiced'  => array_sum(array_column($inv, 'amount_total')),
                'invoicedN' => count($inv),
                'residual'  => array_sum(array_column($inv, 'amount_residual')),
                'bills'     => array_sum(array_column($bills, 'amount_total')),
                'billsN'    => count($bills),
                'at'        => now()->format('H:i'),
            ];
        });
    }
}
