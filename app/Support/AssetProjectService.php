<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetProjectAssignment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * **خدمةُ تخصيصِ الأصلِ للمشروع** (Project 360 · §15/§17/§18/§21/§46/§47) — المحرّكُ الواحدُ
 * للويبِ والـAPI (لا منطقَ مكرَّر · §56). التخصيصُ **علاقةٌ تشغيليّةٌ، لا عهدةٌ ماديّة**:
 * لا يمسّ `holder_id`/`station_id`/`asset_custody`/النقطةَ الطرفيّة/الحالة — والإنهاءُ لا يفكّها (§18).
 *
 * الثوابت: الزوجُ (أصل، مشروع) النشطُ لا يُكرَّر (فهرسٌ فريدٌ عبر المحرّكين + معاملة/قفل §46 →
 * idempotent)؛ أصلٌ واحدٌ قد يدعم عدّةَ مشاريعَ نشطة (§65)؛ الإنهاءُ يحفظ التاريخَ لا يحذف (§21)؛
 * كلُّ تغييرٍ مُدقَّق (§47). **العميلُ لا يُخصِّص أبداً**، والتفويضُ/العزلُ يُحسمان في المتحكّم قبلَ الوصولِ هنا.
 */
class AssetProjectService
{
    /** حالاتُ الأصلِ النهائيّةُ التي لا معنى للتخصيصِ فيها (مستبعد/مباع/مُعاد/مفقود/تالف) */
    public const INELIGIBLE_STATUSES = Asset::ENDPOINT_INELIGIBLE_STATUSES;

    /**
     * **تخصيصُ أصلٍ لمشروع** — idempotent: زوجٌ نشطٌ قائمٌ يُعاد كما هو (لا تكرار). يفترض أنّ
     * المتحكّم حلّ الأصلَ والمشروعَ ضمن نطاقِ المستخدم (IDOR-safe) وتحقّق من `hub_can`.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403/422 على انتهاكِ ثابت
     */
    public function assign(Asset $asset, Project $project, User $actor,
                           ?string $purpose = null, ?string $note = null): AssetProjectAssignment
    {
        abort_if(hub_is_client($actor), 403, 'العملاءُ لا يخصّصون الأصولَ الداخليّة');

        // شركةٌ متوافقة: أصلٌ ومشروعٌ لشركتين مختلفتين لا يُربطان (عزلٌ فوق تنطيقِ المتحكّم)
        abort_if(
            $asset->company_id !== null && $project->company_id !== null
                && (string) $asset->company_id !== (string) $project->company_id,
            422, 'الأصلُ والمشروعُ لشركتين مختلفتين'
        );

        // حالةٌ نهائيّةٌ لا معنى للتخصيصِ فيها (fail-closed على التطبيع)
        $status = Custody::canonicalStatus($asset->status);
        abort_if($status !== null && in_array($status, self::INELIGIBLE_STATUSES, true),
            422, 'حالةُ الأصلِ لا تسمح بالتخصيص: ' . $status);

        $purpose = ($p = trim((string) $purpose)) !== '' ? mb_substr($p, 0, 120) : null;
        $note = ($n = trim((string) $note)) !== '' ? mb_substr($n, 0, 500) : null;

        return DB::transaction(function () use ($asset, $project, $actor, $purpose, $note) {
            // قفلُ الصفوفِ النشطةِ لهذا الزوج — دفاعٌ في العمقِ ضدّ التزامن (§46)
            $existing = AssetProjectAssignment::where('asset_id', $asset->getKey())
                ->where('project_id', $project->getKey())
                ->whereNull('ended_at')->lockForUpdate()->first();
            if ($existing) return $existing;   // idempotent — لا تكرارَ لزوجٍ نشط

            try {
                $a = AssetProjectAssignment::create([
                    'company_id'  => $asset->company_id ?? $project->company_id,
                    'asset_id'    => (string) $asset->getKey(),
                    'project_id'  => (string) $project->getKey(),
                    'purpose'     => $purpose,
                    'note'        => $note,
                    'assigned_at' => now(),
                    'assigned_by' => (string) $actor->getKey(),
                    'active_flag' => AssetProjectAssignment::ACTIVE,
                ]);
            } catch (QueryException $e) {
                // سباقٌ نادر: معاملةٌ أخرى أدرجت الزوجَ النشطَ بيننا — الفهرسُ الفريدُ صدّنا.
                // نعيد قراءةَ النشطِ ونُعيده (idempotent) بدل إفشالِ الطلب.
                if ((string) $e->getCode() === '23000') {
                    $row = AssetProjectAssignment::where('asset_id', $asset->getKey())
                        ->where('project_id', $project->getKey())->whereNull('ended_at')->first();
                    if ($row) return $row;
                }
                throw $e;
            }

            hub_audit('asset.project.assign', 'assets', (string) $asset->getKey(),
                $project->name ?? 'مشروع', [
                    'project_id' => (string) $project->getKey(),
                    'assignment_id' => (string) $a->getKey(),
                    'purpose' => $purpose,
                ]);

            return $a;
        });
    }

    /**
     * **إنهاءُ تخصيص** — يحفظ التاريخ (لا حذف · §21): `ended_at`/`ended_by`/`active_flag=NULL`.
     * لا يمسّ الحائزَ ولا المحطّةَ ولا العهدة (§18). idempotent (مُنهًى يبقى مُنهى).
     */
    public function end(AssetProjectAssignment $a, User $actor, ?string $reason = null): AssetProjectAssignment
    {
        abort_if(hub_is_client($actor), 403, 'العملاءُ لا يُنهون تخصيصاتِ الأصول');
        if ($a->ended_at !== null) return $a;   // idempotent

        $a->forceFill([
            'ended_at' => now(),
            'ended_by' => (string) $actor->getKey(),
            'active_flag' => null,               // يخرج من فرادةِ النشط
        ])->save();

        hub_audit('asset.project.end', 'assets', (string) $a->asset_id, null, [
            'project_id' => (string) $a->project_id,
            'assignment_id' => (string) $a->getKey(),
            'reason' => ($r = trim((string) $reason)) !== '' ? mb_substr($r, 0, 500) : null,
        ]);

        return $a;
    }

    /* ────────── قراءاتٌ محدودةٌ حتميّة (لا N+1) ────────── */

    /** تخصيصاتُ المشروعِ النشطة (مع الأصل)، محدودةٌ ومرتّبةٌ حتميّاً */
    public function activeForProject(string $projectId, int $limit = 200)
    {
        return AssetProjectAssignment::active()->where('project_id', $projectId)
            ->with('asset:id,name,code,type,status,holder_id,station_id')
            ->orderByDesc('assigned_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** تاريخُ تخصيصاتِ المشروعِ (نشطٌ ومُنهًى) */
    public function historyForProject(string $projectId, int $limit = 100)
    {
        return AssetProjectAssignment::where('project_id', $projectId)
            ->with('asset:id,name,code,type')
            ->orderByDesc('assigned_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** تخصيصاتُ الأصلِ النشطة (مع المشروع) */
    public function activeForAsset(string $assetId, int $limit = 100)
    {
        return AssetProjectAssignment::active()->where('asset_id', $assetId)
            ->with('project:id,name,status,client_id,audience')
            ->orderByDesc('assigned_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** تاريخُ تخصيصاتِ الأصلِ (نشطٌ ومُنهًى) */
    public function historyForAsset(string $assetId, int $limit = 100)
    {
        return AssetProjectAssignment::where('asset_id', $assetId)
            ->with('project:id,name,status')
            ->orderByDesc('assigned_at')->orderByDesc('id')->limit($limit)->get();
    }

    /** عددُ الأصولِ النشطةِ المخصَّصةِ لمشروع (عدٌّ مُنطَّقٌ لا صفوفٌ مخفيّة) */
    public function activeCountForProject(string $projectId): int
    {
        return AssetProjectAssignment::active()->where('project_id', $projectId)->count();
    }
}
