<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\MobileEndpoint;
use App\Models\Asset;
use App\Models\AssetCustody;
use App\Support\Assets\CustodyHandover;
use App\Support\Collaboration\Acks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * **عهدتي + تسليمُ العهدة واستردادُها على الجوال** (خطّةُ التطبيق · المرحلة ٣ · 3.3).
 *
 *  • `GET me/custody` — نظيرُ «عهدتي» الويبية (`PortalController::myCustody`): ما بيدي
 *    (`holder_id = أنا`) وحركاتُ عهدتي، وإقراراتُ الاستلام المعلّقة من المحرّك الواحد
 *    (`Acks::pending` — خلفَ `hub_can(assets,v)` ونطاقِه كما في صندوق الويب). الإقرارُ نفسُه
 *    يبقى عبر إجراءِ السجلّ القائم `POST assets/{id}/actions/ack` (لا بابَ ثانٍ).
 *    الحقلُ المحجوبُ على الدور (`hub_field_mode = hide`) يُعاد null.
 *  • `POST custody/{id}/handover|recover` — السكّةُ الواحدة `CustodyHandover` (مستخرَجةٌ من
 *    `CustodyController` بلا تغيير سلوك): `assets:e` أو المفتاحُ الدقيق `custodyAssign`،
 *    `Custody::scoped` (خارجَ النطاق ٤٠٤)، القواعدُ والأثرُ والإشعارُ نفسُها. الويبُ لا
 *    يُصعّد هذين الفعلين فلا يُصعّدهما الجوال. `Idempotency-Key` على الاثنين.
 *
 * حسابُ العميل محجوبٌ ببوّابة `mobile.portal` (المساراتُ خارجَ قائمته البيضاء).
 */
class MobileCustodyController extends V1Controller
{
    use MobileEndpoint;

    /** `GET me/custody` — عهدتي وحركاتُها وإقراراتي المعلّقة */
    public function mine(Request $r): Response
    {
        $this->tagMobile($r);
        $u = $r->user();
        $uid = (string) $u->id;

        $assets = Asset::query()->whereNull('deleted_at')->where('holder_id', $uid)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'type', 'tag', 'code', 'serial', 'status', 'station_id', 'version']);

        $stations = $assets->pluck('station_id')->filter()->unique()->values()->all();
        $stationNames = $stations ? hub_ref_labels('stations', $stations) : [];

        // الإقراراتُ المعلّقة — المحرّكُ الواحد (نطاقٌ + صلاحيّةٌ داخله)، مقصوراً على العهد
        $pending = collect(Acks::pending($u))->where('module', 'assets')->values();
        $pendingIds = $pending->pluck('id')->map(fn ($i) => (string) $i)->all();

        // قيدُ الحقل: ما يُخفى عن الدور لا يُعاد (null لا قيمة)
        $show = fn (string $key) => hub_field_mode($u, 'assets', $key) !== 'hide';

        $moves = Schema::hasTable('asset_custody')
            ? AssetCustody::query()->whereNull('deleted_at')->where('user_id', $uid)
                ->orderByDesc('at')->orderByDesc('id')->limit(30)
                ->get(['id', 'asset_id', 'action', 'at', 'note'])
            : collect();
        $moveNames = $moves->isEmpty() ? [] : DB::table('assets')
            ->whereIn('id', $moves->pluck('asset_id')->filter()->unique()->values()->all())->pluck('name', 'id')->all();

        return $this->okData([
            'assets' => $assets->map(fn ($a) => [
                'id' => (string) $a->id,
                'code' => $show('code') ? (string) $a->code : null,
                'name' => (string) $a->name,
                'type' => $a->type !== null ? (string) $a->type : null,
                'tag' => $show('tag') && $a->tag !== null ? (string) $a->tag : null,
                'serial' => $show('serial') && $a->serial !== null ? (string) $a->serial : null,
                'status' => $a->status !== null ? (string) $a->status : null,
                'station' => $a->station_id
                    ? ['id' => (string) $a->station_id, 'name' => (string) ($stationNames[$a->station_id] ?? '')] : null,
                'receipt_pending' => in_array((string) $a->id, $pendingIds, true),
            ])->values()->all(),
            'moves' => $moves->map(fn ($m) => [
                'id' => (string) $m->id,
                'asset_id' => (string) $m->asset_id,
                'asset_name' => (string) ($moveNames[$m->asset_id] ?? ''),
                'action' => (string) $m->action,
                'at' => $m->at?->toDateString(),
                'note' => $m->note,
            ])->values()->all(),
            'pending_receipts' => $pending->map(fn ($p) => [
                'module' => 'assets',
                'id' => (string) $p['id'],
                'title' => (string) $p['title'],
                'label' => (string) $p['label'],
                'why' => (string) $p['why'],
                // الإقرارُ عبر إجراءِ السجلّ القائم (القاعدةُ من الباب الواحد `Acknowledgement`)
                'ack' => ['method' => 'POST', 'path' => '/' . \App\Support\Mobile\MobileOpenApi::PREFIX . '/assets/' . $p['id'] . '/actions/ack'],
            ])->all(),
        ]);
    }

    /** `POST custody/{id}/handover` — `{user_id, at, note?, project_id?}` */
    public function handover(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $a = CustodyHandover::asset($id, 'e', 'custodyAssign');

        $d = $this->validateMapped($r, CustodyHandover::handoverRules(), CustodyHandover::HANDOVER_LABELS,
            ['user_id' => 'userId', 'at' => 'at', 'note' => 'note', 'project_id' => 'projectId']);

        return $this->idempotently($r, function () use ($a, $d) {
            $entry = CustodyHandover::handover($a, $d);

            return $this->okData(self::result($a->fresh() ?? $a, $entry));
        });
    }

    /** `POST custody/{id}/recover` — `{at, note?}` (العهدةُ بيد أحدٍ شرط، وإلا ٤٢٢) */
    public function recover(Request $r, string $id): Response
    {
        $this->tagMobile($r);
        $a = CustodyHandover::asset($id, 'e', 'custodyAssign');

        return $this->idempotently($r, function () use ($r, $a) {
            CustodyHandover::assertHeld($a);
            $d = $this->validateMapped($r, CustodyHandover::recoverRules(), CustodyHandover::RECOVER_LABELS,
                ['at' => 'at', 'note' => 'note']);
            $entry = CustodyHandover::recover($a, $d);

            return $this->okData(self::result($a->fresh() ?? $a, $entry));
        });
    }

    /**
     * التحقّقُ **بقواعد الويب نفسِها** على مفاتيحِ الجوال (snake_case): تُعاد تسميةُ مفاتيح
     * القواعد، ثم تُعاد البياناتُ بمفاتيحِ السكّة الواحدة.
     *
     * @param array<string,string> $map مفتاحُ الجوال ⇒ مفتاحُ السكّة
     */
    private function validateMapped(Request $r, array $rules, array $labels, array $map): array
    {
        $mRules = [];
        $mLabels = [];
        foreach ($map as $mobile => $rail) {
            $mRules[$mobile] = $rules[$rail];
            if (isset($labels[$rail])) $mLabels[$mobile] = $labels[$rail];
        }
        $v = Validator::make($r->all(), $mRules, [], $mLabels)->validate();

        $out = [];
        foreach ($map as $mobile => $rail) {
            if (array_key_exists($mobile, $v)) $out[$rail] = $v[$mobile];
        }

        return $out;
    }

    /** نتيجةُ الحركة: الأصلُ بعدها + صفُّ الحركة (لا حقلَ محجوب) */
    private static function result(Asset $a, AssetCustody $m): array
    {
        return [
            'asset' => [
                'id' => (string) $a->id,
                'name' => (string) $a->name,
                'status' => $a->status !== null ? (string) $a->status : null,
                'holder_id' => $a->holder_id ? (string) $a->holder_id : null,
            ],
            'movement' => [
                'id' => (string) $m->id,
                'action' => (string) $m->action,
                'at' => $m->at?->toDateString(),
                'user_id' => $m->user_id ? (string) $m->user_id : null,
                'project_id' => $m->project_id ? (string) $m->project_id : null,
            ],
        ];
    }
}
