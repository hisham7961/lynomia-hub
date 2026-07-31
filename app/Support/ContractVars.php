<?php

namespace App\Support;

use App\Models\SignRequest;

/**
 * سجل متغيرات العقود (CLM م3): كل متغير باسمٍ واضح ووصفٍ ومصدرٍ ومثال —
 * لا رموز تقنية عارية يحفظها المستخدم. المصادر تُحَلّ تلقائياً من السجلات
 * المربوطة (عقد/عميل/شركة/موظف/مورد/مشروع) ومن النظام، وقيم المستخدم تعلو
 * دائماً على المحلول تلقائياً.
 *
 * العقد الصارم: الإلزامي الفارغ يمنع الإنشاء، والاختياري الفارغ يُعلَّم
 * ⟦هكذا⟧ ظاهراً لا قوسين خاويين يتسللان لوثيقة ملزمة.
 */
class ContractVars
{
    /** المجموعات المعيارية — تُمدّد بمتغيرات مخصصة من setting('esign.custom_vars') */
    public static function registry(): array
    {
        $groups = [
            '📄 العقد' => [
                ['k' => 'رقم_العقد', 'label' => 'رقم العقد', 'src' => 'contract.doc_no', 'type' => 'text', 'ex' => 'CTR-2026-0001', 'desc' => 'الرقم الرسمي المولد للعقد المربوط'],
                ['k' => 'المبلغ', 'label' => 'قيمة العقد', 'src' => 'contract.value', 'type' => 'money', 'ex' => '12,000', 'desc' => 'قيمة العقد المربوط'],
                ['k' => 'العملة', 'label' => 'العملة', 'src' => 'contract.currency', 'type' => 'text', 'ex' => 'د.ك'],
                ['k' => 'تاريخ_البداية', 'label' => 'تاريخ البداية', 'src' => 'contract.date_start', 'type' => 'date', 'ex' => '2026-08-01'],
                ['k' => 'تاريخ_النهاية', 'label' => 'تاريخ النهاية', 'src' => 'contract.date_end', 'type' => 'date', 'ex' => '2027-07-31'],
            ],
            '🏢 شركتنا' => [
                ['k' => 'اسم_شركتنا', 'label' => 'اسم شركتنا', 'src' => 'setting.app.name', 'type' => 'text', 'ex' => 'لينوميا', 'req' => true, 'desc' => 'من إعدادات المنصة'],
                ['k' => 'ممثل_شركتنا', 'label' => 'ممثل شركتنا', 'src' => 'user.name', 'type' => 'text', 'ex' => 'المدير العام', 'req' => true, 'desc' => 'منشئ الطلب ما لم يُغيَّر'],
                ['k' => 'صفة_الممثل', 'label' => 'صفة الممثل', 'src' => null, 'type' => 'text', 'ex' => 'المدير التنفيذي'],
            ],
            '🤝 الطرف الثاني' => [
                ['k' => 'اسم_الطرف_الثاني', 'label' => 'اسم الطرف الثاني', 'src' => 'party.name', 'type' => 'text', 'ex' => 'شركة الأفق', 'req' => true, 'desc' => 'يُحل من العميل/المورد/الموظف المربوط'],
                ['k' => 'هوية_الطرف_الثاني', 'label' => 'هوية/سجل الطرف الثاني', 'src' => 'party.id_no', 'type' => 'text', 'ex' => '123456789'],
                ['k' => 'بريد_الطرف_الثاني', 'label' => 'بريد الطرف الثاني', 'src' => 'party.email', 'type' => 'text', 'ex' => 'client@example.com'],
                ['k' => 'هاتف_الطرف_الثاني', 'label' => 'هاتف الطرف الثاني', 'src' => 'party.phone', 'type' => 'text', 'ex' => '+96550000000'],
            ],
            '🚀 المشروع' => [
                ['k' => 'اسم_المشروع', 'label' => 'اسم المشروع', 'src' => 'project.name', 'type' => 'text', 'ex' => 'تطبيق التوصيل'],
            ],
            '⚙️ النظام' => [
                ['k' => 'التاريخ', 'label' => 'تاريخ اليوم', 'src' => 'system.date', 'type' => 'date', 'ex' => now()->toDateString(), 'desc' => 'يُملأ تلقائياً بتاريخ الإنشاء'],
            ],
        ];

        foreach ((array) (setting('esign.custom_vars') ?: []) as $cv) {
            if (! empty($cv['k'])) {
                $groups['🧩 مخصصة'][] = $cv + ['label' => $cv['label'] ?? $cv['k'], 'type' => $cv['type'] ?? 'text', 'src' => null];
            }
        }

        return $groups;
    }

    /** فهرس مسطح: اسم المتغير ← تعريفه */
    public static function flat(): array
    {
        $out = [];
        foreach (self::registry() as $vars) {
            foreach ($vars as $v) $out[$v['k']] = $v;
        }

        return $out;
    }

    /**
     * الحل التلقائي من السياق: العقد المربوط، جهة الربط (عميل/مورد/موظف…)،
     * والنظام. تُعاد [اسم_المتغير => القيمة] لما أمكن حله فقط.
     */
    public static function resolve(?string $contractId, ?string $linkModule, ?string $linkId): array
    {
        $vals = ['التاريخ' => now()->toDateString()];
        try {
            if ($n = (setting('app.name') ?: config('app.name'))) $vals['اسم_شركتنا'] = (string) $n;
            if (auth()->check()) $vals['ممثل_شركتنا'] = (string) auth()->user()->name;

            $contract = $contractId ? \App\Models\Contract::find($contractId) : null;
            if ($contract) {
                foreach ([
                    'رقم_العقد' => $contract->doc_no,
                    'المبلغ' => $contract->value !== null ? rtrim(rtrim(number_format((float) $contract->value, 3, '.', ','), '0'), '.') : null,
                    'العملة' => $contract->currency,
                    'تاريخ_البداية' => $contract->date_start?->toDateString(),
                    'تاريخ_النهاية' => $contract->date_end?->toDateString(),
                ] as $k => $v) {
                    if ($v !== null && $v !== '') $vals[$k] = (string) $v;
                }
            }

            // الطرف الثاني: جهة الربط أولاً، ثم عميل العقد
            $party = null;
            if ($linkModule && $linkId && ($def = hub_mod($linkModule))) {
                $cls = '\\App\\Models\\' . $def['model'];
                if (class_exists($cls)) $party = $cls::find($linkId);
            }
            if (! $party && $contract && $contract->client_id) {
                $party = \App\Models\Client::find($contract->client_id);
            }
            if ($party) {
                $name = $party->{hub_display_col($linkModule ?: 'clients')} ?? $party->name ?? null;
                foreach ([
                    'اسم_الطرف_الثاني' => $name,
                    'هوية_الطرف_الثاني' => $party->civil_no ?? $party->id_no ?? $party->registration_no ?? null,
                    'بريد_الطرف_الثاني' => $party->email ?? null,
                    'هاتف_الطرف_الثاني' => $party->phone ?? null,
                ] as $k => $v) {
                    if ($v) $vals[$k] = (string) $v;
                }
            }

            $projectId = $contract?->project_id;
            if ($projectId && ($proj = \App\Models\Project::find($projectId))) {
                $vals['اسم_المشروع'] = (string) $proj->name;
            }
        } catch (\Throwable $e) {
            report($e);   // الحل التلقائي إثراء — فشله لا يمنع الإنشاء
        }

        return $vals;
    }

    /** المتغيرات الإلزامية الناقصة في نصٍّ بعد التطبيق */
    public static function missing(string $body, array $vals): array
    {
        preg_match_all('/\{([^{}\s]{1,40})\}/u', $body, $m);
        $flat = self::flat();
        $missing = [];
        foreach (array_unique($m[1]) as $k) {
            if (($vals[$k] ?? '') !== '') continue;
            if (! empty($flat[$k]['req'])) $missing[] = $flat[$k]['label'] ?? $k;
        }

        return $missing;
    }

    /**
     * التطبيق: القيم تُستبدل، والاختياري الفارغ يُعلَّم ⟦اسم المتغير⟧ ظاهراً —
     * لا أقواس {خام} تتسلل للوثيقة الملزمة بصمت.
     */
    public static function apply(string $body, array $vals): string
    {
        $flat = self::flat();

        return (string) preg_replace_callback('/\{([^{}\s]{1,40})\}/u', function ($m) use ($vals, $flat) {
            $k = $m[1];
            $v = (string) ($vals[$k] ?? '');
            if ($v !== '') return $v;

            return '⟦' . ($flat[$k]['label'] ?? str_replace('_', ' ', $k)) . '⟧';
        }, $body);
    }
}
