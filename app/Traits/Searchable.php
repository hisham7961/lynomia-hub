<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

/** بحث نصي بسيط وموثوق (LIKE) على أعمدة الوحدة النصية من سجل الوحدات — متوافق مع MySQL */
trait Searchable
{
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term || mb_strlen(trim($term)) < 2) {
            return $q;
        }

        $t   = '%' . trim($term) . '%';
        $def = config('hub.modules.' . static::MODULE, []);

        $cols = collect($def['fields'] ?? [])
            ->whereIn('type', ['text', 'ta', 'url', 'sel', 'tags'])
            ->pluck('col')
            ->push(hub_display_col(static::MODULE))
            ->unique()
            ->values();

        return $q->where(function (Builder $qq) use ($cols, $t) {
            foreach ($cols as $c) {
                $qq->orWhere($c, 'LIKE', $t);
            }
        });
    }
}
