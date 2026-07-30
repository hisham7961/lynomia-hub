<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * QuoteFlow — تطبيق جانبي معزول (عروض/فواتير/مخزون مستوردات) للمالك وحده.
 *
 * جزيرة كاملة: لا يقرأ من وحدات النظام ولا يكتب فيها. صفحته ملف HTML واحد
 * يُقدَّم كما هو، وحالته كلها (١٤ مفتاح localStorage في أصله) تُحفظ صفاً واحداً
 * في `sideapp_stores` — فالخادم مصدر الحقيقة والمتصفح مجرد خبيئة تشغيل:
 *  - عند الفتح: تُبذر الحالة المحفوظة في localStorage قبل إقلاع سكربت التطبيق.
 *  - عند كل `saveAll()` في التطبيق: يدفعها غلافٌ محقونٌ للخادم (مؤجَّلاً).
 *  - تصفير التطبيق يمسح نسخة الخادم أيضاً (دفع حالة فارغة).
 * أول فتحٍ وقاعدةٌ خاوية: تُحمَّل النسخة الاحتياطية المرفقة مع المشروع تلقائياً.
 */
class QuoteFlowController extends Controller
{
    protected const APP = 'quoteflow';
    protected const MAX_BYTES = 12_000_000;   // سقف الحالة: ١٢MB يسع سنوات من العروض

    /** مفاتيح التطبيق كما في ثابت KEYS داخل ملفه — الحارس الوحيد على ما يُقبل حفظه */
    protected const KEYS = [
        'qfu_products_v3', 'qfu_customers_v3', 'qfu_quotes_v3', 'qfu_companies_v3',
        'qfu_active_company_v3', 'qfu_special_prices_v3', 'qfu_price_lists_v3',
        'qfu_templates_v3', 'qfu_documents_v3', 'qfu_exchange_rates_v3', 'qfu_invoices_v3',
        'qfu_suppliers_v3', 'qfu_purchase_orders_v3', 'qfu_backup_meta_v3',
    ];

    protected function gate(): void
    {
        abort_unless(hub_is_owner(), 403, 'QuoteFlow لمالك النظام فقط');
    }

    public function page()
    {
        $this->gate();

        $html = file_get_contents(resource_path('sideapps/quoteflow.html'));
        $map  = $this->stored() ?? $this->seedFromBundledBackup();

        // البذر قبل أول سكربت في الصفحة — فيقرأ التطبيق حالة الخادم لا حالة المتصفح
        $seed = '<script>(function(){var d=' . json_encode($map ?: null, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE)
              . ';if(!d)return;try{Object.keys(d).forEach(function(k){localStorage.setItem(k,d[k])})}catch(e){}})();</script>' . "\n";
        $pos  = strpos($html, '<script');
        $html = $pos === false ? $seed . $html : substr_replace($html, $seed, $pos, 0);

        // غلاف المزامنة قبل إغلاق الوسم — يلتف على saveAll ويدفع للخادم مؤجَّلاً
        $shim = view('sideapps.quoteflow-sync', [
            'keys' => self::KEYS, 'url' => route('quoteflow.save'), 'token' => csrf_token(),
        ])->render();
        $html = str_replace('</body>', $shim . '</body>', $html);

        hub_audit('فتح QuoteFlow', null, null, 'تطبيق جانبي');

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store');   // الحالة مبذورة في الصفحة — لا تُخبأ
    }

    public function save(Request $r)
    {
        $this->gate();

        $data = $r->input('data');
        if (! is_array($data)) return response()->json(['ok' => false, 'err' => 'بنية غير صالحة'], 422);

        // لا يُقبل إلا مفاتيح التطبيق نفسها وقيمٌ نصية — أي شيء آخر يُرفض بصمت الحارس
        $clean = [];
        foreach ($data as $k => $v) {
            if (! in_array($k, self::KEYS, true) || ! is_string($v)) continue;
            $clean[$k] = $v;
        }

        $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if (strlen($json) > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'err' => 'الحالة تجاوزت الحد الأقصى'], 413);
        }

        DB::table('sideapp_stores')->updateOrInsert(['app' => self::APP],
            ['data' => $json, 'updated_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** الحالة المحفوظة — null إن لم يُحفظ شيء بعد */
    protected function stored(): ?array
    {
        $row = DB::table('sideapp_stores')->where('app', self::APP)->value('data');
        if ($row === null) return null;
        $map = json_decode($row, true);

        return is_array($map) ? $map : null;
    }

    /** أول تشغيل: تحويل النسخة الاحتياطية المرفقة (صيغة التصدير) إلى خريطة مفاتيح التخزين */
    protected function seedFromBundledBackup(): ?array
    {
        $file = resource_path('sideapps/quoteflow-backup.json');
        if (! is_file($file)) return null;

        $d = json_decode((string) file_get_contents($file), true);
        if (! is_array($d)) return null;

        // اسم الحقل في التصدير → مفتاح التخزين (activeCompanyId وحده يختلف اسماً)
        $fieldToKey = [
            'products' => 'qfu_products_v3', 'customers' => 'qfu_customers_v3',
            'quotes' => 'qfu_quotes_v3', 'companies' => 'qfu_companies_v3',
            'activeCompanyId' => 'qfu_active_company_v3', 'specialPrices' => 'qfu_special_prices_v3',
            'priceLists' => 'qfu_price_lists_v3', 'templates' => 'qfu_templates_v3',
            'documents' => 'qfu_documents_v3', 'exchangeRates' => 'qfu_exchange_rates_v3',
            'invoices' => 'qfu_invoices_v3', 'suppliers' => 'qfu_suppliers_v3',
            'purchaseOrders' => 'qfu_purchase_orders_v3', 'backupMeta' => 'qfu_backup_meta_v3',
        ];

        $map = [];
        foreach ($fieldToKey as $field => $key) {
            if (! array_key_exists($field, $d)) continue;
            // القيم تُخزن كما يخزنها التطبيق: نص JSON لكل مفتاح (load() تفكّه بـJSON.parse)
            $map[$key] = json_encode($d[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (! $map) return null;

        DB::table('sideapp_stores')->updateOrInsert(['app' => self::APP],
            ['data' => json_encode($map, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);

        return $map;
    }
}
