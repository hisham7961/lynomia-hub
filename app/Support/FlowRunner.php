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
                // مطابقة مطبَّعة كطبقة الأحداث الدلالية: status_to يُدخل نصاً حراً،
                // فتنويعة همزة/تاء مربوطة («منجزه» عن «منجزة») كانت تعطّل المسار بصمت
                if ($event === 'status' && trim((string) $flow->status_to) !== ''
                    && hub_ar_norm(trim((string) $flow->status_to)) !== hub_ar_norm(trim((string) $statusTo))) continue;
                if (! self::condPass($flow, $def, $m)) continue;

                $ok = 0;
                foreach ((array) $flow->actions as $a) {
                    // إجراء واحد لا يوقف البقية — لكن عطله يُبلَّغ لا يُبتلع
                    try { self::act($a + ['_flow' => (string) $flow->name], $def, $module, $m); $ok++; } catch (\Throwable $e) { report($e); }
                }
                // «آخر تشغيل: قبل دقيقة» لا تُكتب وكلُّ الإجراءات فشلت —
                // كانت الشاشة تُظهر مساراً معطوباً بمظهر السليم
                if ($ok) {
                    $flow->increment('runs');
                    $flow->forceFill(['last_run_at' => now()])->saveQuietly();
                }
            }
        } catch (\Throwable $e) {
            // المسارات لا تكسر العملية الأصلية أبداً — وعطلها يُبلَّغ كما تفعل HubAutomation
            report($e);
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
            $statusMatch = hub_ar_norm(trim((string) $flow->status_to)) === hub_ar_norm(trim((string) $statusTo));
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
        // حقلٌ اختفى من التعريف (أُعيدت تسميته، أو بُدّلت وحدة المسار): الشرط
        // لا يُقيَّم فلا يمرّ — المرورُ المفتوح كان يحوّل مساراً مشروطاً
        // بـ«المبلغ أكبر من ١٠٠٠» إلى مسارٍ يطلق على كل سجل بصمت
        if (! $field) return false;
        $v = $m->{$field['col']};
        if (is_array($v)) $v = implode('،', $v);
        $v = (string) $v;
        $want = (string) $f->cond_value;

        return match ($f->cond_op) {
            'has'   => $want !== '' && mb_stripos($v, $want) !== false,
            'gt'    => is_numeric($v) && is_numeric($want) && (float) $v > (float) $want,
            'lt'    => is_numeric($v) && is_numeric($want) && (float) $v < (float) $want,
            // تطبيع عربي كمطابقة الحالة — التنويعة الإملائية لا تعطّل الشرط
            default => hub_ar_norm(trim($v)) === hub_ar_norm(trim($want)),
        };
    }

    /* ── الإجراءات ── */
    protected static function act(array $a, array $def, string $module, Model $m): void
    {
        $text = self::tpl((string) ($a['text'] ?? ''), $def, $module, $m);

        switch ($a['type'] ?? '') {
            case 'notify':
                // «owners» = المعتمِدون الذين يرون هذا السجل (نطاقٌ لكلّ مستلم) —
                // فلا يُسرَّب اسمُ السجل عبر حدّ العزل من مسار عمل.
                $targets = ($a['to'] ?? 'owners') === 'owners'
                    ? hub_approvers_for($module, $m->id)
                    : [(string) $a['to']];
                foreach (array_unique($targets) as $uid) {
                    if (! $uid) continue;
                    HubNotification::create([
                        'user_id' => $uid, 'kind' => 'flow',
                        // نصُّ إجراء المسار يصل بلا حدٍّ من شاشة المسارات، والعمود
                        // ٦٠٠ — والفشل هنا **صامت** لأن act() ملفوفةٌ بـcatch
                        'text' => hub_fit($text ?: ('حدث في ' . $def['label']),
                            hub_col_max('notifications_hub', 'text') ?? 590),
                        'module' => $module, 'record_id' => $m->id,
                        'read' => false, 'created_at' => now(),
                    ]);
                }
                break;

            case 'tg':
                OutboxMessage::create([
                    'kind' => 'flow', 'channel' => 'tg', 'text' => hub_fit($text, hub_col_max('outbox', 'text') ?? 790),
                    'state' => 'queued', 'created_at' => now(),
                ]);
                break;

            case 'mail':
                OutboxMessage::create([
                    'kind' => 'flow', 'channel' => 'mail',
                    'target' => hub_fit((string) ($a['to_email'] ?? ''), hub_col_max('outbox', 'target') ?? 290),
                    'text' => hub_fit($text, hub_col_max('outbox', 'text') ?? 790),
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
                    $old = $m->{$field['col']};
                    $m->{$field['col']} = (string) ($a['value'] ?? '');
                    $m->saveQuietly();   // بلا إطلاق مسارات جديدة — حماية من الحلقات
                    // **لكن بأثرٍ تدقيقيّ** (v2.399): الحفظُ الصامت كان يغيّر سجلَّ أعمالٍ بلا قيدٍ
                    // يقول ماذا تغيّر ولماذا — فتاريخُ السجل يناقض حالتَه.
                    if ((string) $old !== (string) $m->{$field['col']} && method_exists($m, 'writeAudit')) {
                        try {
                            request()->merge(['_reason' => 'مسار عمل: ' . ($a['_flow'] ?? '')]);
                            $m->writeAudit('تعديل', [$field['col'] => $old], [$field['col'] => $m->{$field['col']}]);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
                break;
        }
    }

    /* ── القوالب ── */
    protected static function tpl(string $t, array $def, string $module, Model $m): string
    {
        if ($t === '') return '';

        // **تمريرةٌ واحدة على القالب الأصلي وحده.** كانت {_display}/{_by} تُستبدل
        // أولاً ثم يمرّ الناتج كله على حلّال رموز الحقول — فسجلٌّ سُمّي «{secret}»
        // أو مستخدمٌ اسمه «{salary}» يُحلّ رمزُه المحقون إلى قيمة الحقل الفعلية
        // ويخرج في إشعارٍ أو تلجرام أو بريد: تسريبٌ لا يحتاج صلاحية مالك.
        return preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)\}/u', function ($mm) use ($def, $module, $m) {
            switch ($mm[1]) {
                case '_display': return self::display($def, $module, $m);
                case '_module':  return $def['label'];
                case '_by':      return auth()->user()->name ?? 'النظام';
            }
            $f = collect($def['fields'])->firstWhere('key', $mm[1]);
            if (! $f) return $mm[0];
            // الحقول السرية والملفات لا تُحلّ قالباً: النموذج يستثنيها من القوائم
            // والكتابة ترفضها — وكان القالب وحده يسرّبها خامّةً خارج الخزنة
            if (in_array($f['type'] ?? '', ['sec', 'file', 'img'], true)) return $mm[0];
            $v = $m->{$f['col']};

            return is_array($v) ? implode('،', $v) : (string) $v;
        }, $t);
    }

    protected static function display(array $def, string $module, Model $m): string
    {
        return \Illuminate\Support\Str::limit((string) ($m->{hub_display_col($module)} ?? $m->id), 60);
    }
}
