<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الابتكار — الفكرةُ **أثرٌ في ملف صاحبها** لا سطرٌ في قائمة.
 *
 * المركز كان يرتّب الأفكار بدرجة ICE ثم يقف: لا يُعرف من يقترح ولا كم رُقّيت
 * له فكرة، ولا تظهر أفكار الموظف في ملفه، ولا يُحتسب الاقتراح إسهاماً. فمن
 * يفكّر لا يُرى، ومن لا يفكّر لا يُفتقَد.
 */
class Innovation
{
    /** درجة إسهام كل مقترِح — عددٌ وجودةٌ وما وصل إلى التنفيذ */
    public static function contributors(int $limit = 12): array
    {
        // القائمةُ منطَّقة والملخّصُ فوقها كان يقرأ الجدول خاماً: عددٌ واسمُ
        // مقترِحٍ من شركةٍ لا يراها القارئ. `hub_read` تحرس الوجودَ والصلاحيةَ
        // والنطاقَ والحذفَ الناعم دفعةً واحدة.
        $q = hub_read('ideas');
        if (! $q) return [];

        $rows = $q->whereNotNull('by_id')
            ->get(['by_id', 'impact', 'confidence', 'ease', 'project_id', 'status', 'created_at']);
        if ($rows->isEmpty()) return [];

        $names = DB::table('users')->whereNull('deleted_at')
            ->whereIn('id', $rows->pluck('by_id')->filter()->unique()->all())->pluck('name', 'id');

        return $rows->groupBy('by_id')->map(function ($g, $uid) use ($names) {
            $scored = $g->filter(fn ($i) => $i->impact && $i->confidence && $i->ease);
            $ice = $scored->count()
                ? (int) round($scored->avg(fn ($i) => (int) $i->impact * (int) $i->confidence * (int) $i->ease))
                : null;
            $promoted = $g->filter(fn ($i) => (string) $i->project_id !== '')->count();
            $recent = $g->filter(fn ($i) => $i->created_at && \Illuminate\Support\Carbon::parse($i->created_at)->gt(now()->subDays(90)))->count();

            return [
                'id' => $uid, 'name' => $names[$uid] ?? '—',
                'n' => $g->count(), 'ice' => $ice, 'promoted' => $promoted, 'recent' => $recent,
                // الإسهام: كثرةٌ وجودةٌ ووصولٌ للتنفيذ — والتنفيذ أثقلها وزناً
                'score' => $g->count() + ($promoted * 3) + ($ice ? (int) round($ice / 100) : 0),
            ];
        })->sortByDesc('score')->take($limit)->values()->all();
    }

    /** أفكار شخصٍ بعينه — لتظهر في ملفه لا في قائمةٍ عامة وحدها */
    public static function byPerson(?string $userId, int $limit = 12): array
    {
        if (! $userId) return [];
        $q = hub_read('ideas');
        if (! $q) return [];

        return $q->where('by_id', $userId)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'title', 'cat', 'impact', 'confidence', 'ease', 'project_id', 'status', 'created_at'])
            ->map(fn ($i) => [
                'id' => $i->id, 'title' => $i->title, 'cat' => $i->cat, 'status' => $i->status,
                'ice' => ($i->impact && $i->confidence && $i->ease)
                    ? (int) $i->impact * (int) $i->confidence * (int) $i->ease : null,
                'promoted' => (string) $i->project_id !== '',
                'at' => $i->created_at,
            ])->all();
    }

    /** عدد مرفقات كل فكرة — الصور والملفات تُرفع عبر لوحة المرفقات العامة */
    public static function attachmentCounts(array $ideaIds): array
    {
        if (! $ideaIds || ! Schema::hasTable('attachments')) return [];

        return DB::table('attachments')->where('module', 'ideas')
            ->whereIn('record_id', $ideaIds)
            ->groupBy('record_id')->pluck(DB::raw('COUNT(*)'), 'record_id')->all();
    }

    /** نبض المركز */
    public static function pulse(): array
    {
        $q = hub_read('ideas');
        if (! $q) return ['n' => 0, 'scored' => 0, 'promoted' => 0, 'people' => 0, 'q90' => 0];

        $rows = $q->get(['by_id', 'impact', 'confidence', 'ease', 'project_id', 'created_at']);

        return [
            'n' => $rows->count(),
            'scored' => $rows->filter(fn ($i) => $i->impact && $i->confidence && $i->ease)->count(),
            'promoted' => $rows->filter(fn ($i) => (string) $i->project_id !== '')->count(),
            'people' => $rows->pluck('by_id')->filter()->unique()->count(),
            'q90' => $rows->filter(fn ($i) => $i->created_at
                && \Illuminate\Support\Carbon::parse($i->created_at)->gt(now()->subDays(90)))->count(),
        ];
    }
}
