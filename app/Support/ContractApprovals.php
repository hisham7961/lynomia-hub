<?php

namespace App\Support;

use App\Models\Contract;
use App\Models\ContractApprovalStep;
use App\Models\SignRequest;

/**
 * موافقات العقود المرحلية (CLM م5): قواعدُ من `setting('contracts.approval_steps')`
 * — JSON مصفوفة مراحل مرتبة، لكل مرحلة نوع (مالية/قانونية/مخصصة) وعتبة قيمة
 * `min_value` وموافِق `approver_id` (فارغ = قرار المالكين):
 *
 *   [{"kind":"مالية","min_value":10000,"approver_id":"<uuid>","label":"موافقة المدير المالي"}]
 *
 * طلبُ توقيعٍ لعقدٍ قيمته تبلغ عتبة مرحلةٍ ما يُنشأ «بانتظار الموافقة» ولا يُسلَّم
 * للموقّعين حتى تُعتمد مراحله بالترتيب. الافتراضي بلا قواعد = صفر أثر على أي مسار.
 */
class ContractApprovals
{
    /** قواعد المراحل من الإعدادات — منظفةً ومرتبةً كما كُتبت (بحد ١٠ مراحل) */
    public static function rules(): array
    {
        $raw = setting('contracts.approval_steps');
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (! is_array($raw)) return [];

        $out = [];
        foreach (array_slice(array_values($raw), 0, 10) as $r) {
            if (! is_array($r)) continue;
            $out[] = [
                'kind' => in_array($r['kind'] ?? '', ['مالية', 'قانونية', 'مخصصة'], true) ? $r['kind'] : 'مخصصة',
                'label' => mb_substr(trim((string) ($r['label'] ?? '')), 0, 160) ?: null,
                'min_value' => (float) ($r['min_value'] ?? 0),
                'approver_id' => trim((string) ($r['approver_id'] ?? '')) ?: null,
            ];
        }

        return $out;
    }

    /** المراحل المنطبقة على عقدٍ بقيمته — عقدٌ دون كل العتبات يمضي بلا موافقات */
    public static function stagesFor(?Contract $c): array
    {
        if (! $c) return [];
        $value = (float) ($c->value ?? 0);

        return array_values(array_filter(static::rules(), fn ($r) => $value >= $r['min_value']));
    }

    /** إنشاء مراحل الطلب من قواعده المنطبقة — يعيد عددها (صفر = لا موافقات) */
    public static function spawn(SignRequest $req, Contract $c): int
    {
        $stages = static::stagesFor($c);
        foreach ($stages as $i => $r) {
            ContractApprovalStep::create([
                'request_id' => $req->id, 'contract_id' => $c->id,
                'stage' => $i + 1, 'kind' => $r['kind'], 'label' => $r['label'],
                'approver_id' => $r['approver_id'], 'status' => 'بانتظار',
            ]);
        }

        return count($stages);
    }

    /** المرحلة المعلقة الحالية بترتيبها — null إن اكتملت السلسلة */
    public static function pending(SignRequest $req): ?ContractApprovalStep
    {
        return ContractApprovalStep::where('request_id', $req->id)
            ->where('status', 'بانتظار')->orderBy('stage')->first();
    }

    /** صاحب قرار المرحلة: موافِقها المسمى أو المالك — والمسماة يظل المالك فوقها */
    public static function canDecide($user, ContractApprovalStep $step): bool
    {
        if (! $user) return false;

        return hub_is_owner($user) || ($step->approver_id && $step->approver_id === $user->id);
    }
}
