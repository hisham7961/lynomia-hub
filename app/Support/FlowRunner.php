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
    /**
     * نقطة النداء التاريخية من المتحكمات — صارت تُفوِّض للناقل.
     * الناقل يُنادي الويبهوكس ثم المسارات بالترتيب نفسه، فالسلوك القائم لم يتغيّر،
     * ويُضاف فوقه بثُّ الأحداث الدلالية.
     */
    public static function fire(string $event, string $module, Model $m, ?string $statusTo = null): void
    {
        HubEvents::dispatch($event, $module, $m, $statusTo);
    }

    /** تنفيذ المسارات المطابقة — مشتركٌ في الناقل، لا يُنادى مباشرة */
    public static function run(string $event, string $module, Model $m, ?string $statusTo = null): void
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

    /**
     * تجربة جافة: يقيّم المسار على سجل حقيقي ويصف ما **سيحدث** دون تنفيذ أي
     * إجراء — لا إشعار يُرسل ولا مهمة تُنشأ ولا حقل يُكتب. أمان تكامل n8n:
     * ترى أثر المسار قبل أن تفعّله على بياناتك.
     */
    public static function simulate(Flow $flow, string $module, Model $m, ?string $statusTo = null): array
    {
        $def = hub_mod($module);
        if (! $def) return ['ok' => false, 'why' => 'وحدة غير معروفة', 'actions' => []];

        // مطابقة الحالة (الحدث مضمون: المسار مُختار لحدثه في الواجهة)
        $statusMatch = true; $statusWhy = '';
        if ($flow->event === 'status' && trim((string) $flow->status_to) !== '') {
            $statusMatch = trim((string) $flow->status_to) === trim((string) $statusTo);
            $statusWhy = "الحالة المطلوبة «{$flow->status_to}»، والمُختبَرة «" . ($statusTo ?: '—') . '»';
        }

        // الشرط
        $condPass = self::condPass($flow, $def, $m);
        $condWhy = '';
        if ($flow->cond_field) {
            $field = collect($def['fields'])->firstWhere('key', $flow->cond_field);
            $cur = $field ? $m->{$field['col']} : null;
            $cur = is_array($cur) ? implode('،', $cur) : (string) $cur;
            $ops = ['eq' => 'يساوي', 'has' => 'يحوي', 'gt' => 'أكبر من', 'lt' => 'أصغر من'];
            $condWhy = ($field['label'] ?? $flow->cond_field) . " («{$cur}») "
                . ($ops[$flow->cond_op] ?? '=') . " «{$flow->cond_value}»";
        }

        $wouldRun = $statusMatch && $condPass;

        // وصف الإجراءات بقوالبها محلولةً — بلا تنفيذ
        $actions = [];
        foreach ((array) $flow->actions as $a) {
            $actions[] = self::describe($a, $def, $module, $m);
        }

        return [
            'ok' => $wouldRun,
            'statusMatch' => $statusMatch, 'statusWhy' => $statusWhy,
            'condPass' => $condPass, 'condWhy' => $condWhy,
            'actions' => $actions,
            'record' => self::display($def, $module, $m),
        ];
    }

    /** وصف إجراء واحد بقالبه محلولاً — انعكاس act() بلا آثار جانبية */
    protected static function describe(array $a, array $def, string $module, Model $m): array
    {
        $text = self::tpl((string) ($a['text'] ?? ''), $def, $module, $m);
        $type = $a['type'] ?? '';

        return match ($type) {
            'notify' => ['icon' => '🔔', 'label' => 'إشعار داخلي',
                'detail' => (($a['to'] ?? 'owners') === 'owners' ? 'إلى المالكين' : 'إلى مستخدم محدد')
                    . ': ' . ($text ?: 'حدث في ' . $def['label'])],
            'tg' => ['icon' => '📨', 'label' => 'رسالة تلجرام', 'detail' => $text ?: '—'],
            'mail' => ['icon' => '✉️', 'label' => 'بريد إلكتروني',
                'detail' => 'إلى ' . ($a['to_email'] ?? '—') . ': ' . ($text ?: '—')],
            'task' => ['icon' => '✅', 'label' => 'إنشاء مهمة',
                'detail' => $text ?: ('متابعة: ' . self::display($def, $module, $m))],
            'set' => ['icon' => '✏️', 'label' => 'تعيين حقل',
                'detail' => (collect($def['fields'])->firstWhere('key', $a['field'] ?? '')['label'] ?? ($a['field'] ?? '—'))
                    . ' ← «' . ($a['value'] ?? '') . '»'],
            default => ['icon' => '❓', 'label' => 'إجراء غير معروف', 'detail' => $type],
        };
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
