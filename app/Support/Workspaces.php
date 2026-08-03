<?php

namespace App\Support;

/**
 * مساحات العمل (CTO م2): قراءة config/hub_workspaces مدموجةً بوحدات مجموعتها
 * من hub_nav (مصدر الحقيقة الواحد) ومرشحةً بصلاحيات المستخدم — المساحة التي
 * لا يرى المستخدم أياً من وحداتها لا تظهر له أصلاً.
 */
class Workspaces
{
    /** كل المساحات المرئية للمستخدم: key => [label, icon, color, desc, modules[], centers[]] */
    public static function for($user): array
    {
        $navGroups = collect(config('hub_nav', []))->keyBy('g');
        $links = collect(hub_top_links($user))->keyBy('key');

        $out = [];
        foreach (config('hub_workspaces', []) as $key => $ws) {
            $items = $navGroups[$ws['nav']]['items'] ?? [];
            $visible = array_values(array_filter($items,
                fn ($mk) => hub_mod($mk) && hub_can($user, $mk, 'v')));
            if (! $visible) continue;

            $out[$key] = $ws + [
                'key' => $key,
                'modules' => $visible,
                'centerLinks' => collect($ws['centers'] ?? [])
                    ->map(fn ($ck) => $links[$ck] ?? null)->filter()->values()->all(),
            ];
        }

        return $out;
    }

    /** مساحة واحدة أو null — بصلاحيات المستخدم نفسها */
    public static function find(string $key, $user): ?array
    {
        return static::for($user)[$key] ?? null;
    }

    /**
     * عدّاد الانتباه لكل وحدة: كم سجلٍّ يستحق أو تأخّر أو يقارب الانتهاء —
     * من رادار الانتهاء القائم (hub_expiry) المنطَّق بصلاحية المستخدم وعزله،
     * فلا تُظهر الشارةُ انتباه شركةٍ لا يراها. أهمُّ من عدّ السجلات: يقول أين
     * يُنظر لا كم يوجد. يُخبَّأ مع الرادار نفسه (لا استعلامَ إضافياً).
     *
     * @return array<string,int> module => count
     */
    public static function attentionByModule($user = null, bool $fresh = false): array
    {
        $user = $user ?? auth()->user();
        $out = [];
        foreach (hub_expiry($fresh, $user) as $i) {
            $mk = (string) ($i['module'] ?? '');
            if ($mk === '') continue;
            $out[$mk] = ($out[$mk] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * مجموع الانتباه لكل مساحةٍ يراها المستخدم — لشارة الشريط الجانبي.
     *
     * @return array<string,int> workspaceKey => count
     */
    public static function attentionByWorkspace($user = null, bool $fresh = false): array
    {
        $user = $user ?? auth()->user();
        $byMod = static::attentionByModule($user, $fresh);
        $out = [];
        foreach (static::for($user) as $key => $ws) {
            $sum = 0;
            foreach ($ws['modules'] as $mk) $sum += $byMod[$mk] ?? 0;
            if ($sum) $out[$key] = $sum;
        }

        return $out;
    }
}
