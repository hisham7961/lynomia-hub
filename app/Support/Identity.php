<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\Product;
use App\Models\RecordIdentifier;
use App\Models\StockItem;
use Illuminate\Support\Str;

/**
 * **المحلّل الموحّد للهوية — بابٌ واحدٌ لكل سؤال «ما هذا؟».**
 *
 * كان لكل شاشةٍ منطقُ بحثها: العهدةُ تبحث في `assets.code`، والمخزون في
 * `stock_items.barcode`، ولا أحد يفهم GTIN. فصار السؤال كلُّه يمرّ من هنا:
 * `resolve('أي شيء')` تجيب — كودُ عهدة؟ كودُ منتج؟ باركود عالمي؟ سيريال؟
 * — بترتيب حسمٍ ثابت، وبنطاق القارئ نفسِه (`hub_scope`) فلا يكشف المسحُ
 * ما لا تكشفه الشاشة.
 *
 * ترتيبُ الحسم (الأدقُّ هويةً أولاً):
 *   1) كودُ عهدةٍ (`assets.code`)         ← القطعة بعينها
 *   2) كودُ منتجٍ (`products.code`)        ← الطراز
 *   3) سجلُّ المعرفات (سيريال/GTIN/بديل…) ← صاحبُ المعرّف أيّاً كانت وحدتُه
 *   4) أعمدةُ الميراث (serial/tag/barcode/sku) لغير المُسجَّل بعد
 */
class Identity
{
    /** أنواع المعرفات وأسماؤها — نوعٌ جديد غداً سطرٌ هنا لا هجرة */
    public const KINDS = [
        'lyn' => 'كود Lynomia', 'gtin' => 'GTIN', 'ean' => 'EAN', 'upc' => 'UPC',
        'barcode' => 'باركود', 'serial' => 'الرقم التسلسلي', 'mpn' => 'رقم قطعة المصنع',
        'sku' => 'SKU', 'tag' => 'وسم أصل', 'alias' => 'كود سابق (دمج)',
    ];

    /** أقصى قطع التسجيل الدفعي في طلبٍ واحد */
    public const BULK_MAX = 50;

    /* ────────── التطبيع ────────── */

    /**
     * الصورة القياسية للبحث والتفرّد: الأرقامُ وحدها للأنواع الرقمية العالمية،
     * وحروفٌ كبيرة بلا فراغاتٍ للباقي. `value` تبقى كما أُدخلت للعرض.
     */
    public static function norm(string $kind, string $value): string
    {
        $v = trim($value);
        if ($v === '') return '';
        if (in_array($kind, ['gtin', 'ean', 'upc'], true)) {
            return preg_replace('/\D+/', '', $v) ?: '';
        }

        return mb_strtoupper(preg_replace('/\s+/u', '', $v));
    }

    /** أيصلح نصٌّ باركوداً عالمياً؟ 8/12/13/14 خانة **وخانةُ التحقق سليمة** */
    public static function looksGtin(string $q): bool
    {
        $d = preg_replace('/\D+/', '', trim($q));
        if (! in_array(strlen($d), [8, 12, 13, 14], true)) return false;

        // خوارزمية GS1: الأوزان 3/1 بالتناوب من اليمين قبل خانة التحقق
        $sum = 0;
        $digits = str_split($d);
        $check = (int) array_pop($digits);
        foreach (array_reverse($digits) as $i => $n) {
            $sum += (int) $n * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }

    /* ────────── سجل المعرفات ────────── */

    /**
     * تسجيلُ معرّفٍ على سجل — **متسامحٌ مع التكرار عمداً**: النداءُ الثاني بنفس
     * القيمة لا يفعل شيئاً، والقيمةُ المحجوزة لسجلٍ آخر تُترك له (الفهرسُ الفريد
     * هو الحكم لا فحصٌ يسبق الكتابة، فالتزامن لا يخترقه).
     */
    public static function attach(string $module, string $recordId, string $kind, string $value,
                                  array $opts = []): ?RecordIdentifier
    {
        // شيفرةٌ منشورةٌ قبل تشغيل الهجرة لا تُسقط حفظَ أصلٍ — السجلُ يلحق بعدها
        static $ready = null;
        $ready ??= \Illuminate\Support\Facades\Schema::hasTable('record_identifiers');
        if (! $ready) return null;

        $norm = self::norm($kind, $value);
        if ($norm === '' || mb_strlen($norm) > 190) return null;

        try {
            return RecordIdentifier::create([
                'module' => $module, 'record_id' => $recordId,
                'kind' => $kind, 'value' => mb_substr(trim($value), 0, 300), 'norm' => $norm,
                'issuer' => $opts['issuer'] ?? null,
                'source' => $opts['source'] ?? null,
                'is_primary' => (bool) ($opts['is_primary'] ?? false),
                'verified' => (bool) ($opts['verified'] ?? false),
                'meta' => $opts['meta'] ?? null,
                'created_by' => auth()->id(),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') return null;   // مُسجَّلٌ من قبل — ليس خطأ
            throw $e;
        }
    }

    /** معرفات سجلٍ مرتّبةً: الأساسيُّ أولاً ثم الأقدم — ترتيبٌ دلاليّ لا قرعة */
    public static function of(string $module, string $recordId)
    {
        return RecordIdentifier::where('module', $module)->where('record_id', $recordId)
            ->orderByDesc('is_primary')->orderBy('created_at')->orderBy('id')->get();
    }

    /* ────────── الحسم ────────── */

    /**
     * «ما هذا؟» — تُعيد ['type' => asset|product|stock|none, 'row' => ?, 'via' => نوع المعرّف].
     * كلُّ قراءةٍ بنطاق القارئ: ما لا يراه في شاشته لا يراه بالمسح.
     */
    public static function resolve(string $q, $user = null): array
    {
        $user = $user ?? auth()->user();
        $q = trim($q);
        if ($q === '' || mb_strlen($q) > 300) return ['type' => 'none', 'q' => $q];

        $up = mb_strtoupper($q);

        // ١) كودُ عهدة — القطعةُ بعينها
        if (hub_can($user, 'assets', 'v')) {
            $a = self::scopedAssets($user)->where('code', $up)->first();
            if ($a) return ['type' => 'asset', 'row' => $a, 'via' => 'lyn'];
        }

        // ٢) كودُ منتج — الطراز. والمدموجُ **إحالةٌ لا جثة**: مسحُ ملصقٍ قديمٍ
        // بعد الدمج يفتح الأصلَ الذي ذهبت إليه القطعُ والمعرفات.
        if (hub_can($user, 'products', 'v')) {
            $p = self::scopedProducts($user)->where('code', $up)->first();
            if ($p) return ['type' => 'product', 'row' => self::followMerge($p, $user), 'via' => 'lyn'];
        }

        // ٣) سجلُّ المعرفات: أدقُّ مطابقةٍ بأيّ تطبيعٍ محتمل
        $norms = array_values(array_unique(array_filter([
            self::norm('serial', $q), self::norm('gtin', $q),
        ])));
        $ids = RecordIdentifier::whereIn('norm', $norms)
            ->orderBy('created_at')->orderBy('id')->get();
        foreach (['assets', 'products', 'stock'] as $module) {          // الأصلُ قبل الطراز قبل الصنف
            foreach ($ids->where('module', $module) as $hit) {
                $found = self::openScoped($module, $hit->record_id, $user);
                if ($found) return $found + ['via' => $hit->kind];
            }
        }

        // ٤) أعمدةُ الميراث لغير المسجَّل بعد (بياناتٌ سبقت سجل الهوية)
        if (hub_can($user, 'assets', 'v')) {
            $a = self::scopedAssets($user)
                ->where(fn ($w) => $w->where('serial', $q)->orWhere('tag', $q))->orderBy('id')->first();
            if ($a) return ['type' => 'asset', 'row' => $a, 'via' => 'serial'];
        }
        if (hub_can($user, 'stock', 'v') && class_exists(StockItem::class)) {
            $s = hub_company_scope(hub_scope(StockItem::query(), 'stock', $user), 'stock')
                ->where(fn ($w) => $w->where('barcode', $q)->orWhere('sku', $q))->orderBy('id')->first();
            if ($s) return ['type' => 'stock', 'row' => $s, 'via' => 'barcode'];
        }

        return ['type' => 'none', 'q' => $q, 'gtin' => self::looksGtin($q)];
    }

    /** فتحُ سجلٍّ من وحدةٍ بنطاق القارئ — أو null إن كان خارج نطاقه/صلاحيته */
    protected static function openScoped(string $module, string $recordId, $user): ?array
    {
        return match ($module) {
            'assets' => hub_can($user, 'assets', 'v')
                ? (($r = self::scopedAssets($user)->find($recordId)) ? ['type' => 'asset', 'row' => $r] : null) : null,
            'products' => hub_can($user, 'products', 'v')
                ? (($r = self::scopedProducts($user)->find($recordId))
                    ? ['type' => 'product', 'row' => self::followMerge($r, $user)] : null) : null,
            'stock' => hub_can($user, 'stock', 'v')
                ? (($r = hub_company_scope(hub_scope(StockItem::query(), 'stock', $user), 'stock')->find($recordId))
                    ? ['type' => 'stock', 'row' => $r] : null) : null,
            default => null,
        };
    }

    /** اتباعُ سلسلة الدمج حتى الأصل الحيّ — بعمقٍ محدودٍ فلا تدور حلقة */
    protected static function followMerge(Product $p, $user): Product
    {
        for ($i = 0; $i < 5; $i++) {
            $next = data_get($p->meta, 'merged_into');
            if (! $next) return $p;
            $target = self::scopedProducts($user)->find($next);
            if (! $target) return $p;                       // الهدفُ خارج نطاق القارئ: يقف عند المتاح
            $p = $target;
        }

        return $p;
    }

    protected static function scopedAssets($user)
    {
        return hub_company_scope(hub_scope(Asset::query(), 'assets', $user), 'assets');
    }

    protected static function scopedProducts($user)
    {
        return hub_company_scope(hub_scope(Product::query(), 'products', $user), 'products');
    }

    /* ────────── منع التكرار ────────── */

    /**
     * مرشّحو التكرار قبل إنشاء منتج: باركود مطابق، أو (علامة + طراز) متطابقان،
     * أو اسمٌ شديد الشبه — يُعرضون على المستخدم ولا يُقرَّر عنه.
     */
    public static function dupes(array $data, $user = null): array
    {
        $out = collect();

        $norm = self::norm('gtin', (string) ($data['barcode'] ?? ''));
        if ($norm !== '') {
            $hit = RecordIdentifier::where('module', 'products')->where('norm', $norm)->first();
            if ($hit) $out->push(['why' => 'الباركود نفسه', 'id' => $hit->record_id]);
        }

        $brand = trim((string) ($data['brand'] ?? ''));
        $model = trim((string) ($data['model'] ?? ''));
        if ($brand !== '' && $model !== '') {
            self::scopedProducts($user)->where('brand', $brand)->where('model', $model)
                ->orderBy('id')->limit(3)->get()
                ->each(fn ($p) => $out->push(['why' => 'نفس العلامة والطراز', 'id' => $p->id]));
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            self::scopedProducts($user)->where('name', $name)->orderBy('id')->limit(3)->get()
                ->each(fn ($p) => $out->push(['why' => 'الاسم نفسه', 'id' => $p->id]));
        }

        $ids = $out->pluck('id')->unique()->values();
        $rows = Product::whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $rows->get($id))->filter()
            ->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name,
                'brand' => $p->brand, 'model' => $p->model,
                'why' => $out->firstWhere('id', $p->id)['why']])
            ->values()->all();
    }

    /* ────────── الدمج ────────── */

    /**
     * دمجُ منتجٍ مكرر في الأصل: القطعُ تُعاد إشارتُها، والمعرفاتُ تنتقل،
     * وكودُ المكرر يصير **اسماً بديلاً** على الأصل — مسحُ ملصقٍ قديمٍ يفتح
     * الصحيح، لا «لا نتائج». المكرر يبقى صفاً مؤرشفاً يقول أين ذهب.
     */
    public static function merge(Product $dupe, Product $into): array
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($dupe, $into) {
            $moved = Asset::where('product_id', $dupe->id)->update(['product_id' => $into->id]);

            $ids = RecordIdentifier::where('module', 'products')->where('record_id', $dupe->id)->get();
            foreach ($ids as $rid) {
                if ($rid->kind === 'lyn') continue;                    // كودُ المكرر يُعالَج بديلاً أدناه
                RecordIdentifier::where('id', $rid->id)->update(['record_id' => $into->id]);
            }
            if ($dupe->code) {
                RecordIdentifier::where('module', 'products')->where('record_id', $dupe->id)
                    ->where('kind', 'lyn')->delete();
                self::attach('products', $into->id, 'alias', $dupe->code,
                    ['source' => 'دمج', 'verified' => true, 'meta' => ['merged_from' => $dupe->id]]);
            }

            $dupe->forceFill([
                'status' => 'مؤرشف بدمج', 'archived' => true,
                'meta' => array_merge((array) $dupe->meta, ['merged_into' => $into->id, 'merged_at' => now()->toIso8601String()]),
            ])->save();

            hub_audit('دمج منتجات', 'products', $into->id, $into->name,
                ['before' => ['dupe' => $dupe->code], 'after' => ['into' => $into->code, 'assets_moved' => $moved]]);

            return ['assets' => $moved, 'identifiers' => $ids->count()];
        });
    }
}
