<?php

namespace App\Support\Assets;

use App\Models\Asset;
use App\Models\AssetCustody;
use Illuminate\Validation\Rule;

/**
 * **تسليمُ العهدة واستردادُها — السكّةُ الواحدة للويب والجوال.**
 *
 * استُخرجت من `CustodyController::handover/recover` حرفاً بحرف كي يسلك الجوالُ
 * (`POST /api/mobile/v1/custody/{id}/handover|recover`) **الحارسَ والقاعدةَ والأثرَ نفسَها**:
 *
 *  · **البوّابة** `asset()`: `hub_can('assets', e)` أو المفتاحُ الدقيق `custodyAssign`
 *    (Permissions 360 · 12.4)، ثم `Custody::scoped()` (نطاقُ القارئ + عزلُ الشركة) —
 *    أصلٌ خارجَ النطاق ٤٠٤ لا كشفَ وجود.
 *  · **القاعدة**: قواعدُ التحقّق نفسُها (`handoverRules`/`recoverRules`)، ومشروعٌ خارجَ نطاق
 *    المسلِّم لا يُقبل (`hub_guard_scope_input`)، والاسترداد لعهدةٍ بيد أحدٍ فقط.
 *  · **الأثر**: `Custody::move` (المعاملةُ المقفلة) + قيدُ تدقيقٍ + إشعارُ المستلم.
 *
 * الويبُ لا يطلب تصعيدَ هويّةٍ لهذين الفعلين، فلا يطلبه الجوالُ (تكافؤٌ لا تشديدٌ مختلَق).
 */
final class CustodyHandover
{
    /**
     * الأصلُ بنطاق القارئ وصلاحيته — بوّابةُ كلِّ فعلٍ على العهدة.
     *
     * `$fine`: مفتاحٌ دقيقٌ بديلٌ يفتح الطريقَ لمن لا يملك رايةَ `e` الجامعة (أمينُ عهدة).
     */
    public static function asset(string $id, string $op = 'v', ?string $fine = null): Asset
    {
        $u = auth()->user();
        $ok = hub_can($u, 'assets', $op) || ($fine !== null && hub_can($u, 'assets', $fine));
        abort_unless($ok, 403,
            $op === 'v' ? 'لا تملك عرض الأصول والعهد' : 'تعديلُ العهدة يتطلب صلاحية تعديل الأصول');

        return Custody::scoped()->findOrFail($id);
    }

    /** قواعدُ تحقّقِ التسليم — مشتركةٌ بين البابَين */
    public static function handoverRules(): array
    {
        return [
            'userId' => ['required', 'string', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'at'     => 'required|date',
            'note'   => 'nullable|string|max:500',
            'projectId' => ['nullable', 'string', Rule::exists('projects', 'id')->whereNull('deleted_at')],
        ];
    }

    public const HANDOVER_LABELS = ['userId' => 'المستلم', 'at' => 'تاريخ التسليم', 'note' => 'ملاحظة', 'projectId' => 'المشروع'];

    /** قواعدُ تحقّقِ الاسترداد */
    public static function recoverRules(): array
    {
        return [
            'at'   => 'required|date',
            'note' => 'nullable|string|max:500',
        ];
    }

    public const RECOVER_LABELS = ['at' => 'تاريخ الاسترداد', 'note' => 'ملاحظة'];

    /** **التسليم** (بعد البوّابة والتحقّق): حركةٌ مقفلة + تدقيق + إشعارُ المستلم */
    public static function handover(Asset $a, array $d): AssetCustody
    {
        hub_guard_scope_input($d, ['projectId' => 'projects']);   // مشروعٌ خارج نطاق المسلِّم لا يُقبل (v2.399)

        $entry = Custody::move($a, 'تسليم', $d['userId'], substr((string) $d['at'], 0, 10), $d['note'] ?? null,
            ['project_id' => $d['projectId'] ?? null]);

        hub_audit('تسليم عهدة', 'assets', $a->id, (string) $a->name,
            ['after' => ['المستلم' => $d['userId'], 'التاريخ' => $entry->at?->toDateString()]]);

        // المستلمُ يُخبَر: عهدةٌ باسمه لا يعلم بها لا يُسأل عنها بعدل
        hub_notify($d['userId'], 'custody',
            '🧰 سُجّلت باسمك عهدة: ' . \Illuminate\Support\Str::limit((string) $a->name, 60)
            . ' (' . $a->code . ')', 'assets', $a->id);

        return $entry;
    }

    /** الاستردادُ لعهدةٍ بيد أحد — يُسأل قبل التحقّق (ترتيبُ الويب القائم) */
    public static function assertHeld(Asset $a): void
    {
        abort_if(! $a->holder_id, 422, 'هذه العهدة ليست بيد أحد أصلاً');
    }

    /** **الاسترداد** (بعد البوّابة والتحقّق): العهدةُ بيد أحدٍ شرطٌ، وإلا ٤٢٢ */
    public static function recover(Asset $a, array $d): AssetCustody
    {
        self::assertHeld($a);

        $was = $a->holder_id;
        $entry = Custody::move($a, 'استرداد', null, substr((string) $d['at'], 0, 10), $d['note'] ?? null);

        hub_audit('استرداد عهدة', 'assets', $a->id, (string) $a->name,
            ['before' => ['الحائز' => $was], 'after' => ['الحائز' => '—']]);

        return $entry;
    }
}
