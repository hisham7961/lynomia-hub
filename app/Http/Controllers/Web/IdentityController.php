<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\IdentityLookup;
use App\Models\Product;
use App\Models\RecordIdentifier;
use App\Support\Barcode;
use App\Support\Custody;
use App\Support\Discovery\Engine;
use App\Support\Identity;
use App\Support\Qr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * **مركز الهوية والمسح — «امسح، والنظام يعرف».**
 *
 * تجربةٌ واحدة لأربع حالات، من شاشةٍ واحدة لا يغادرها المستخدم:
 *   ١) مسحُ كود عهدةٍ قائم       ← يفتح الأصل بأفعاله فوراً.
 *   ٢) مسحُ باركود منتجٍ معروف    ← «الباركود يعرّف الطراز لا القطعة» —
 *      سجّل قطعةً (أو قطعاً بسيريالاتها) وأسندها عهدةً في خطوةٍ واحدة.
 *   ٣) باركود عالميّ مجهول        ← استكشافٌ خارجيّ آليّ ← اقتراحٌ بثقةٍ
 *      ومصادرَ ← تأكيدٌ ← منتجٌ + قطعةٌ + عهدةٌ في معاملةٍ منسّقةٍ واحدة.
 *   ٤) لا يعرفه أحد               ← تسجيلٌ يدويّ سريع بنفس المعاملة.
 *
 * والحسمُ كلُّه عبر المحلّل الموحّد (`Identity::resolve`) — لا منطقَ بحثٍ
 * ثانٍ في أي شاشة.
 */
class IdentityController extends Controller
{
    /* ────────── مركز الهوية ────────── */

    public function center()
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v') || hub_can(auth()->user(), 'products', 'v'), 403,
            'مركز الهوية يتطلب صلاحية عرض الأصول أو سجل المنتجات');

        $u = auth()->user();
        $canP = hub_can($u, 'products', 'v');

        // الأرقام على نطاق القارئ — لا لوحة منشأةٍ لمعزول
        $pQ = fn () => hub_company_scope(hub_scope(Product::query(), 'products', $u), 'products');
        $aQ = fn () => hub_company_scope(hub_scope(Asset::query(), 'assets', $u), 'assets');

        return view('identity.center', [
            'nProducts' => $canP ? $pQ()->count() : null,
            'nVerified' => $canP ? $pQ()->where('status', 'موثّق')->count() : null,
            'nAssets' => hub_can($u, 'assets', 'v') ? $aQ()->count() : null,
            'nLinked' => hub_can($u, 'assets', 'v') ? $aQ()->whereNotNull('product_id')->count() : null,
            'nIds' => RecordIdentifier::count(),
            'lookups' => $canP
                ? IdentityLookup::orderByDesc('checked_at')->orderByDesc('id')->limit(8)->get()
                : collect(),
            'recent' => $canP
                ? $pQ()->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get()
                : collect(),
        ]);
    }

    /* ────────── الحسم: «ما هذا؟» ────────── */

    public function resolve(Request $r)
    {
        return response()->json($this->resolvePayload(trim((string) $r->query('q', ''))));
    }

    /** حمولةُ الحسم — يشترك فيها المسحُ المباشر والاستكشاف (داخليٌّ أولاً دائماً) */
    protected function resolvePayload(string $q): array
    {
        $hit = Identity::resolve($q);
        $u = auth()->user();

        $out = ['type' => $hit['type'], 'via' => $hit['via'] ?? null,
            'viaLabel' => Identity::KINDS[$hit['via'] ?? ''] ?? null, 'q' => $q];

        if ($hit['type'] === 'asset') {
            $a = $hit['row'];
            $out['asset'] = [
                'id' => $a->id, 'code' => $a->code, 'name' => $a->name,
                'serial' => hub_masked('assets', 'serial') ? null : $a->serial,
                'type' => $a->type, 'status' => $a->status,
                'holder' => $a->holder_id ? optional($a->holder)->name : null,
                'loc' => $a->loc,
                'product' => $a->product_id ? optional(Product::find($a->product_id))->name : null,
                'url' => route('m.show', ['assets', $a->id]),
                'label' => route('custody.label', $a->id),
                'canMove' => hub_can($u, 'assets', 'e'),
            ];
        } elseif ($hit['type'] === 'product') {
            $p = $hit['row'];
            $out['product'] = $this->productPayload($p);
        } elseif ($hit['type'] === 'stock') {
            $s = $hit['row'];
            $out['stock'] = ['id' => $s->id, 'name' => $s->name, 'sku' => $s->sku,
                'url' => route('m.show', ['stock', $s->id])];
        } else {
            $out['gtin'] = (bool) ($hit['gtin'] ?? false);
            $out['canDiscover'] = ($out['gtin'] ?? false) && hub_can($u, 'products', 'a');
            $out['canManual'] = hub_can($u, 'products', 'a') && hub_can($u, 'assets', 'a');
        }

        return $out;
    }

    /* ────────── الاستكشاف الخارجي ────────── */

    public function discover(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'products', 'a'), 403,
            'الاستكشاف الخارجي يتطلب صلاحية إضافة المنتجات');

        $d = $r->validate(['q' => 'required|string|max:100'], [], ['q' => 'الباركود']);

        // داخليّ أولاً دائماً — المزوّدون لا يُسألون عن معروف
        $inner = $this->resolvePayload($d['q']);
        if ($inner['type'] !== 'none') {
            return response()->json($inner);
        }

        $res = Engine::lookup($d['q']);
        $out = ['type' => 'discovery', 'status' => $res['status'], 'cached' => $res['cached'],
            'providers' => $res['providers'], 'suggestion' => $res['suggestion']];
        if ($res['suggestion']) {
            $out['dupes'] = Identity::dupes($res['suggestion']);
        }

        return response()->json($out);
    }

    /* ────────── المعاملة المنسّقة: منتج ← قطع ← عهدة ────────── */

    public function register(Request $r)
    {
        $u = auth()->user();
        abort_unless(hub_can($u, 'assets', 'a'), 403, 'تسجيل الأصول يتطلب صلاحية الإضافة على الأصول');

        $types = collect(hub_mod('products')['fields'])->firstWhere('key', 'type')['options'];
        $d = $r->validate([
            'product_id' => ['nullable', 'string', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'name' => 'required_without:product_id|nullable|string|max:300',
            'brand' => 'nullable|string|max:300',
            'manufacturer' => 'nullable|string|max:300',
            'model' => 'nullable|string|max:300',
            'type' => ['nullable', 'string', Rule::in($types)],
            'barcode' => 'nullable|string|max:300',
            'mpn' => 'nullable|string|max:300',
            'origin' => 'nullable|string|max:300',
            'descr' => 'nullable|string|max:4000',
            'qty' => 'nullable|integer|min:1|max:' . Identity::BULK_MAX,
            'serials' => 'nullable|array|max:' . Identity::BULK_MAX,
            'serials.*' => 'nullable|string|max:300',
            'holder_id' => ['nullable', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'company_id' => ['nullable', 'string', Rule::exists('companies', 'id')->whereNull('deleted_at')],
            // «تُدار لدينا» ≠ «ملكُنا»: قطعةُ العميل تُسجَّل بكل وظائف العهدة
            // وتخرج من ممتلكاتنا — والفارغ يعني «لينوميا» كما في كل النظام
            'owner' => ['nullable', 'string', Rule::in(['لينوميا', 'عميل — يُدار لدينا', 'مشترك'])],
            'client_id' => ['nullable', 'string', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'project_id_ctx' => ['nullable', 'string', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'loc' => 'nullable|string|max:300',
            'note' => 'nullable|string|max:500',
        ], [], ['name' => 'اسم المنتج', 'qty' => 'الكمية', 'holder_id' => 'المستلم', 'serials' => 'السيريالات']);

        $qty = max(1, (int) ($d['qty'] ?? 1));
        if (($d['holder_id'] ?? null) && ! hub_can($u, 'assets', 'e')) {
            abort(403, 'إسناد العهدة يتطلب صلاحية تعديل الأصول');
        }

        [$product, $assets, $warnings] = DB::transaction(function () use ($d, $qty, $u) {
            $warnings = [];

            // ── المنتج: قائمٌ يُستعمل، وجديدٌ يُنشأ — والباركود المحجوز يقود لصاحبه ──
            if ($d['product_id'] ?? null) {
                $product = hub_company_scope(hub_scope(Product::query(), 'products', $u), 'products')
                    ->findOrFail($d['product_id']);
            } else {
                abort_unless(hub_can($u, 'products', 'a'), 403, 'إنشاء منتجٍ يتطلب صلاحية الإضافة على سجل المنتجات');

                $norm = Identity::norm('gtin', (string) ($d['barcode'] ?? ''));
                $owner = $norm !== ''
                    ? RecordIdentifier::where('module', 'products')->where('norm', $norm)->first() : null;
                if ($owner && ($product = Product::find($owner->record_id))) {
                    // مستخدمان مسحا المجهولَ نفسَه معاً: الثاني يلحق بالأول لا ينافسه
                    $warnings[] = 'الباركود مسجَّلٌ لمنتجٍ قائم — استُعمل «' . $product->name . '» بدل إنشاء مكرر';
                } else {
                    // مصدرُ الاستكشاف يُقرأ من كاش الخادم لا من المتصفح — لا ثقةَ تُدّعى
                    $cache = $norm !== '' ? IdentityLookup::where('norm', $norm)->first() : null;
                    $product = new Product(array_filter([
                        'name' => $d['name'], 'brand' => $d['brand'] ?? null,
                        'manufacturer' => $d['manufacturer'] ?? null, 'model' => $d['model'] ?? null,
                        'type' => $d['type'] ?? null, 'barcode' => $d['barcode'] ?? null,
                        'mpn' => $d['mpn'] ?? null, 'origin' => $d['origin'] ?? null,
                        'descr' => $d['descr'] ?? null,
                        'company_id' => $d['company_id'] ?? null,
                    ], fn ($v) => $v !== null));
                    $product->status = ($cache && $cache->status === 'found') ? 'بحاجة مراجعة' : 'غير موثّق';
                    if ($cache && $cache->result) {
                        $product->meta = ['discovery' => [
                            'providers' => $cache->providers, 'confidence' => $cache->result['confidence'] ?? [],
                            'sources' => $cache->result['sources'] ?? [], 'score' => $cache->result['score'] ?? null,
                            'at' => now()->toIso8601String(),
                        ]];
                    }
                    try {
                        $product->save();
                    } catch (\Illuminate\Database\QueryException $e) {
                        // سباقُ اللحظة نفسِها على الفهرس الفريد: أعد القراءة — الفائز واحد
                        if ((string) $e->getCode() !== '23000' || $norm === '') throw $e;
                        $owner = RecordIdentifier::where('module', 'products')->where('norm', $norm)->firstOrFail();
                        $product = Product::findOrFail($owner->record_id);
                    }
                }
            }

            // ── القطع: أصلٌ لكل وحدة بكودها المولَّد وسيريالها ──
            $assets = [];
            for ($i = 0; $i < $qty; $i++) {
                $serial = trim((string) ($d['serials'][$i] ?? ''));
                $a = new Asset([
                    'name' => $product->name,
                    'type' => $product->type ?: 'أخرى',
                    'product_id' => $product->id,
                    'serial' => $serial !== '' ? $serial : null,
                    'company_id' => $d['company_id'] ?? $product->company_id,
                    'loc' => $d['loc'] ?? null,
                    'status' => 'متاح',
                    'vendor' => $product->supplier_id ? optional($product->supplier)->name : null,
                    'owner_scope' => $d['owner'] ?? null,
                    'client_id' => $d['client_id'] ?? null,
                ]);
                $a->save();

                // ── العهدة: الإسنادُ جزءُ المعاملة — قطعةٌ بلا عهدةٍ فاشلة لا تُترك ──
                if ($d['holder_id'] ?? null) {
                    Custody::move($a, 'تسليم', $d['holder_id'], now()->toDateString(), $d['note'] ?? null,
                        ['project_id' => $d['project_id_ctx'] ?? null, 'client_id' => $d['client_id'] ?? null]);
                }
                $assets[] = $a;
            }

            return [$product, $assets, $warnings];
        });

        hub_audit('تسجيل بالمسح', 'assets', $assets[0]->id ?? null, (string) $product->name, [
            'after' => ['product' => $product->code, 'qty' => count($assets),
                'codes' => collect($assets)->pluck('code')->implode(' '),
                'holder' => $d['holder_id'] ?? null],
        ]);

        $codes = collect($assets)->pluck('code')->implode(' · ');
        $msg = 'سُجّل ' . count($assets) . ' أصلاً من «' . $product->name . '» (' . $codes . ')'
            . (($d['holder_id'] ?? null) ? ' وأُسندت العهدة' : '')
            . ($warnings ? ' — ' . implode(' · ', $warnings) : '');

        if ($r->wantsJson()) {
            return response()->json([
                'ok' => true, 'msg' => $msg,
                'product' => ['id' => $product->id, 'code' => $product->code, 'name' => $product->name],
                'assets' => collect($assets)->map(fn ($a) => ['id' => $a->id, 'code' => $a->code,
                    'url' => route('m.show', ['assets', $a->id]), 'label' => route('custody.label', $a->id)])->all(),
                'labels' => route('identity.labels', ['ids' => collect($assets)->pluck('id')->implode(',')]),
            ]);
        }

        return redirect()->route('m.show', ['assets', $assets[0]->id])->with('ok', $msg);
    }

    /* ────────── دمج المكرر ────────── */

    public function merge(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'products', 'd'), 403,
            'دمجُ المنتجات يتطلب صلاحية الحذف على سجل المنتجات — فهو إخفاءُ سجلٍّ قائم');

        $d = $r->validate([
            'into' => ['required', 'string', 'different:id', Rule::exists('products', 'id')->whereNull('deleted_at')],
        ], [], ['into' => 'المنتج الأصل']);

        $dupe = Product::findOrFail($id);
        $into = Product::findOrFail($d['into']);
        abort_if($dupe->id === $into->id, 422, 'لا يُدمج المنتج في نفسه');

        $res = Identity::merge($dupe, $into);

        return redirect()->route('m.show', ['products', $into->id])
            ->with('ok', 'دُمج «' . $dupe->name . '» في «' . $into->name . '»: '
                . $res['assets'] . ' أصلاً أُعيدت إشارتُه، وكودُ المكرر صار اسماً بديلاً يُمسح فيفتح الأصل');
    }

    /* ────────── الملصقات ────────── */

    /** ملصق المنتج ٤٠×٣٠مم: كود LYN-PRD بخطَّي Code128 وQR */
    public function productLabel(Request $r, string $id)
    {
        abort_unless(hub_can(auth()->user(), 'products', 'v'), 403, 'لا تملك عرض سجل المنتجات');
        $p = hub_company_scope(hub_scope(Product::query(), 'products', auth()->user()), 'products')
            ->findOrFail($id);

        $copies = max(1, min(60, (int) $r->input('copies', 1)));
        hub_audit('طباعة ملصق منتج', 'products', $p->id, (string) $p->name, ['after' => ['copies' => $copies]]);

        return view('identity.label', [
            'p' => $p, 'copies' => $copies,
            'qr' => Qr::svg(route('products.code', $p->code), 220),
            'bar' => Barcode::svg((string) $p->code, 40, false),
            'org' => setting('app.company', setting('app.name', 'Lynomia')),
        ]);
    }

    /** طباعةٌ دفعية: ورقة A4 من ملصقات أصولٍ عدة — بعد تسجيل دفعةٍ مثلاً */
    public function labels(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'assets', 'v'), 403, 'لا تملك عرض الأصول والعهد');

        $ids = array_slice(array_filter(explode(',', (string) $r->query('ids', ''))), 0, Identity::BULK_MAX);
        $assets = Custody::scoped()->whereIn('id', $ids)->orderBy('code')->get();
        abort_if($assets->isEmpty(), 404);

        hub_audit('طباعة ملصقات دفعية', 'assets', null, $assets->count() . ' ملصقاً',
            ['after' => ['codes' => $assets->pluck('code')->implode(' ')]]);

        return view('identity.labels', [
            'assets' => $assets,
            'org' => setting('app.company', setting('app.name', 'Lynomia')),
            'qrs' => $assets->mapWithKeys(fn ($a) => [$a->id => Qr::svg(route('custody.code', $a->code), 200)]),
            'bars' => $assets->mapWithKeys(fn ($a) => [$a->id => Barcode::svg((string) $a->code, 34, false)]),
        ]);
    }

    /** مسحُ باركود منتج (p/{code}): يفتح سجلَّ المنتج — نظير c/{code} للعهدة */
    public function byCode(string $code)
    {
        abort_unless(hub_can(auth()->user(), 'products', 'v'), 403, 'لا تملك عرض سجل المنتجات');
        $p = hub_company_scope(hub_scope(Product::query(), 'products', auth()->user()), 'products')
            ->where('code', mb_strtoupper($code))->firstOrFail();

        return redirect()->route('m.show', ['products', $p->id]);
    }

    /* ────────── مساعدات ────────── */

    protected function productPayload(Product $p): array
    {
        $u = auth()->user();

        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name,
            'brand' => $p->brand, 'model' => $p->model, 'type' => $p->type,
            'barcode' => $p->barcode, 'status' => $p->status,
            'assets' => Asset::where('product_id', $p->id)->count(),
            'url' => route('m.show', ['products', $p->id]),
            'canRegister' => hub_can($u, 'assets', 'a'),
            'canAssign' => hub_can($u, 'assets', 'e'),
        ];
    }
}
