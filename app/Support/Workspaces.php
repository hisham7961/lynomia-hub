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
}
