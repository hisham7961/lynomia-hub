<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * الإقرار الموثَّق على أي سجل — قراءةٌ وكتابةٌ واحدة لكل الوحدات.
 *
 * السكّة نفسها التي تمشي عليها المرفقات والتعليقات: `(module, record_id)`.
 * ومن يجب أن يُقرّ مقروءٌ من السجل نفسه — حاضرو الاجتماع، وحائزُ العهدة،
 * ومنفّذُ القرار — فلا قائمةَ مستقلّةٌ تتخلّف عن الحقيقة.
 */
class Acks
{
    public static function registry(): array
    {
        return (array) config('hub_acks', []);
    }

    public static function def(string $module): ?array
    {
        return self::registry()[$module] ?? null;
    }

    public static function enabled(string $module): bool
    {
        return self::def($module) !== null && Schema::hasTable('record_acks');
    }

    /** معرّفات من يجب أن يُقرّوا هذا السجل — من السجل نفسه */
    public static function targets(string $module, $row): array
    {
        $def = self::def($module);
        if (! $def || ! $row) return [];

        $col = $def['who']['col'];
        $raw = data_get($row, $col);

        if ($def['who']['type'] === 'one') {
            return $raw ? [(string) $raw] : [];
        }

        // قائمة: قد تصل مصفوفةً (Eloquent cast) أو نصّاً JSON (استعلام خام)
        $list = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);

        return array_values(array_filter(array_map('strval', (array) $list)));
    }

    /** نسخة السجل التي يُقاس عليها الإقرار */
    public static function version($row): int
    {
        return (int) (data_get($row, 'version') ?: 1);
    }

    /**
     * حالة الإقرار للسجل كاملاً: من أقرّ ومن لم يُقرّ، وأيُّ إقرارٍ تقادم.
     * يعيد `null` للوحدات التي لا إقرار عليها — فالشاشة لا تعرض ما لا معنى له.
     */
    public static function state(string $module, $row): ?array
    {
        $def = self::def($module);
        if (! $def || ! Schema::hasTable('record_acks') || ! $row) return null;

        $targets = self::targets($module, $row);
        $ver = self::version($row);

        $rows = DB::table('record_acks')->where('module', $module)->where('record_id', $row->id)
            ->orderByDesc('ack_at')->get(['user_id', 'ver', 'ack_at', 'ip', 'device', 'note']);

        $names = Schema::hasTable('users')
            ? DB::table('users')->whereIn('id', $targets ?: ['-'])->pluck('name', 'id')
            : collect();

        $mine = [];
        foreach ($rows as $r) $mine[$r->user_id] ??= $r;      // أحدثُ إقرارٍ لكل شخص

        $people = [];
        foreach ($targets as $uid) {
            $a = $mine[$uid] ?? null;
            $stale = $a && $def['reack'] && (int) $a->ver !== $ver;
            $people[] = [
                'id' => $uid, 'name' => $names[$uid] ?? 'مستخدم محذوف',
                'acked' => (bool) $a && ! $stale, 'stale' => $stale,
                'at' => $a?->ack_at, 'ver' => $a?->ver,
                'ip' => $a?->ip, 'device' => $a?->device, 'note' => $a?->note,
            ];
        }

        $done = collect($people)->where('acked', true)->count();

        return [
            'def' => $def, 'ver' => $ver, 'people' => $people,
            'done' => $done, 'total' => count($people),
            'pct' => count($people) ? (int) round($done / count($people) * 100) : 0,
            // إقرارات من لم يعد مطلوباً منه (خرج من الحضور مثلاً) — تُحفظ ولا تُعرض كنقص
            'extra' => collect($mine)->keys()->diff($targets)->count(),
        ];
    }

    /** هل ينتظر هذا السجلُّ إقرارَ فلان؟ */
    public static function pendingFor(string $module, $row, string $userId): bool
    {
        $st = self::state($module, $row);
        if (! $st) return false;

        $me = collect($st['people'])->firstWhere('id', $userId);

        return $me !== null && ! $me['acked'];
    }

    /** تسجيل الإقرار بدليله — الوقت والعنوان والجهاز */
    public static function record(string $module, $row, string $userId, ?string $note = null): void
    {
        DB::table('record_acks')->updateOrInsert(
            ['module' => $module, 'record_id' => $row->id, 'user_id' => $userId, 'ver' => self::version($row)],
            ['id' => (string) Str::uuid(), 'ack_at' => now(),
             'ip' => substr((string) request()->ip(), 0, 60),
             'device' => substr((string) request()->userAgent(), 0, 200),
             'note' => $note ?: null, 'created_at' => now()],
        );
    }

    /**
     * كل ما ينتظر إقرار مستخدم — للصندوق الموحّد.
     * يقرأ الوحدات المسجَّلة وحدها، وكلٌّ خلف صلاحيتها ونطاقها.
     */
    public static function pending($user): array
    {
        if (! Schema::hasTable('record_acks') || ! $user) return [];

        $out = [];
        foreach (self::registry() as $module => $def) {
            $md = hub_mod($module);
            if (! $md || ! Schema::hasTable($md['table']) || ! hub_can($user, $module, 'v')) continue;

            $col = $def['who']['col'];
            if (! Schema::hasColumn($md['table'], $col)) continue;

            $q = hub_scope(DB::table($md['table'])->whereNull('deleted_at'), $module, $user);
            $q = $def['who']['type'] === 'one'
                ? $q->where($col, $user->id)
                : $q->where($col, 'LIKE', '%"' . $user->id . '"%');

            $display = $md['display'] ?? 'title';
            foreach ($q->orderByDesc('created_at')->limit(30)->get() as $row) {
                if (! self::pendingFor($module, $row, (string) $user->id)) continue;
                $out[] = [
                    'module' => $module, 'id' => $row->id,
                    'title' => (string) ($row->{$display} ?? $md['label']),
                    'label' => $def['label'], 'why' => $def['why'],
                ];
            }
        }

        return $out;
    }
}
