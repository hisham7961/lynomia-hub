<?php

namespace App\Support;

use App\Http\Middleware\PortalGuard;
use App\Models\User;

/**
 * **مُفسِّرُ الصلاحيّة الفعّالة (PermissionInspector)** — طبقةُ قراءةٍ وتفسيرٍ لا محرّكُ توثيقٍ ثانٍ
 * (Permissions Reconciliation · §12/§22/§59). تجيب سؤالَ المالك: «ماذا يرى هذا الموظف، ولماذا؟»
 * دون قراءةِ الكود.
 *
 * **تفوّض لا تكرّر:** كلُّ قرارٍ يُشتَقُّ من محرّكِ التوثيقِ القائم نفسِه — `hub_can` (المصفوفة)،
 * `hub_flag` (الرايات)، `PortalGuard::MODULE_ALLOW` (حدُّ العميل)، `hub_scope`/`hub_company_ids`/
 * `hub_client_ids`/`hub_scoped` (النطاق)، `hub_field_mode` (الحقول). لا تُتَّخذ قرارُ سماحٍ هنا
 * أبداً؛ إنّما يُعاد بناءُ **سلسلةِ السبب** حول قرارِ `hub_can` ليُفهَم.
 *
 * **الحالاتُ الأربعُ (§13) لا تُطوى في «غير مرئي»:**
 *   · `ALLOWED` — مسموح (قد يكون النطاقُ فارغاً: بيانةٌ لا منع).
 *   · `DENIED_ROLE` — الدورُ يمنع (لا صلاحيّة).
 *   · `DENIED_CLIENT` — حدُّ نوعِ الحساب (عميل) يعلو على المصفوفة.
 *   · `FLAG_GOVERNED` / `DEPRECATED` — ليست وجهةَ مصفوفةٍ إنسانيّة.
 */
class PermissionInspector
{
    public const OPS = ['v' => 'عرض', 'a' => 'إضافة', 'e' => 'تعديل', 'd' => 'حذف'];

    /**
     * **يُفسِّر (مستخدم، وحدة، عمليّة)** — يعيد قرارَ السماحِ الفعّال وسلسلةَ سببه.
     *
     * @return array{allowed:bool, state:string, reason:string, module:string, op:string, chain:list<array{step:string, ok:bool, detail:string}>}
     */
    public static function explain(User $user, string $module, string $op = 'v'): array
    {
        $op = array_key_exists($op, self::OPS) ? $op : 'v';
        $def = hub_mod($module);
        $chain = [];
        $add = function (string $step, bool $ok, string $detail) use (&$chain) {
            $chain[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];
        };

        // 1) الوحدةُ حقيقيّة؟
        if (! $def) {
            $add('الوحدة', false, "لا وحدةَ بالمفتاح «{$module}» في سجلّ الوحدات");
            return self::verdict(false, 'UNKNOWN_MODULE', 'مفتاحُ وحدةٍ غيرُ معروف', $module, $op, $chain);
        }
        $label = $def['label'] ?? $module;
        $add('الوحدة', true, "«{$label}» ({$module}) — جدول {$def['table']}");

        // وحدةُ users محكومةٌ بالعلَم لا المصفوفة (ModuleController يردّ ٤٠٤ عليها)
        if ($module === 'users') {
            $ok = hub_flag($user, 'users');
            $add('محكومةٌ بعلَم', $ok, 'إدارةُ المستخدمين براية users لا مصفوفةِ الدور');
            return self::verdict($ok, 'FLAG_GOVERNED',
                $ok ? 'مسموحٌ عبر راية «إدارة المستخدمين»' : 'راية «إدارة المستخدمين» غيرُ ممنوحة',
                $module, $op, $chain);
        }

        // 2) نوعُ الحساب (العميل) — حدٌّ يعلو على المصفوفة
        if (hub_is_client($user)) {
            $allowedForClient = in_array($module, PortalGuard::MODULE_ALLOW, true);
            $add('نوعُ الحساب', $allowedForClient,
                $allowedForClient
                    ? 'حسابُ عميلٍ — الوحدةُ ضمن قائمةِ البوّابة المسموحة'
                    : 'حسابُ عميلٍ — الوحدةُ داخليّةٌ خارجَ قائمةِ البوّابة (يعلو على المصفوفة)');
            if (! $allowedForClient) {
                return self::verdict(false, 'DENIED_CLIENT',
                    'حدُّ حساب العميل يمنع الوحدةَ الداخليّةَ مهما كانت المصفوفة', $module, $op, $chain);
            }
        }

        // 3) المالكُ يتجاوز
        if (hub_is_owner($user)) {
            $add('المالك', true, 'دورُ المالكِ يتجاوز المصفوفةَ كلَّها');
            return self::verdict(true, 'OWNER', 'وصولٌ كاملٌ للمالك', $module, $op, $chain);
        }

        // 4) الدورُ موجود؟
        if (! $user->role) {
            $add('الدور', false, 'لا دورَ مُسنَدٌ للحساب');
            return self::verdict(false, 'DENIED_NO_ROLE', 'لا دورَ مُسنَد', $module, $op, $chain);
        }
        $add('الدور', true, 'الدور: ' . ($user->role->name ?? '؟'));

        // 5) الكتابةُ تستلزم العرض (دلالةُ المحرّك) — يُبيَّن للمُفسِّر
        if ($op !== 'v' && ! hub_can($user, $module, 'v')) {
            $add("العمليّة ({$op})", false, 'الكتابةُ تستلزم العرضَ أولاً، والعرضُ ممنوع');
            return self::verdict(false, 'DENIED_ROLE',
                'الدورُ يمنع العرضَ فلا تُتاح ' . self::OPS[$op], $module, $op, $chain);
        }

        // 6) قرارُ المصفوفةِ الحاسم — من hub_can نفسِه (لا قرارَ محلّيّ)
        $allowed = hub_can($user, $module, $op);
        $add("المصفوفة [{$module}][{$op}]", $allowed,
            $allowed ? 'مضبوطٌ في مصفوفةِ الدور' : 'غيرُ مضبوطٍ في مصفوفةِ الدور');

        if (! $allowed) {
            return self::verdict(false, 'DENIED_ROLE',
                "الدور «{$user->role->name}» لا يمنح {$module}.{$op}", $module, $op, $chain);
        }

        // 7) ملاحظاتٌ لا حواجز: النطاقُ والحقول (تُميّز «مسموحٌ بلا سجلّات» عن «ممنوع» · §39)
        if ($op === 'v') {
            $scope = self::scopeNote($user, $module);
            if ($scope !== '') $add('النطاق', true, $scope);
            $fields = self::fieldNote($user, $module);
            if ($fields !== '') $add('قواعدُ الحقل', true, $fields);
        }

        return self::verdict(true, 'ALLOWED', 'مسموحٌ عبر مصفوفةِ الدور', $module, $op, $chain);
    }

    /**
     * **يفسِّر «هل يصل المستخدمُ X الوثيقةَ Y؟»** (Permissions 360 · وثائق · المستوى 5/6).
     * سلسلةُ السبب: رؤيةُ السجلِّ الأمِّ (وحدة+نطاق+حدُّ العميل) ← قاعدةُ الوثيقةِ الصريحة.
     *
     * @return array{allowed:bool, state:string, reason:string, chain:list<array{step:string, ok:bool, detail:string}>}
     */
    public static function explainDocument(User $user, \App\Models\Attachment $a, string $action = 'download'): array
    {
        $chain = [];
        $add = function (string $step, bool $ok, string $detail) use (&$chain) {
            $chain[] = ['step' => $step, 'ok' => $ok, 'detail' => $detail];
        };

        // 1) رؤيةُ السجلِّ الأمِّ (نفسُ حارسِ التنزيل: وحدة v + نطاق + حدُّ العميل)
        $parent = self::explain($user, (string) $a->module, 'v');
        $add('السجلُّ الأمُّ', $parent['allowed'], $parent['reason'] . " (سجلّ {$a->module})");
        if (! $parent['allowed']) {
            return ['allowed' => false, 'state' => $parent['state'], 'reason' => 'السجلُّ الأمُّ غيرُ مرئيٍّ فلا تُتاح وثيقتُه', 'chain' => $chain];
        }

        // 2) طبقةُ الوثيقةِ على المورد (المالك/قواعدُ المستخدم/الدور/الوراثة)
        $doc = \App\Support\DocumentPolicy::decide($user, $a, $action);
        $add('قاعدةُ الوثيقة', $doc['allowed'], $doc['reason']);

        // 3) تصنيفُ الحساسية (بيانةٌ لا منع): نوعٌ حسّاسٌ يُنبّه على ضبطِ وصولٍ صريح
        if (hub_doc_sensitive((string) $a->module, $a->kind)) {
            $add('التصنيف', true, 'نوعٌ حسّاسٌ ('
                . (hub_doc_label((string) $a->module, $a->kind) ?: $a->kind)
                . ') — بياناتٌ شخصيّة/ماليّة يُنصَح بضبطِ قاعدةِ وصولٍ صريحة');
        }

        return ['allowed' => $doc['allowed'], 'state' => $doc['state'], 'reason' => $doc['reason'],
            'sensitive' => hub_doc_sensitive((string) $a->module, $a->kind), 'chain' => $chain];
    }

    /** وصفُ نطاقِ السجلّ (شركة/عميل/مشروع) — «بيانةٌ لا منع»: مسموحٌ وإن كان النطاقُ فارغاً */
    protected static function scopeNote(User $user, string $module): string
    {
        $parts = [];
        if (hub_scoped($user)) $parts[] = 'مشاريعُ الدورِ المُسنَدةُ فقط';
        $co = hub_company_ids($user);
        if ($co !== null) $parts[] = 'شركاتٌ محدَّدة (' . count($co) . ')';
        $cl = hub_client_ids($user);
        if ($cl !== null) $parts[] = 'عملاءُ محدَّدون (' . count($cl) . ')';

        return $parts ? 'السجلّاتُ مقيَّدةٌ بـ' . implode(' · ', $parts) . ' — قائمةٌ فارغةٌ ليست منعاً'
            : 'كلُّ السجلّاتِ ضمنَ الصلاحيّة (لا قيدَ نطاقٍ إضافيّ)';
    }

    /** قيودُ الحقلِ للوحدة (ro/hide) — منفصلةٌ عن رؤيةِ الوحدة (§19) */
    protected static function fieldNote(User $user, string $module): string
    {
        $rules = $user->role?->field_rules[$module] ?? null;
        if (! is_array($rules) || ! $rules) return '';
        $ro = $hide = 0;
        foreach ($rules as $mode) { $mode === 'hide' ? $hide++ : ($mode === 'ro' ? $ro++ : null); }
        $bits = [];
        if ($hide) $bits[] = "$hide مخفيّ";
        if ($ro) $bits[] = "$ro للقراءة فقط";

        return $bits ? 'قيودُ حقلٍ: ' . implode(' · ', $bits) . ' (رؤيةُ الوحدةِ لا تتأثّر)' : '';
    }

    protected static function verdict(bool $allowed, string $state, string $reason, string $module, string $op, array $chain): array
    {
        return ['allowed' => $allowed, 'state' => $state, 'reason' => $reason,
                'module' => $module, 'op' => $op, 'chain' => $chain];
    }

    /**
     * **القرارُ الفعّالُ المجرَّد** (bool) — نفسُ منطقِ `explain` بلا سلسلةِ السبب. يعلو على
     * `hub_can` الخام لأنّه يطبّق حدَّ حساب العميل (`MODULE_ALLOW`) الذي يفرضه الوسيطُ لا المصفوفة —
     * فلا تُحسَب وحدةٌ داخليّةٌ «مرئيّةً» لعميلٍ لوّثت مصفوفتُه.
     */
    public static function allows(User $user, string $module, string $op = 'v'): bool
    {
        return self::explain($user, $module, $op)['allowed'];
    }

    /**
     * **المصفوفةُ الفعّالة لمستخدم** — كلُّ وحدةٍ إنسانيّةٍ × (v/a/e/d) بقيمها الفعّالة وسببِ منعِ
     * العرض، مجموعةً كمجموعاتِ التنقّل (§22/§92). تُحسَب من التهيئةِ والمصفوفة — بلا استعلامٍ لكلِّ وحدة.
     *
     * @return array<string, array{label:string, icon:string, rows:list<array{key:string,label:string,v:bool,a:bool,e:bool,d:bool,reason:string,state:string}>}>
     */
    public static function moduleMatrix(User $user): array
    {
        $groups = \App\Http\Controllers\Web\RoleController::groupedModules();
        $out = [];
        foreach ($groups as $gLabel => $g) {
            $rows = [];
            foreach ($g['items'] as $mk => $md) {
                $vExp = self::explain($user, $mk, 'v');
                $rows[] = [
                    'key' => $mk, 'label' => $md['label'] ?? $mk,
                    // القيمُ الفعّالة (تعلو على hub_can الخام بحدِّ حساب العميل)
                    'v' => $vExp['allowed'], 'a' => self::allows($user, $mk, 'a'),
                    'e' => self::allows($user, $mk, 'e'), 'd' => self::allows($user, $mk, 'd'),
                    'reason' => $vExp['reason'], 'state' => $vExp['state'],
                ];
            }
            if ($rows) $out[$gLabel] = ['label' => $gLabel, 'icon' => $g['icon'] ?? '📁', 'rows' => $rows];
        }

        return $out;
    }

    /**
     * **معاينةُ التنقّلِ الفعّال** (§60): الوحداتُ المرئيّةُ مقابلَ المخفيّة، ولكلِّ مخفيّةٍ سببُها.
     * تُحسَب من المصادر الحيّة لا من قائمةٍ مخزَّنة.
     *
     * @return array{visible:list<array{key:string,label:string}>, hidden:list<array{key:string,label:string,reason:string,state:string}>}
     */
    public static function navigation(User $user): array
    {
        $visible = $hidden = [];
        foreach (hub_modules() as $mk => $md) {
            if ($mk === 'users') continue;   // محكومةٌ بعلَمٍ لا مصفوفة
            $label = $md['label'] ?? $mk;
            $exp = self::explain($user, $mk, 'v');   // قرارٌ فعّالٌ (يطبّق حدَّ العميل)
            if ($exp['allowed']) {
                $visible[] = ['key' => $mk, 'label' => $label];
            } else {
                $hidden[] = ['key' => $mk, 'label' => $label, 'reason' => $exp['reason'], 'state' => $exp['state']];
            }
        }

        return ['visible' => $visible, 'hidden' => $hidden];
    }
}
