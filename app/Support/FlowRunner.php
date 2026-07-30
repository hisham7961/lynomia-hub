<?php

namespace App\Support;

use App\Models\Flow;
use App\Models\HubNotification;
use App\Models\OutboxMessage;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * محرك مسارات العمل: يُستدعى عند إنشاء/تعديل/تغيير حالة أي سجل،
 * يطابق المسارات المفعلة وشرطها ثم ينفذ إجراءاتها.
 * القوالب: {مفتاح_الحقل} و{_display} و{_module} و{_by}
 */
class FlowRunner
{
    /** @param string $event created|updated|status */
    public static function fire(string $event, string $module, Model $m, ?string $statusTo = null): void
    {
        try {
            $flows = Flow::where('enabled', true)->where('module', $module)->where('event', $event)->get();
            if ($flows->isEmpty()) return;

            $def = hub_mod($module);
            if (! $def) return;

            foreach ($flows as $flow) {
                if ($event === 'status' && trim((string) $flow->status_to) !== ''
                    && trim((string) $flow->status_to) !== trim((string) $statusTo)) continue;
                if (! self::condPass($flow, $def, $m)) continue;

                foreach ((array) $flow->actions as $a) {
                    try { self::act($a, $def, $module, $m); } catch (\Throwable $e) { /* إجراء واحد لا يوقف البقية */ }
                }
                $flow->increment('runs');
                $flow->forceFill(['last_run_at' => now()])->saveQuietly();
            }
        } catch (\Throwable $e) {
            // المسارات لا تكسر العملية الأصلية أبداً
        }
    }

    /* ── الشرط ── */
    protected static function condPass(Flow $f, array $def, Model $m): bool
    {
        if (! $f->cond_field) return true;
        $field = collect($def['fields'])->firstWhere('key', $f->cond_field);
        if (! $field) return true;
        $v = $m->{$field['col']};
        if (is_array($v)) $v = implode('،', $v);
        $v = (string) $v;
        $want = (string) $f->cond_value;

        return match ($f->cond_op) {
            'has'   => $want !== '' && mb_stripos($v, $want) !== false,
            'gt'    => is_numeric($v) && is_numeric($want) && (float) $v > (float) $want,
            'lt'    => is_numeric($v) && is_numeric($want) && (float) $v < (float) $want,
            default => trim($v) === trim($want),
        };
    }

    /* ── الإجراءات ── */
    protected static function act(array $a, array $def, string $module, Model $m): void
    {
        $text = self::tpl((string) ($a['text'] ?? ''), $def, $module, $m);

        switch ($a['type'] ?? '') {
            case 'notify':
                $targets = ($a['to'] ?? 'owners') === 'owners'
                    ? hub_approvers()
                    : [(string) $a['to']];
                foreach (array_unique($targets) as $uid) {
                    if (! $uid) continue;
                    HubNotification::create([
                        'user_id' => $uid, 'kind' => 'flow',
                        'text' => $text ?: ('حدث في ' . $def['label']),
                        'module' => $module, 'record_id' => $m->id,
                        'read' => false, 'created_at' => now(),
                    ]);
                }
                break;

            case 'tg':
                OutboxMessage::create([
                    'kind' => 'flow', 'channel' => 'tg', 'text' => mb_substr($text, 0, 790),
                    'state' => 'queued', 'created_at' => now(),
                ]);
                break;

            case 'mail':
                OutboxMessage::create([
                    'kind' => 'flow', 'channel' => 'mail',
                    'target' => (string) ($a['to_email'] ?? ''), 'text' => mb_substr($text, 0, 790),
                    'state' => 'queued', 'created_at' => now(),
                ]);
                break;

            case 'task':
                Task::create([
                    'title' => mb_substr($text ?: ('متابعة: ' . self::display($def, $module, $m)), 0, 290),
                    'project_id' => $m->project_id ?? null,
                    'assignee_id' => $a['assignee'] ?? null,
                    'status' => 'جديدة',
                    'description' => 'أُنشئت آلياً بمسار عمل من ' . $def['label'] . ': ' . self::display($def, $module, $m),
                ]);
                break;

            case 'set':
                $field = collect($def['fields'])->firstWhere('key', $a['field'] ?? '');
                if ($field && ! in_array($field['type'], ['file', 'img', 'sec'], true)) {
                    $m->{$field['col']} = (string) ($a['value'] ?? '');
                    $m->saveQuietly();   // بلا إطلاق مسارات جديدة — حماية من الحلقات
                }
                break;
        }
    }

    /* ── القوالب ── */
    protected static function tpl(string $t, array $def, string $module, Model $m): string
    {
        if ($t === '') return '';
        $t = str_replace(['{_display}', '{_module}', '{_by}'],
            [self::display($def, $module, $m), $def['label'], auth()->user()->name ?? 'النظام'], $t);

        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/u', function ($mm) use ($def, $m) {
            $f = collect($def['fields'])->firstWhere('key', $mm[1]);
            if (! $f) return $mm[0];
            $v = $m->{$f['col']};

            return is_array($v) ? implode('،', $v) : (string) $v;
        }, $t);
    }

    protected static function display(array $def, string $module, Model $m): string
    {
        return \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? $m->id), 60);
    }
}
