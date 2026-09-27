<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileEndpoint;
use App\Http\Middleware\MobileContext;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Support\Assets\InventorySessions;
use App\Support\Platform\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * **جلساتُ الجرد بالمسح على الجوال** (خطّةُ التطبيق · المرحلة ٣ · 3.4).
 *
 * المحرّكُ الواحد `InventorySessions` (مستخرَجٌ من `InventoryController` بلا تغيير سلوك) —
 * والحرّاسُ مرآةُ الويب حرفاً:
 *  • القراءة `assets:v`؛ والتجميدُ والمسحُ `assets:e` أو المفتاحُ الدقيق `assetInventory`.
 *  • عزلُ الشركة على كلِّ قارئ (`InventorySessions::query`) — جلسةٌ أجنبيةٌ ٤٠٤.
 *  • المسحُ عبر المحلِّل الموحّد `Identity::resolve` بنطاقِ الماسِح — الرمزُ الخامُ لأصلٍ
 *    خارجَ النطاق لا يُخزَّن ولا يُعاد.
 *  • **المصالحةُ والإغلاقُ خلفَ تصعيد الهويّة** (نظيرُ `hub_require_stepup`): مِنحةُ
 *    `auth/step-up` بالغرض `action:inventory:reconcile|close` وإلا ٤٢٨.
 *
 * داخليّةٌ حصراً: حسابُ العميل محجوبٌ ببوّابة `mobile.portal`.
 */
class MobileInventoryController extends V1Controller
{
    use MobileEndpoint;

    public const STEPUP_RECONCILE = 'action:inventory:reconcile';

    public const STEPUP_CLOSE = 'action:inventory:close';

    /** `GET inventory/sessions` — جلساتي المنعزلةُ بالشركة، الأحدثُ أوّلاً (حتميّ) */
    public function sessions(Request $r): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('v');

        $rows = InventorySessions::query()->withCount(['items', 'scans'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $names = $this->userNames($rows->pluck('by_id'));

        return $this->okData([
            'sessions' => $rows->map(fn (InventorySession $s) => $this->sessionShape($s, $names))->values()->all(),
            'can' => $this->abilities(),
        ]);
    }

    /** `GET inventory/sessions/{id}` — الجلسة + العدُّ بالحكم + صفحةُ أصنافٍ + أحدثُ المسحات */
    public function session(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('v');
        $session = InventorySessions::find($id);

        $items = InventoryItem::where('session_id', $session->id)
            ->orderBy('verdict')->orderBy('id')->paginate(50);
        $scans = InventoryScan::where('session_id', $session->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(50)->get();
        $counts = InventorySessions::counts($session);
        $names = $this->userNames($scans->pluck('by_id')->push($session->by_id));

        return $this->okData([
            'session' => $this->sessionShape($session, $names) + [
                'counts' => $counts->map(fn ($c) => (int) $c)->all(),
                'total' => (int) $counts->sum(),
                'scans_total' => InventoryScan::where('session_id', $session->id)->count(),
            ],
            // اللقطةُ المجمَّدة بلا حقولِ سرّ (المحرّكُ لا يُخزّنها أصلاً)
            'items' => collect($items->items())->map(fn (InventoryItem $it) => [
                'asset_id' => (string) $it->asset_id,
                'verdict' => (string) $it->verdict,
                'code' => (string) data_get($it->snapshot, 'code', ''),
                'name' => (string) data_get($it->snapshot, 'name', ''),
                'type' => (string) data_get($it->snapshot, 'type', ''),
                'status' => (string) data_get($it->snapshot, 'status', ''),
            ])->values()->all(),
            'page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'items_total' => $items->total(),
            'scans' => $scans->map(fn (InventoryScan $s) => $this->scanShape($s, $names))->values()->all(),
            'can' => $this->abilities($session),
        ]);
    }

    /** `POST inventory/sessions` — التجميد: لقطةٌ ثابتةٌ بنطاقي (شركةُ السياقِ إن ضُيِّقت) */
    public function freeze(Request $r): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('e', 'assetInventory');

        // ترويسةُ الشركة تمرّ للمالك بلا فحص — تُتحقَّق قبل أن تُكتب في عمود uuid (لا ٥٠٠ ولا جلسةَ يتيمة)
        $hdr = MobileContext::company($r);
        if ($hdr !== null && ! \App\Models\Company::whereKey($hdr)->exists()) {
            return Api::error(Api::VALIDATION_FAILED, 422, 'الشركةُ المختارة غيرُ موجودة', ['reason' => 'unknown_company']);
        }

        return $this->idempotently($r, function () use ($hdr) {
            $cids = hub_company_ids();
            $company = $hdr
                ?? (($cids !== null && count($cids) === 1) ? $cids[0] : null);
            [$session, $n] = InventorySessions::freeze($company);

            return $this->okData(['session' => $this->sessionShape($session->fresh() ?? $session,
                $this->userNames(collect([$session->by_id]))), 'frozen' => $n], 201);
        });
    }

    /** `POST inventory/sessions/{id}/scan` — `{code}` عبر المحلِّل الموحّد، مختومٌ بالماسِح */
    public function scan(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('e', 'assetInventory');
        $session = InventorySessions::find($id);
        if ((string) $session->status !== InventorySession::OPEN) {
            return Api::error(Api::BUSINESS_RULE_VIOLATION, 422, 'الجلسةُ مغلقةٌ — لا مسحَ بعد الإغلاق',
                ['reason' => 'session_closed']);
        }

        $d = $r->validate(['code' => ['required', 'string', 'max:300']], [], ['code' => 'الرمز']);

        return $this->idempotently($r, function () use ($session, $d) {
            $res = InventorySessions::scan($session, $d['code']);
            $scan = $res['scan'];
            $item = $scan->asset_id
                ? InventoryItem::where('session_id', $session->id)->where('asset_id', $scan->asset_id)->orderBy('id')->first()
                : null;

            return $this->okData([
                'result' => $res['result'],
                'result_key' => match ($res['result']) {
                    InventoryScan::KNOWN => 'known', InventoryScan::UNEXPECTED => 'unexpected', default => 'unknown',
                },
                'message' => $res['flash'],
                'scan' => $this->scanShape($scan, [(string) $scan->by_id => (string) (auth()->user()->name ?? '')]),
                // الأصلُ المحلولُ داخلَ النطاق وحدَه (غيرُ المعروف بلا أصلٍ ولا رمز)
                'asset' => $scan->asset_id ? [
                    'id' => (string) $scan->asset_id,
                    'code' => (string) data_get($scan->meta, 'code', ''),
                    'name' => $item ? (string) data_get($item->snapshot, 'name', '') : null,
                ] : null,
            ]);
        });
    }

    /** `POST inventory/sessions/{id}/reconcile` — خلفَ التصعيد (`action:inventory:reconcile`) */
    public function reconcile(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('e', 'assetInventory');
        if ($resp = $this->requireMobileStepUp($r, self::STEPUP_RECONCILE)) return $resp;
        $session = InventorySessions::find($id);

        return $this->idempotently($r, function () use ($session) {
            $summary = InventorySessions::reconcile($session);

            return $this->okData(['id' => (string) $session->id, 'counts' => $summary,
                'reconciled_at' => data_get($session->meta, 'reconciled_at')]);
        });
    }

    /** `POST inventory/sessions/{id}/close` — خلفَ التصعيد (`action:inventory:close`) */
    public function close(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        InventorySessions::authorize('e', 'assetInventory');
        if ($resp = $this->requireMobileStepUp($r, self::STEPUP_CLOSE)) return $resp;
        $session = InventorySessions::find($id);

        return $this->idempotently($r, function () use ($session) {
            $closedNow = InventorySessions::close($session);

            return $this->okData(['id' => (string) $session->id, 'status' => (string) $session->status,
                'closed_now' => $closedNow, 'closed_at' => optional($session->closed_at)->toIso8601String()]);
        });
    }

    // ═══════════════════════════ مساعِداتٌ داخلية ═══════════════════════════

    /** ما يجوز للقارئ — عرضٌ يعيد الخادمُ فحصَه عند كلِّ فعل (لا تخويلَ من العميل) */
    private function abilities(?InventorySession $s = null): array
    {
        $write = InventorySessions::can('e', 'assetInventory');
        $open = $s === null || (string) $s->status === InventorySession::OPEN;

        return [
            'freeze' => $write,
            'scan' => $write && $open,
            'reconcile' => $write,
            'close' => $write && $open,
            'step_up' => ['reconcile' => self::STEPUP_RECONCILE, 'close' => self::STEPUP_CLOSE],
        ];
    }

    private function sessionShape(InventorySession $s, array $names): array
    {
        return [
            'id' => (string) $s->id,
            'status' => (string) $s->status,
            'open' => (string) $s->status === InventorySession::OPEN,
            'company_id' => $s->company_id ? (string) $s->company_id : null,
            'by' => $s->by_id ? ['id' => (string) $s->by_id, 'name' => (string) ($names[(string) $s->by_id] ?? '')] : null,
            'created_at' => optional($s->created_at)->toIso8601String(),
            'closed_at' => optional($s->closed_at)->toIso8601String(),
            'reconciled_at' => data_get($s->meta, 'reconciled_at'),
            'items_count' => isset($s->items_count) ? (int) $s->items_count : null,
            'scans_count' => isset($s->scans_count) ? (int) $s->scans_count : null,
        ];
    }

    private function scanShape(InventoryScan $s, array $names): array
    {
        return [
            'id' => (string) $s->id,
            'result' => (string) $s->result,
            'asset_id' => $s->asset_id ? (string) $s->asset_id : null,
            // الرمزُ لا يُخزَّن إلا للمحلولِ داخلَ النطاق — «غير معروف» بلا رمز
            'code' => $s->asset_id ? (string) data_get($s->meta, 'code', '') : null,
            'by' => $s->by_id ? ['id' => (string) $s->by_id, 'name' => (string) ($names[(string) $s->by_id] ?? '')] : null,
            'at' => optional($s->at)->toIso8601String(),
        ];
    }

    /** @return array<string,string> */
    private function userNames($ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($i) => (string) $i)->unique()->values()->all();

        return $ids ? DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all() : [];
    }
}
