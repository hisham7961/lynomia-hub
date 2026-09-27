<?php

namespace App\Support\Assets;

use App\Models\Asset;
use App\Models\InventoryItem;
use App\Models\InventoryScan;
use App\Models\InventorySession;
use App\Support\Platform\FlowRunner;
use App\Support\Security\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **جلساتُ الجرد — المحرّكُ الواحد للويب والجوال** (Work OS · الطور F · WP-F.3 · §32 · §99).
 *
 * استُخرج من `InventoryController` حرفاً بحرف كي يسلك الجوالُ (`/api/mobile/v1/inventory/*`)
 * **الحارسَ والقاعدةَ والأثرَ نفسَها** لا نسخةً ثانية:
 *   · **`freeze`** — لقطةٌ ثابتةٌ لمجموعةِ الأصولِ بنطاق القارئ (`Custody::scoped`).
 *   · **`scan`** — يحلّ الرمزَ عبر المحلِّل الموحّد `Identity::resolve` (بنطاقِ الماسِح)،
 *     ويختم الماسِحَ وزمنَه، ويصنّف: معروف/غير متوقع/غير معروف — ولا يُخزَّن رمزٌ خامٌ
 *     لأصلٍ خارجَ النطاق.
 *   · **`reconcile`/`close`** — تصنيفُ الفروق وختمُ الجلسة (خلفَ التصعيد في كلا البابَين).
 *
 * **الحرّاس:** `hub_can('assets', v/e)` أو المفتاحُ الدقيق `assetInventory`؛ وعزلُ الشركة
 * صريحٌ عبر `hub_company_ids` (الجلسةُ ليست وحدةَ `hub.modules`). البابُ يترجم النتائجَ
 * إلى لغته (تحويلٌ برسالةٍ للويب، غلافُ `Api::*` للجوال).
 */
final class InventorySessions
{
    /** بوّابةُ الصلاحية على وحدة الأصول (الجردُ عمليّةٌ عليها) */
    public static function can(string $op, ?string $fine = null, $user = null): bool
    {
        $u = $user ?? auth()->user();

        return hub_can($u, 'assets', $op) || ($fine !== null && hub_can($u, 'assets', $fine));
    }

    public static function authorize(string $op, ?string $fine = null): void
    {
        abort_unless(self::can($op, $fine), 403, 'لا تملك صلاحيةَ الأصول');
    }

    /** استعلامُ الجلسات محصورٌ بشركاتِ القارئ — نظيرُ فقرةِ الشركة في `hub_scope` */
    public static function query()
    {
        $q = InventorySession::query();
        if (($cids = hub_company_ids()) !== null) {
            $q->whereIn('company_id', $cids);
        }

        return $q;
    }

    /** جلسةٌ بنطاق القارئ — شركةٌ أجنبيةٌ ٤٠٤ لا كشفَ وجود (IDOR) */
    public static function find(?string $id): InventorySession
    {
        abort_unless(filled($id), 404);

        return self::query()->findOrFail($id);
    }

    /** العدُّ بالحكم من تجميعِ القاعدة (يمثّل الجلسةَ كاملة) */
    public static function counts(InventorySession $session)
    {
        return InventoryItem::where('session_id', $session->id)
            ->select('verdict', DB::raw('COUNT(*) c'))->groupBy('verdict')->pluck('c', 'verdict');
    }

    /* ══════════ التجميد ══════════ */

    /**
     * **التجميدُ — لقطةٌ ثابتة.** يفتح جلسةً ويصوّر الأصولَ بنطاق القارئ بحكمٍ `معلّق`.
     *
     * @return array{0: InventorySession, 1: int}
     */
    public static function freeze(?string $companyId): array
    {
        $session = new InventorySession();
        $session->company_id = $companyId;
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
                    'snapshot'   => json_encode(self::snapshotOf($a), JSON_UNESCAPED_UNICODE),
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

        return [$session, $n];
    }

    /**
     * اللقطةُ المجمَّدة لأصلٍ: هويّةٌ وموضعٌ لحظةَ التجميد — **لا حقولَ سرّ** (لا serial/IP).
     */
    public static function snapshotOf(Asset $a): array
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
     * **مسحُ رمز** — يكتب مسحةً مختومةً بالماسِح. الجلسةُ المغلقة ٤٢٢.
     *
     * @return array{scan: InventoryScan, result: string, flash: string}
     */
    public static function scan(InventorySession $session, string $code): array
    {
        abort_unless((string) $session->status === InventorySession::OPEN, 422, 'الجلسةُ مغلقةٌ — لا مسحَ بعد الإغلاق');
        $code = trim($code);

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

        $scan = InventoryScan::create([
            'session_id' => $session->id,
            'asset_id'   => $assetId,
            'result'     => $result,
            'by_id'      => auth()->id(),   // **الختمُ الذي يطلبه §32**
            'at'         => now(),
            'meta'       => $meta,
        ]);

        return ['scan' => $scan, 'result' => $result, 'flash' => $flash];
    }

    /* ══════════ المصالحة والإغلاق ══════════ */

    /**
     * **المصالحةُ الكتابيّة** — موجود/مفقود/انتقل لكلِّ صنفٍ مجمَّد، والطارئُ `غير متوقع`.
     * البابُ يفرض التصعيدَ قبلها.
     *
     * @return array<string,int> العدُّ بالحكم
     */
    public static function reconcile(InventorySession $session): array
    {
        $summary = DB::transaction(function () use ($session) {
            $scannedIds = InventoryScan::where('session_id', $session->id)
                ->whereNotNull('asset_id')->pluck('asset_id')->unique();

            $items = InventoryItem::where('session_id', $session->id)->orderBy('id')->get();
            $current = Custody::scoped()
                ->whereIn('id', $items->pluck('asset_id')->merge($scannedIds)->unique()->values())
                ->get()->keyBy('id');

            $counts = [InventoryItem::PRESENT => 0, InventoryItem::MISSING => 0,
                       InventoryItem::MOVED => 0, InventoryItem::UNEXPECTED => 0];

            foreach ($items as $it) {
                if (! $scannedIds->contains($it->asset_id)) {
                    $verdict = InventoryItem::MISSING;
                } else {
                    $cur = $current->get($it->asset_id);
                    $verdict = ($cur && self::samePlace($it->snapshot, $cur))
                        ? InventoryItem::PRESENT : InventoryItem::MOVED;
                }
                if ((string) $it->verdict !== $verdict) {
                    $it->verdict = $verdict;
                    $it->save();
                }
                $counts[$verdict]++;
            }

            $frozen = $items->pluck('asset_id')->flip();
            foreach ($scannedIds as $aid) {
                if ($frozen->has($aid)) continue;
                $cur = $current->get($aid);
                if (! $cur) continue;   // خرج من النطاق بين المسح والمصالحة — لا يُختلَق صف
                InventoryItem::updateOrCreate(
                    ['session_id' => $session->id, 'asset_id' => $aid],
                    ['verdict' => InventoryItem::UNEXPECTED, 'snapshot' => self::snapshotOf($cur)]
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

        return $summary;
    }

    /** أهوَ في موضعِه المجمَّد؟ مقارنةُ المحطةِ والحائزِ (null/'' سواء) */
    public static function samePlace($snapshot, Asset $cur): bool
    {
        return (string) data_get($snapshot, 'station_id') === (string) $cur->station_id
            && (string) data_get($snapshot, 'holder_id') === (string) $cur->holder_id;
    }

    /** **إغلاقُ الجلسة** — يعيد true إن أُغلقت الآن (المغلقةُ سلفاً لا تُعاد) */
    public static function close(InventorySession $session): bool
    {
        if ((string) $session->status === InventorySession::CLOSED) return false;

        $session->status = InventorySession::CLOSED;
        $session->closed_at = now();
        $session->closed_by = auth()->id();
        $session->save();

        hub_audit('إغلاق جلسة جرد', 'assets', $session->id, (string) $session->id);
        try {
            FlowRunner::fire('session_closed', 'inventory', $session);
        } catch (\Throwable $e) {
            report($e);   // بثُّ الحدث لا يكسر العمليةَ الأصلية أبداً
        }

        return true;
    }
}
