<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Support\Assets\InventorySessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 *
 * **المحرّكُ في `App\Support\Assets\InventorySessions`** (سكّةٌ واحدةٌ يسلكها الجوالُ حرفاً)؛
 * هذا البابُ يحمل شكلَ ردِّ الويب وحدَه (صفحةٌ وتحويلٌ برسالة).
 */
class InventoryController extends Controller
{
    /** بوّابةُ الصلاحية (المفتاحُ الدقيق `assetInventory` بديلٌ لـ`e`) — من المحرّك الواحد */
    protected function can(string $op, ?string $fine = null): void
    {
        InventorySessions::authorize($op, $fine);
    }

    /** جلسةٌ بنطاق القارئ — شركةٌ أجنبيةٌ ٤٠٤ لا كشفَ وجود (IDOR) */
    protected function sessionScoped(?string $id): InventorySession
    {
        return InventorySessions::find($id);
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

        $rows = InventorySessions::query()
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
        // والعدُّ قبل القصّ (W-5): جلسةٌ فيها ألفُ مسحةٍ كانت تُقرأ «٢٠٠»
        $scansN = InventoryScan::where('session_id', $session->id)->count();
        $scans = InventoryScan::where('session_id', $session->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(200)->get();

        // أسماءُ الماسِحين (by_id) — لا استعلامٌ لكلِّ صف
        $names = DB::table('users')
            ->whereIn('id', $scans->pluck('by_id')->filter()->unique())
            ->pluck('name', 'id');

        // (AUDIT-8) العدُّ بالحكم من **تجميعِ القاعدة** لا من صفوفِ الصفحة
        $counts = InventorySessions::counts($session);
        $total = (int) $counts->sum();

        // خريطةُ أكواد اللقطة لصفوفِ المسح (المحدودةِ بـ200) — من عناصرِ تلك المسحات وحدَها
        $scanAssetIds = $scans->pluck('asset_id')->filter()->unique()->values();
        $itemCodes = $scanAssetIds->isEmpty() ? collect()
            : InventoryItem::where('session_id', $session->id)->whereIn('asset_id', $scanAssetIds->all())
                ->get(['asset_id', 'snapshot'])
                ->mapWithKeys(fn ($it) => [$it->asset_id => (string) data_get($it->snapshot, 'code', '')]);

        return view('inventory.show', [
            'session'   => $session,
            'items'     => $items,
            'scans'     => $scans,
            'scansN'    => $scansN,
            'names'     => $names,
            'itemCodes' => $itemCodes,
            'counts'    => $counts,
            'total'     => $total,
        ]);
    }

    /* ══════════ التجميد · المسح · المصالحة · الإغلاق (المحرّكُ الواحد) ══════════ */

    /** **التجميدُ — لقطةٌ ثابتة** بنطاق القارئ (`InventorySessions::freeze`) */
    public function freeze(Request $r)
    {
        $this->can('e', 'assetInventory');

        [$session, $n] = InventorySessions::freeze($this->activeCompanyId());

        return redirect()->route('inventory.show', $session->id)
            ->with('ok', 'فُتِحت جلسةُ جردٍ وجُمِّد ' . $n . ' أصلاً');
    }

    /** **مسحُ رمز** عبر المحلِّل الموحّد، مختومٌ بالماسِح (`InventorySessions::scan`) */
    public function scan(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        $session = $this->sessionScoped($id);
        abort_unless((string) $session->status === InventorySession::OPEN, 422, 'الجلسةُ مغلقةٌ — لا مسحَ بعد الإغلاق');

        $d = $r->validate(['code' => ['required', 'string', 'max:300']]);
        $res = InventorySessions::scan($session, $d['code']);

        return redirect()->route('inventory.show', $session->id)->with('ok', $res['flash']);
    }

    /** **المصالحةُ الكتابيّة (step-up)** — لا تُثبَّت فروقُ جردٍ بجلسةٍ مسروقةٍ وحدَها */
    public function reconcile(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        if ($resp = hub_require_stepup()) return $resp;
        $session = $this->sessionScoped($id);

        $summary = InventorySessions::reconcile($session);

        return redirect()->route('inventory.show', $session->id)
            ->with('ok', 'صولحت الجلسةُ: موجود ' . $summary[InventoryItem::PRESENT]
                . ' · مفقود ' . $summary[InventoryItem::MISSING] . ' · انتقل ' . $summary[InventoryItem::MOVED]
                . ' · غير متوقع ' . $summary[InventoryItem::UNEXPECTED]);
    }

    /** **إغلاقُ الجلسة (step-up)** — يُطلق `inventory.session_closed` مرّةً واحدة */
    public function close(Request $r, string $id)
    {
        $this->can('e', 'assetInventory');
        if ($resp = hub_require_stepup()) return $resp;
        $session = $this->sessionScoped($id);

        InventorySessions::close($session);

        return redirect()->route('inventory.show', $session->id)->with('ok', 'أُغلقت جلسةُ الجرد');
    }
}
