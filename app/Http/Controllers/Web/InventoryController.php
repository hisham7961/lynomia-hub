<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Support\Custody;
use App\Support\FlowRunner;
use App\Support\Identity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **جلساتُ الجرد — لقطةٌ مجمَّدةٌ تُقارَن بمسحٍ مُصادَق** (Work OS · الطور F · WP-F.3 · §32 · §99).
 *
 * ثلاثُ حركاتٍ فوق سكّتين قائمتين لا محرّكاتٍ جديدة:
 *   · **`freeze`** — لقطةٌ **ثابتةٌ** لمجموعةِ الأصولِ **بنطاق القارئ** (`Custody::scoped`،
 *     المصدرُ الوحيدُ للأصولِ المنطَّقة) في `inventory_items`؛ لا تتبدّل بتغيّرِ الأصلِ بعدها.
 *   · **`scan`** — يحلّ الرمزَ عبر **المحلِّل الموحّد** `Identity::resolve` (لا محلِّلَ ثانٍ،
 *     بنطاقِ الماسِح)، ويختم **الماسِحَ** (`by_id`) وزمنَه، ويصنّف: معروف/غير متوقع/غير معروف.
 *   · **`reconcile`** — يصنّف الفروق على كل صنفٍ مجمَّد: موجود/مفقود/انتقل، ويضيف الطارئَ
 *     (مُسِح خارجَ اللقطة) بحكمِ «غير متوقع».
 *
 * **الحرّاسُ (لا إخفاءَ رابط):** `hub_can('assets', v/e)` (الجردُ عمليّةٌ على الأصول)؛ وعزلُ
 * الشركة صريحٌ عبر `hub_company_ids` (الجلسةُ ليست وحدةَ `hub.modules` فيُطبَّق العزلُ هنا).
 * **داخليّةٌ فقط:** `PortalGuard` قائمةٌ بيضاءُ لا تضمّ `inventory.*` → حسابُ العميل ٤٠٤ فوق
 * المصفوفة. و**الإغلاقُ/المصالحةُ الكتابيّة** خلفَ `hub_require_stepup` — لا يُغلَق جردٌ ولا
 * تُثبَّت فروقُه بجلسةٍ مسروقةٍ وحدَها.
 */
class InventoryController extends Controller
{
    /**
     * بوّابةُ الصلاحية على وحدة الأصول (الجردُ عمليّةٌ عليها — لا وحدةَ جردٍ منفصلة).
     *
     * `$fine` (Permissions 360 · 12.4): مفتاحٌ دقيقٌ بديلٌ (`assetInventory`) يفتحُ
     * الجردَ لأمينِ مخزنٍ لا يملكُ رايةَ `e` الجامعة. إضافةٌ لا كسر: حاملُ `e` يمرّ كما كان.
     */
    protected function can(string $op, ?string $fine = null): void
    {
        $u = auth()->user();
        $ok = hub_can($u, 'assets', $op) || ($fine !== null && hub_can($u, 'assets', $fine));
        abort_unless($ok, 403, 'لا تملك صلاحيةَ الأصول');
    }

    /**
     * استعلامُ الجلسات محصورٌ بشركاتِ القارئ — نظيرُ فقرةِ الشركة في `hub_scope`
     * (الجلسةُ ليست وحدةَ `hub.modules` فيُطبَّق العزلُ صراحةً هنا كـ`EmployeeCustody::moves`).
     */
    protected function sessions()
    {
        $q = InventorySession::query();
        if (($cids = hub_company_ids()) !== null) {
            $q->whereIn('company_id', $cids);
        }

        return $q;
    }

    /** جلسةٌ بنطاق القارئ — شركةٌ أجنبيةٌ ٤٠٤ لا كشفَ وجود (IDOR) */
    protected function sessionScoped(?string $id): InventorySession
    {
        abort_unless(filled($id), 404);

        return $this->sessions()->findOrFail($id);
    }

    /**
     * الشركةُ النشطةُ للجلسة الجديدة: المختارةُ من الشريط العلويّ إن وُجدت، وإلا الشركةُ
     * الوحيدةُ المسموحة (المحصورُ بواحدة)، وإلا null (المالكُ/غيرُ المحصور — يرى الكلّ).
     */
    protected function activeCompanyId(): ?string
    {
        $active = (string) session('hub.company', '');
        if ($active !== '') return $active;
        $cids = hub_company_ids();

        return ($cids !== null && count($cids) === 1) ? $cids[0] : null;
    }

    /* ══════════ المركز والعرض ══════════ */

    /** قائمةُ جلساتِ الجرد — منعزلةٌ بالشركة، الأحدثُ أولاً بترتيبٍ حتميّ */
    public function center()
    {
        $this->can('v');

        $rows = $this->sessions()
            ->withCount(['items', 'scans'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return view('inventory.center', ['sessions' => $rows]);
    }

    /** جلسةٌ واحدة: لقطتُها وأحكامُها ومسحاتُها — كلُّها منطَّقة */
    public function show(string $id)
    {
        $this->can('v');
        $session = $this->sessionScoped($id);

        // (AUDIT-8) الأصنافُ المجمَّدة **صفحةً محدودةً على مستوى القاعدة** لا اللقطةَ كاملةً:
        // كانت `->get()` تحمّل كلَّ الأصناف في الذاكرة (تكبر بعددِ الأصول). الترتيبُ حتميّ (الحكم ثم id).
        $items = InventoryItem::where('session_id', $session->id)
            ->orderBy('verdict')->orderBy('id')->paginate(50)->withQueryString();

        // المسحاتُ الأحدثُ أولاً بترتيبٍ حتميّ (at ثم id)
        $scans = InventoryScan::where('session_id', $session->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(200)->get();

        // أسماءُ الماسِحين (by_id) — لا استعلامٌ لكلِّ صف
        $names = DB::table('users')
            ->whereIn('id', $scans->pluck('by_id')->filter()->unique())
            ->pluck('name', 'id');

        // (AUDIT-8) العدُّ بالحكم من **تجميعِ القاعدة** لا من صفوفِ الصفحة — يمثّل الجلسةَ
        // كاملةً مهما كانت الصفحةُ المعروضة، ولا يُحمّل صفٌّ لأجل العدّ.
        $counts = InventoryItem::where('session_id', $session->id)
            ->select('verdict', DB::raw('COUNT(*) c'))->groupBy('verdict')->pluck('c', 'verdict');
        $total = (int) $counts->sum();

        // خريطةُ أكواد اللقطة لصفوفِ المسح (المحدودةِ بـ200) — من عناصرِ تلك المسحات وحدَها
        // لا من كلِّ الأصناف: فلا يعود العرضُ يحمّل اللقطةَ كاملةً لبناء الخريطة. ومن اللقطة
        // المنطَّقة (session_id) فلا يُعرَض رمزٌ لأصلٍ خارجَها، ومسحُ «غير معروف» لا asset_id أصلاً.
        $scanAssetIds = $scans->pluck('asset_id')->filter()->unique()->values();
        $itemCodes = $scanAssetIds->isEmpty() ? collect()
            : InventoryItem::where('session_id', $session->id)->whereIn('asset_id', $scanAssetIds->all())
                ->get(['asset_id', 'snapshot'])
                ->mapWithKeys(fn ($it) => [$it->asset_id => (string) data_get($it->snapshot, 'code', '')]);

        return view('inventory.show', [
            'session'   => $session,
            'items'     => $items,
            'scans'     => $scans,
            'names'     => $names,
            'itemCodes' => $itemCodes,
            'counts'    => $counts,
            'total'     => $total,
        ]);
    }

    /* ══════════ التجميد ══════════ */

    /**
     * **التجميدُ — لقطةٌ ثابتة.** يفتح جلسةً ويصوّر مجموعةَ الأصولِ **بنطاق القارئ**
     * (`Custody::scoped`) في `inventory_items` بحكمٍ `معلّق`. اللقطةُ نقطةٌ زمنيّةٌ مجمَّدة:
     * تغييرُ الأصلِ بعدها لا يمسّها. `insertOrIgnore` على الفهرس الفريد فإعادةُ التجميدِ لا تخترقه.
     */
    public function freeze(Request $r)
    {
        $this->can('e', 'assetInventory');

        $session = new InventorySession();
        $session->company_id = $this->activeCompanyId();
        $session->status = InventorySession::OPEN;
        $session->by_id = auth()->id();
        $session->save();

        $now = now();
        $n = 0;
        // ترتيبٌ حتميٌّ للمرورِ على **كل** الأصول (لا قرعة)، ودُفعاتٌ لا صفٌّ لكلِّ أصل
        Custody::scoped()->orderBy('id')->chunk(500, function ($chunk) use ($session, $now, &$n) {
            $rows = [];
            foreach ($chunk as $a) {
                $rows[] = [
                    'id'         => (string) Str::uuid(),
                    'session_id' => $session->id,
                    'asset_id'   => $a->id,
                    'snapshot'   => json_encode($this->snapshotOf($a), JSON_UNESCAPED_UNICODE),
                    'verdict'    => InventoryItem::PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($rows) {
                InventoryItem::insertOrIgnore($rows);
                $n += count($rows);
            }
        });

        hub_audit('فتح جلسة جرد', 'assets', $session->id, 'جلسةٌ بـ' . $n . ' أصلاً مجمَّداً');

        return redirect()->route('inventory.show', $session->id)
            ->with('ok', 'فُتِحت جلسةُ جردٍ وجُمِّد ' . $n . ' أصلاً');
    }

    /**
     * اللقطةُ المجمَّدة لأصلٍ: هويّةٌ وموضعٌ لحظةَ التجميد — **لا حقولَ سرّ** (لا serial/IP):
     * الجردُ يقارن الموضعَ والهويّةَ لا الأسرار، فلا تُخزَّن قيمةٌ محجوبةٌ في JSON قد تُعرَض.
     */
    protected function snapshotOf(Asset $a): array
    {
        return [
            'code'       => (string) $a->code,
            'name'       => (string) $a->name,
            'type'       => (string) $a->type,
            'status'     => (string) $a->status,
            'station_id' => $a->station_id,
            'holder_id'  => $a->holder_id,
            'company_id' => $a->company_id,
            'frozen_at'  => now()->toIso8601String(),
        ];
    }

    /* ══════════ المسح ══════════ */

    /**
     * **مسحُ رمز.** يحلّ عبر `Identity::resolve` (المحلِّل الموحّد، بنطاقِ الماسِح فما لا
     * يراه في شاشته لا يكشفه مسحُه)، ويكتب مسحةً **مختومةً بالماسِح** (`by_id`) وزمنِها:
     *   · أصلٌ ضمن اللقطة → `معروف`.
     *   · أصلٌ في النطاقِ خارجَ اللقطة (أُنشئ بعد التجميد) → `غير متوقع`.
     *   · لم يُحَلّ (خارجَ الشركة/غيرُ موجود) → `غير معروف`، `asset_id` **null**، ولا يُخزَّن
     *     الرمزُ الخام — لا كشفَ وجودٍ ولا تسريبَ هويّةِ أصلٍ أجنبيّ.
     */
    public function scan(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        $session = $this->sessionScoped($id);
        abort_unless((string) $session->status === InventorySession::OPEN, 422, 'الجلسةُ مغلقةٌ — لا مسحَ بعد الإغلاق');

        $d = $r->validate(['code' => ['required', 'string', 'max:300']]);
        $code = trim($d['code']);

        // المحلِّلُ الموحّد — لا محلِّلَ ثانٍ، وبنطاقِ الماسِح
        $res = Identity::resolve($code, auth()->user());

        if (($res['type'] ?? 'none') === 'asset' && isset($res['row'])) {
            $assetId = $res['row']->id;
            $inLot = InventoryItem::where('session_id', $session->id)->where('asset_id', $assetId)->exists();
            $result = $inLot ? InventoryScan::KNOWN : InventoryScan::UNEXPECTED;
            // الرمزُ يُخزَّن للمحلولِ داخلَ النطاق فقط (أصلُ الماسِح نفسِه)
            $meta = ['code' => (string) ($res['row']->code ?? $code), 'via' => $res['via'] ?? null];
            $flash = $inLot ? 'رمزٌ معروفٌ ضمن اللقطة' : 'رمزٌ في النطاقِ خارجَ اللقطة (غير متوقع)';
        } else {
            $assetId = null;
            $result = InventoryScan::UNKNOWN;
            // لا يُخزَّن الرمزُ الخام لأصلٍ خارجَ النطاق — لا تسريب
            $meta = ['result' => 'unknown'];
            $flash = 'رمزٌ غير معروف — ليس ضمن نطاقك';
        }

        InventoryScan::create([
            'session_id' => $session->id,
            'asset_id'   => $assetId,
            'result'     => $result,
            'by_id'      => auth()->id(),   // **الختمُ الذي يطلبه §32**
            'at'         => now(),
            'meta'       => $meta,
        ]);

        return redirect()->route('inventory.show', $session->id)->with('ok', $flash);
    }

    /* ══════════ المصالحة والإغلاق (خلفَ التصعيد) ══════════ */

    /**
     * **المصالحةُ الكتابيّة (step-up).** تصنّف كلَّ صنفٍ مجمَّد: مُسِحَ في موضعِه → `موجود`؛
     * مُسِحَ لكن موضعَه الحاليَّ يخالف المجمَّد → `انتقل`؛ لم يُمسح → `مفقود`. والطارئُ (أصلٌ
     * مُسِح في النطاقِ خارجَ اللقطة) يُضاف صنفاً بحكمِ `غير متوقع`. خلفَ `hub_require_stepup`:
     * لا تُثبَّت فروقُ جردٍ بجلسةٍ مسروقةٍ وحدَها.
     */
    public function reconcile(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        if ($resp = hub_require_stepup()) return $resp;
        $session = $this->sessionScoped($id);

        $summary = DB::transaction(function () use ($session) {
            // أصولٌ مُسِحت (asset_id غيرُ null) — «هل مُسِح هذا الأصل؟» (فهرسُ asset_id)
            $scannedIds = InventoryScan::where('session_id', $session->id)
                ->whereNotNull('asset_id')->pluck('asset_id')->unique();

            // الموضعُ الحاليُّ للأصولِ المعنيّة، منطَّقٌ — لا يُقرأ خارجَ نطاق القارئ
            $items = InventoryItem::where('session_id', $session->id)->orderBy('id')->get();
            $current = Custody::scoped()
                ->whereIn('id', $items->pluck('asset_id')->merge($scannedIds)->unique()->values())
                ->get()->keyBy('id');

            $counts = [InventoryItem::PRESENT => 0, InventoryItem::MISSING => 0,
                       InventoryItem::MOVED => 0, InventoryItem::UNEXPECTED => 0];

            // ١) الأصنافُ المجمَّدة: موجود/انتقل/مفقود
            foreach ($items as $it) {
                if (! $scannedIds->contains($it->asset_id)) {
                    $verdict = InventoryItem::MISSING;
                } else {
                    $cur = $current->get($it->asset_id);
                    $verdict = ($cur && $this->samePlace($it->snapshot, $cur))
                        ? InventoryItem::PRESENT : InventoryItem::MOVED;
                }
                if ((string) $it->verdict !== $verdict) {
                    $it->verdict = $verdict;
                    $it->save();
                }
                $counts[$verdict]++;
            }

            // ٢) الطارئُ: مُسِح في النطاقِ خارجَ اللقطة → صنفٌ جديدٌ بحكمِ «غير متوقع»
            $frozen = $items->pluck('asset_id')->flip();
            foreach ($scannedIds as $aid) {
                if ($frozen->has($aid)) continue;
                $cur = $current->get($aid);
                if (! $cur) continue;   // خرج من النطاق بين المسح والمصالحة — لا يُختلَق صف
                InventoryItem::updateOrCreate(
                    ['session_id' => $session->id, 'asset_id' => $aid],
                    ['verdict' => InventoryItem::UNEXPECTED, 'snapshot' => $this->snapshotOf($cur)]
                );
                $counts[InventoryItem::UNEXPECTED]++;
            }

            $session->meta = array_merge((array) $session->meta,
                ['reconciled_at' => now()->toIso8601String(), 'counts' => $counts]);
            $session->save();

            return $counts;
        });

        hub_audit('مصالحة جرد', 'assets', $session->id,
            'موجود ' . $summary[InventoryItem::PRESENT] . ' · مفقود ' . $summary[InventoryItem::MISSING]
            . ' · انتقل ' . $summary[InventoryItem::MOVED] . ' · غير متوقع ' . $summary[InventoryItem::UNEXPECTED]);

        return redirect()->route('inventory.show', $session->id)
            ->with('ok', 'صولحت الجلسةُ: موجود ' . $summary[InventoryItem::PRESENT]
                . ' · مفقود ' . $summary[InventoryItem::MISSING] . ' · انتقل ' . $summary[InventoryItem::MOVED]
                . ' · غير متوقع ' . $summary[InventoryItem::UNEXPECTED]);
    }

    /** أهوَ في موضعِه المجمَّد؟ مقارنةُ المحطةِ والحائزِ (null/'' سواء) */
    protected function samePlace($snapshot, Asset $cur): bool
    {
        return (string) data_get($snapshot, 'station_id') === (string) $cur->station_id
            && (string) data_get($snapshot, 'holder_id') === (string) $cur->holder_id;
    }

    /**
     * **إغلاقُ الجلسة (step-up).** يختم الجلسةَ `مغلقة` (لا مسحَ بعده)، ويُطلق الحدثَ
     * الدلاليَّ `inventory.session_closed`. خلفَ `hub_require_stepup`: لا يُغلَق جردٌ بجلسةٍ مسروقة.
     */
    public function close(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        if ($resp = hub_require_stepup()) return $resp;
        $session = $this->sessionScoped($id);

        if ((string) $session->status !== InventorySession::CLOSED) {
            $session->status = InventorySession::CLOSED;
            $session->closed_at = now();
            $session->closed_by = auth()->id();
            $session->save();

            hub_audit('إغلاق جلسة جرد', 'assets', $session->id, (string) $session->id);
            $this->fire('session_closed', $session);
        }

        return redirect()->route('inventory.show', $session->id)->with('ok', 'أُغلقت جلسةُ الجرد');
    }

    /** بثُّ الحدث الدلاليّ — لا يكسر العمليةَ الأصلية أبداً (نمطُ StationController) */
    protected function fire(string $event, InventorySession $session): void
    {
        try {
            FlowRunner::fire($event, 'inventory', $session);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
