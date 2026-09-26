<?php

namespace App\Support\Ai\Assist;

use App\Models\AiProfile;
use App\Models\User;
use App\Support\Ai\Ask\AskContext;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Ask\AskTools;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Platform\Redactor;
use Illuminate\Support\Str;

/**
 * **المساعدُ التنفيذيّ — «مسودةٌ ثمّ تأكيد»** (المرحلة ٣ · `docs/ai-hub/46-ai-roadmap.md` §٥).
 *
 * النموذجُ **يقترح ولا يكتب**: يُخرج مقترحاً مُهيكَلاً (`{fields}`) من سجلٍّ يراه السائل، والخادمُ:
 *  ① **يقرأ المصدرَ بعين السائل** — `AskTools::run('hub_record')`: النطاقُ والحقولُ المرئيّةُ وحدَها
 *     (ما لا يراه لا يصل النموذج)، منقّحاً من الأسرار ومقصوصاً داخل سياج.
 *  ② **يُصفّي المقترح بقائمةٍ بيضاءَ لكلِّ نوع** — حقولٌ نصّيّةٌ وتاريخٌ وخياراتٌ من قائمتها فقط،
 *     لا مراجعَ يخترعها النموذج (المشروعُ والتذكرةُ والاجتماعُ يُنقَل من المصدر نفسِه لا من النموذج)،
 *     ولا حقلٌ محجوبٌ أو للقراءة فقط عند السائل.
 *  ③ **يفتح نموذجَ الإنشاء القائمَ معبّأً** (`m.create?…`) — والحفظُ فعلُ السائل عبر `ModuleController`
 *     بقواعد الوحدة وموافقاتها وتدقيقها. **لا يُكتَب سجلٌّ هنا، ولا يُرسَل شيء.**
 *
 * وكلُّ نداءٍ عبر `GovernedCompletion` (السياسة · الميزانيّة · سجلُّ الاستهلاك، feature=assist).
 */
final class DraftAssistant
{
    /** الأنواع: المصدرُ (`*` = أيُّ وحدةٍ يراها) · الهدف · الحقولُ التي يُسمح للنموذج باقتراحها · الروابطُ من المصدر */
    public const KINDS = [
        'task' => [
            'label' => 'مهمّةٌ من هذا السجلّ', 'icon' => '✅', 'source' => '*', 'target' => 'tasks',
            'fields' => ['title' => 'text', 'desc' => 'ta', 'priority' => 'sel', 'due' => 'date', 'estH' => 'num'],
            // حقلُ الهدف ⇐ حقلُ المصدر (أو معرّفُ المصدر نفسِه `@id`) — بلا نموذج
            // والمشروعُ من عمود المصدر، **أو هو المصدرُ نفسُه** حين تُقترح المهمّةُ من صفحة مشروع
            'carry' => ['projectId' => ['projectId', '@id:projects'], 'ticketId' => '@id:tickets', 'decisionId' => '@id:decisions'],
            'many' => false,
        ],
        'decisions' => [
            'label' => 'قراراتٌ من محضر الاجتماع', 'icon' => '⚖️', 'source' => 'meetings', 'target' => 'decisions',
            'fields' => ['title' => 'text', 'due' => 'date', 'reason' => 'ta'],
            'carry' => ['meetingId' => '@id:meetings', 'projectId' => 'projectId', 'clientId' => 'clientId'],
            'many' => true,
        ],
        'reply' => [
            'label' => 'مسودةُ ردٍّ على التذكرة', 'icon' => '✉️', 'source' => 'tickets', 'target' => null,
            'fields' => [], 'carry' => [], 'many' => false,
        ],
        // **تصنيفُ التذكرة وأولويّتُها** — على السجلّ نفسِه: يفتح نموذجَ *التعديل* باقتراحٍ مُسمّى (`?suggest=1`)
        // لحقلَي اختيارٍ فقط؛ والحفظُ فعلُ المحرِّر بقواعد التحديث. (الإسنادُ مرجعٌ — لا يقترحه النموذج)
        'triage' => [
            'label' => 'صنّف التذكرة ورتّب أولويّتَها', 'icon' => '🏷️', 'source' => 'tickets', 'target' => 'tickets', 'mode' => 'edit',
            'fields' => ['cat' => 'sel', 'priority' => 'sel'], 'carry' => [], 'many' => false,
        ],
        // (المرحلة ٥ · مساعدُ التطوير) ملاحظاتُ إصدارٍ من الالتزامات: النظامُ لا يخزّن الالتزامات، فالسجلُّ
        // **يُلصَق** (`git log --oneline` للنطاق)، ويُضاف ما يراه السائلُ من مهامَّ أُنجزت ومشاكلَ حُلّت في
        // مشروع الإصدار منذ الإصدار السابق. والناتجُ نصٌّ يُنسخ إلى حقل الملاحظات — لا يُكتب شيء.
        'notes' => [
            'label' => 'ملاحظاتُ الإصدار', 'icon' => '📝', 'source' => 'code', 'target' => null,
            'fields' => [], 'carry' => [], 'many' => false,
            'input' => ['name' => 'log', 'label' => 'سجلُّ الالتزامات (اختياريّ) — ألصق ناتجَ git log --oneline، أو اتركه فارغاً ليُجلَب من GitHub إن كان للإصدار مستودعٌ والتزام', 'max' => 8000],
        ],
    ];

    /** أقصى ما يُذكر من مهامِّ الإصدار ومشاكله — عناوينُ لا سجلّات */
    public const RELEASE_ITEMS = 40;

    /** سقفُ مخرجات النداء — مقترحٌ لا مقالة */
    public const MAX_OUTPUT = 900;

    /** أطولُ حقلٍ من المصدر يبلغ النموذج — المحضرُ كاملاً لا أوّلُ ٣٠٠ حرفٍ منه */
    public const SOURCE_MAX_VALUE = 4000;

    /** سقفُ نصِّ المصدر كلِّه داخل السياج */
    public const SOURCE_MAX_TOTAL = 12000;

    /** أطولُ قيمةٍ تُحمَل في رابط التعبئة — والتحقّقُ الكاملُ عند الحفظ */
    public const MAX_VALUE = 1200;

    public static function enabled(): bool
    {
        return (string) setting('assist.enabled', '1') === '1';
    }

    public static function profileKey(): string
    {
        $k = trim((string) setting('assist.profile', 'general'));

        return $k === '' ? AskPolicy::PROFILE : $k;
    }

    public static function profile(): ?AiProfile
    {
        $p = AiProfile::query()->where('key', self::profileKey())->where('enabled', true)->orderBy('id')->first();

        return $p !== null && AiProfiles::chain($p, AiPurposes::ASSIST)->isNotEmpty() ? $p : null;
    }

    /** **شروطُ «اسأل Hub» نفسُها** (رايةُ المساعد · بوّابةٌ مفحوصة · توليدٌ مُثبَت) ومفتاحُه وغرضُه */
    public static function ready(?User $u): bool
    {
        return $u !== null && ! hub_is_client($u) && self::enabled() && AskPolicy::ready($u) && self::profile() !== null;
    }

    /** الأنواعُ المتاحةُ لهذا المستخدم على هذه الوحدة — بلا نداءٍ ولا قراءةِ سجلّ @return array<string, array> */
    public static function kindsFor(?User $u, string $module): array
    {
        if (! self::ready($u) || ! hub_can($u, $module, 'v')) return [];
        $out = [];
        foreach (self::KINDS as $k => $def) {
            if ($def['source'] !== '*' && $def['source'] !== $module) continue;
            if (($def['mode'] ?? 'create') === 'edit') {
                if ($def['target'] !== $module || ! hub_can($u, $module, 'e')) continue;
            } elseif ($def['target'] !== null && ($def['target'] === $module || ! hub_can($u, $def['target'], 'a'))) continue;
            $out[$k] = $def;
        }

        return $out;
    }

    /**
     * **المسودة** — لا كتابةَ ولا إرسال.
     *
     * @return array{ok: bool, code: ?string, message: string, kind: string, drafts: list<array{fields: array<string,string>, url: ?string}>,
     *               text: ?string, dropped: list<string>, source: array{module: string, id: string}}
     */
    public static function draft(User $u, string $kind, string $module, string $id, ?string $input = null): array
    {
        $out = ['ok' => false, 'code' => null, 'message' => '', 'kind' => $kind, 'drafts' => [], 'text' => null,
            'dropped' => [], 'clipped' => false, 'commits' => null, 'source' => ['module' => $module, 'id' => $id]];
        $def = self::kindsFor($u, $module)[$kind] ?? null;
        if ($def === null) return ['code' => AskFailures::UNAUTHORIZED, 'message' => 'هذا النوعُ غيرُ متاحٍ لك على هذا السجلّ'] + $out;

        // ① المصدرُ بعين السائل — الحارسُ الذي يحرس الشاشة
        $rec = AskTools::run('hub_record', ['module' => $module, 'id' => $id], $u, self::SOURCE_MAX_VALUE);
        if (! $rec['ok'] || $rec['rows'] === []) return ['code' => 'NOT_FOUND', 'message' => 'لا سجلَّ بهذا المعرّفِ في نطاقك'] + $out;
        $row = $rec['rows'][0];

        $target = $def['target'];
        $allowed = $target !== null ? self::proposable($u, $target, $def['fields']) : [];
        if ($target !== null && $allowed === []) return ['code' => AskFailures::UNAUTHORIZED, 'message' => 'لا حقلَ يمكنك تعبئتُه في الوحدة الهدف'] + $out;

        $auth = GovernedCompletion::authorize($u, self::profile(), AiPurposes::ASSIST, 'assist:' . Str::uuid());
        if (! $auth['ok']) return ['code' => (string) $auth['code'], 'message' => AskFailures::message((string) $auth['code'])] + $out;
        $gc = GovernedCompletion::open(self::profile(), $auth['gov'], [
            'feature' => AiPurposes::ASSIST, 'max_calls' => 1, 'max_output' => self::MAX_OUTPUT, 'in_tokens' => 4000,
        ]);

        // **والقصُّ يُقال لا يُخفى**: حقلٌ بلغ سقفَه أو نصٌّ جاوز السياج ⇒ المسودةُ بُنيت على بعض المصدر
        $src = self::sourceText($module, $row);
        $parts = [$src];
        if ($kind === 'notes') {
            $parts[] = self::releaseContext($u, $id, $row);
            $log = trim((string) $input);
            if ($log !== '') {
                $parts[] = "سجلُّ الالتزامات كما ألصقه المستخدم:\n" . mb_substr($log, 0, (int) $def['input']['max']);
                $out['commits'] = ['source' => 'paste', 'why' => null];
            } else {
                $out['commits'] = self::githubCommits($u, $id, $row);
                if ($lines = $out['commits']['lines'] ?? []) {
                    // **الأحدثُ أوّلاً في الحفظ** — مسقوفٌ كاللصق، والقصُّ يُقال (العدُّ المُرسَل من الكلّ)
                    $kept = [];
                    $len = 0;
                    foreach (array_reverse($lines) as $ln) {
                        if ($len + mb_strlen($ln) + 1 > (int) $def['input']['max']) break;
                        $kept[] = $ln;
                        $len += mb_strlen($ln) + 1;
                    }
                    $kept = array_reverse($kept);
                    $out['commits']['sent'] = count($kept);
                    if (count($kept) < (int) $out['commits']['total']) $out['clipped'] = true;
                    $parts[] = 'سجلُّ الالتزامات من GitHub (آخرُ ' . count($kept) . ' من ' . $out['commits']['total'] . "):\n" . implode("\n", $kept);
                }
                unset($out['commits']['lines']);
            }
        }
        $out['clipped'] = $out['clipped'] || mb_strlen($src) > self::SOURCE_MAX_TOTAL || ($rec['clipped'] ?? []) !== [];

        $res = AuditorAi::ask($gc, self::system($kind, $def, $allowed, $u, $target), $parts,
            $target === null ? 'reply' : 'items', self::SOURCE_MAX_TOTAL);
        if (! $res['ok']) {
            return ['code' => (string) $res['code'], 'message' => AskFailures::message((string) $res['code'])] + $out;
        }

        // ② التصفية — ولا يُقبل ما لم يُطلب
        if ($target === null) {
            $text = AuditorAi::str(implode("\n", array_map(fn ($x) => is_scalar($x) ? (string) $x : '', (array) $res['json']['reply'])), 4000);
            if (trim($text) === '') return ['code' => AskFailures::MODEL_NO_OUTPUT, 'message' => AskFailures::message(AskFailures::MODEL_NO_OUTPUT)] + $out;

            return ['ok' => true, 'text' => $text] + $out;
        }

        // «items» قائمةٌ دائماً (الشكلُ الواحدُ يُفحَص بالحارس نفسِه) — والنوعُ المفردُ يأخذ أوّلَها
        $items = array_slice((array) $res['json']['items'], 0, $def['many'] ? 10 : 1);
        $dropped = [];
        $carry = self::carry($u, $target, $def['carry'], $module, $id, $row);
        foreach ($items as $item) {
            if (! is_array($item)) continue;
            $fields = [];
            foreach ($item as $k => $v) {
                if (! is_string($k)) continue;
                $clean = isset($allowed[$k]) ? self::value($allowed[$k], $v) : null;
                if ($clean === null) { $dropped[] = (string) $k; continue; }
                $fields[$k] = $clean;
            }
            if (($def['mode'] ?? 'create') === 'edit') {
                if ($fields === []) continue;
                $out['drafts'][] = ['fields' => $fields,
                    'url' => route('m.edit', ['module' => $target, 'id' => $id]) . '?' . http_build_query(['suggest' => '1'] + $fields)];
                continue;
            }
            if (($fields['title'] ?? '') === '') continue;   // بلا عنوانٍ ليس مقترحاً
            $fields = $fields + $carry;
            $out['drafts'][] = ['fields' => $fields,
                'url' => route('m.create', ['module' => $target]) . '?' . http_build_query($fields)];
        }
        if ($out['drafts'] === []) return ['code' => AskFailures::MODEL_NO_OUTPUT, 'message' => 'لم يخرج مقترحٌ صالح — جرّب مرّةً أخرى أو اكتبه بنفسك'] + $out;

        return ['ok' => true, 'dropped' => array_values(array_unique($dropped))] + $out;
    }

    /**
     * الحقولُ التي يجوز للنموذج اقتراحُها: في قائمة النوع · موجودةٌ في الوحدة بنوعها · **قابلةٌ للكتابة عند السائل**
     * (لا محجوبةَ ولا للقراءة فقط). ولكلِّ خيارٍ قائمتُه من السجلّ.
     *
     * @param  array<string,string>  $wanted
     * @return array<string, array{type:string, options: list<string>}>
     */
    public static function proposable(User $u, string $target, array $wanted): array
    {
        $out = [];
        foreach ((array) (hub_mod($target)['fields'] ?? []) as $f) {
            $k = (string) ($f['key'] ?? '');
            if (! isset($wanted[$k]) || hub_field_mode($u, $target, $k) !== '') continue;
            $type = (string) ($f['type'] ?? 'text');
            if ($type !== $wanted[$k]) continue;
            $out[$k] = ['type' => $type, 'options' => array_values(array_map('strval', (array) ($f['options'] ?? [])))];
        }

        return $out;
    }

    /** قيمةٌ من النموذج بعد التحقّق من نوعها — أو `null` (تُسقَط وتُعلَن) */
    public static function value(array $spec, mixed $v): ?string
    {
        if (! is_scalar($v)) return null;
        $s = trim(Redactor::text((string) $v));
        if ($s === '') return null;

        return match ($spec['type']) {
            'text' => Str::limit(preg_replace('/\s+/u', ' ', $s), 190, ''),
            'ta' => Str::limit($s, self::MAX_VALUE, '…'),
            'sel' => in_array($s, $spec['options'], true) ? $s : null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false ? $s : null,
            'num' => is_numeric($s) && (float) $s >= 0 && (float) $s < 10000 ? (string) (0 + $s) : null,
            default => null,
        };
    }

    /**
     * **الروابطُ من المصدر لا من النموذج** — المشروعُ الذي يراه السائلُ في السجلّ، ومعرّفُ السجلّ نفسِه
     * حين يكون هدفاً مرجعيّاً (تذكرة · قرار · اجتماع). وكلُّ حقلٍ هدفٍ محجوبٍ أو للقراءة عند السائل لا يُحمَل.
     *
     * @return array<string,string>
     */
    private static function carry(User $u, string $target, array $map, string $module, string $id, array $row): array
    {
        $out = [];
        $fields = collect((array) (hub_mod($target)['fields'] ?? []))->keyBy('key');
        foreach ($map as $to => $froms) {
            if (! $fields->has($to) || hub_field_mode($u, $target, $to) !== '') continue;
            // مصادرُ بالترتيب — أوّلُ ما يُعطي قيمةً يكفي
            foreach ((array) $froms as $from) {
                if (str_starts_with($from, '@id:')) {
                    if (substr($from, 4) === $module) { $out[$to] = $id; break; }
                    continue;
                }
                $v = $row[$from] ?? null;
                if (is_string($v) && Str::isUuid($v)) { $out[$to] = $v; break; }
            }
        }

        return $out;
    }

    /**
     * **ما أُنجز في مشروع الإصدار منذ الإصدار السابق — بعين السائل.** المرشَّحون من استعلامٍ بالمشروع
     * والحالة والنافذة، ثمّ **كلُّهم يمرّون بالحارس المُنطَّق** (`AskTools::visibleIds`/`titles`: النطاقُ
     * ورؤيةُ حقل العرض). **ولا ترشيحَ بحقلٍ محجوبٍ عن السائل** — كما يرفضه `hub_list`: القسمُ الذي يحتاج
     * حالةً أو مشروعاً لا يراهما يُقال غيرَ متاح، والنافذةُ لا تُبنى بتاريخٍ لا يراه (فالترشيحُ نفسُه كان يُفشيه).
     */
    private static function releaseContext(User $u, string $id, array $row, ?string &$prevId = null): string
    {
        $pid = (string) ($row['projectId'] ?? '');
        if (! Str::isUuid($pid)) return 'لا مشروعَ ظاهرٌ لهذا الإصدار — فلا مهامَّ ولا مشاكلَ مرفقة.';
        $sees = fn (string $m, string ...$keys) => array_diff($keys, (array) (AskTools::catalog($u)[$m]['fields'] ?? [])) === [];

        // التاريخُ من الموديل لا من الصفّ المُسقَط (قيمةُ Carbon تُرمَّز نصّاً مقتبساً بتوقيتٍ آخر)
        $date = null;
        $prev = null;
        if ($sees('code', 'date')) {
            $date = \App\Models\CodeRelease::query()->whereKey($id)->value('date');
            $date = $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : null;
            // أحدثُ إصدارٍ سابقٍ **يراه** — بترتيب التاريخ لا بالمعرّف، دفعاتٍ حتى أوّلِ مرئيّ
            \App\Models\CodeRelease::query()->where('project_id', $pid)->whereKeyNot($id)->whereNotNull('date')
                ->when($date, fn ($q) => $q->whereDate('date', '<', $date))
                ->orderByDesc('date')->orderByDesc('id')->select(['id', 'date'])
                ->chunk(200, function ($cands) use ($u, &$prev, &$prevId) {
                    $seen = array_flip(AskTools::visibleIds($u, 'code', $cands->pluck('id')->map(fn ($x) => (string) $x)->all()));
                    foreach ($cands as $c) {
                        if (isset($seen[(string) $c->id])) { $prev = $c->date?->toDateString(); $prevId = (string) $c->id; return false; }
                    }
                });
        }

        $lines = ['نافذةُ الإصدار: ' . ($prev ? 'بعد ' . $prev : 'منذ بداية المشروع') . ($date ? ' حتى ' . $date : '')];
        foreach ([['tasks', \App\Models\Task::class, ['مكتملة', 'منجزة'], 'مهامُّ أُنجزت', null],
                  // «المشاكل والمخاطر» وحدةٌ واحدة — والخطرُ المُغلق ليس إصلاحاً في إصدار
                  ['issues', \App\Models\Issue::class, ['محلولة', 'مغلقة'], 'مشاكلُ حُلّت',
                   fn ($q) => $q->where(fn ($w) => $w->where('kind', 'مشكلة')->orWhereNull('kind'))]] as [$m, $class, $done, $title, $extra]) {
            if (! $sees($m, 'status', 'projectId') || ($m === 'issues' && ! $sees($m, 'kind'))) {
                $lines[] = $title . ': غيرُ متاحٍ لك (حقولُ الحالة أو المشروع محجوبة)';
                continue;
            }
            $ids = $class::query()->where('project_id', $pid)->whereIn('status', $done)->when($extra, $extra)
                ->when($prev, fn ($q) => $q->where('updated_at', '>', $prev . ' 23:59:59'))
                ->when($date, fn ($q) => $q->where('updated_at', '<=', $date . ' 23:59:59'))
                ->orderBy('id')->limit(500)->pluck('id')->map(fn ($x) => (string) $x)->all();
            $names = array_slice(AskTools::titles($u, $m, AskTools::visibleIds($u, $m, $ids)), 0, self::RELEASE_ITEMS);
            $lines[] = $title . ' (' . count($names) . '):';
            foreach ($names as $n) $lines[] = '- ' . $n;
        }

        return implode("\n", $lines);
    }

    /**
     * **التزاماتُ الإصدار من GitHub — من حقولٍ يراها السائل وحدَها**: المستودعُ والالتزامُ من صفّه المُسقَط (المحجوبُ
     * غائبٌ عنه أصلاً)، والتزامُ السابقِ فقط إن رأى السابقَ (`$prevId` من `visibleIds`) وحقلَ الالتزام.
     *
     * @return array{source: ?string, why: ?string, total?: int, lines?: list<string>}
     */
    private static function githubCommits(User $u, string $id, array $row): array
    {
        $repo = (string) ($row['repo'] ?? '');
        $head = (string) ($row['commit'] ?? '');
        $parsed = \App\Support\Ai\Dev\ReleaseCommits::repo($repo);
        if ($parsed === null) return ['source' => null, 'why' => null];
        $fields = (array) (AskTools::catalog($u)['code']['fields'] ?? []);
        $cur = \App\Models\CodeRelease::query()->whereKey($id)->first(['id', 'project_id', 'app_id', 'date']);
        if ($cur === null || ! in_array('commit', $fields, true) || ! in_array('date', $fields, true) || $cur->date === null) {
            return ['source' => null, 'why' => 'لا تاريخَ ظاهرٌ للإصدار أو لا التزامَ ظاهرٌ — ألصق السجلَّ يدويّاً'];
        }
        // **السابقُ للمقارنة: المستودعُ نفسُه** — الإصدارُ الأحدثُ قبل هذا في المشروع (والتطبيقِ إن كان) بالتزامٍ،
        // ممّا يراه السائل، ورابطُ مستودعه يشير إلى المالك/المستودع نفسِه
        $base = null;
        \App\Models\CodeRelease::query()->where('project_id', $cur->project_id)->whereKeyNot($id)
            ->when($cur->app_id, fn ($q) => $q->where('app_id', $cur->app_id))
            ->whereNotNull('commit')->where('commit', '!=', '')->whereNotNull('date')
            ->whereDate('date', '<=', $cur->date->toDateString())
            ->orderByDesc('date')->orderByDesc('id')->select(['id', 'repo', 'commit'])
            ->chunk(200, function ($cands) use ($u, $parsed, &$base) {
                $seen = array_flip(AskTools::visibleIds($u, 'code', $cands->pluck('id')->map(fn ($x) => (string) $x)->all()));
                foreach ($cands as $c) {
                    if (isset($seen[(string) $c->id]) && \App\Support\Ai\Dev\ReleaseCommits::repo($c->repo) === $parsed) {
                        $base = (string) $c->commit;

                        return false;
                    }
                }
            });
        if ($base === null) return ['source' => null, 'why' => 'لا إصدارَ سابقٌ ظاهرٌ للمستودع نفسِه بالتزام — ألصق السجلَّ يدويّاً'];
        $r = \App\Support\Ai\Dev\ReleaseCommits::between($repo, $base, $head);

        return $r['ok'] ? ['source' => 'github', 'why' => null, 'total' => $r['total'], 'lines' => $r['lines']]
                        : ['source' => null, 'why' => $r['why']];
    }

    private static function sourceText(string $module, array $row): string
    {
        $label = (string) (hub_mod($module)['label'] ?? $module);
        $lines = ['السجلّ: ' . $label];
        foreach ($row as $k => $v) {
            if ($k === 'id' || ! is_string($v) || $v === '' || Str::isUuid($v)) continue;
            $lines[] = $k . ': ' . $v;
        }

        return implode("\n", $lines);
    }

    private static function system(string $kind, array $def, array $allowed, User $u, ?string $target): string
    {
        $today = now()->toDateString();
        if ($kind === 'notes') {
            return 'أنت مساعدُ تطويرٍ في نظام أعمالٍ عربيّ. اكتب مسودةَ ملاحظاتِ إصدارٍ موجزةً بالعربيّة لمستخدمي النظام، '
                . 'مجمّعةً تحت: ✨ جديد · 🛠️ تحسينات · 🐞 إصلاحات (أسقِط العنوانَ الفارغ)، سطراً لكلِّ بند يبدأ بـ«- ». '
                . 'من الالتزامات والمهامِّ والمشاكل التي بين السياجين **وحدَها** — لا تخترع ميزةً ولا رقماً، '
                . 'واترك الالتزاماتِ الداخليّةَ البحتة (تنسيق · اختبارات · دمج) خارجها. '
                . 'أعِد {"reply": ["سطر", "سطر"]}.';
        }
        if ($target === null) {
            return "أنت مساعدٌ في نظام أعمالٍ عربيّ. اكتب مسودةَ ردٍّ مهذّبٍ وموجزٍ بالعربيّة على تذكرة العميل التي بين السياجين، "
                . "بلا وعودٍ بمواعيدَ أو أسعارٍ أو التزاماتٍ غيرِ مذكورةٍ في التذكرة، وبلا اختلاقِ حقائق. "
                . 'أعِد {"reply": ["سطر", "سطر"]}.';
        }
        $spec = [];
        foreach ($allowed as $k => $f) {
            $spec[] = $k . ' (' . match ($f['type']) {
                'text' => 'عنوانٌ قصير', 'ta' => 'وصفٌ', 'date' => 'تاريخ YYYY-MM-DD ولا يسبق ' . $today,
                'num' => 'عددُ ساعاتٍ تقديريّ', 'sel' => 'واحدٌ من: ' . implode(' | ', $f['options']), default => 'نصّ',
            } . ')';
        }
        if ($kind === 'triage') {
            return 'أنت مساعدُ دعمٍ فنّيٍّ في نظام أعمالٍ عربيّ. صنّف التذكرةَ التي بين السياجين: الحقولُ المسموحةُ وحدَها: '
                . implode('، ', $spec) . '. اختر من الخيارات المذكورة حرفيّاً، واترك الحقلَ الذي لا يدلّ عليه النصّ. '
                . 'أعِد {"items": [{…}]} بعنصرٍ واحد.';
        }
        $what = $def['many'] ? 'القراراتِ التي اتُّخذت فعلاً في المحضر (لا النقاشَ ولا الاقتراحات)' : 'مهمّةً واحدةً تنفيذيّةً يقتضيها السجلّ';

        return "أنت مساعدٌ تنفيذيٌّ في نظام أعمالٍ عربيّ. استخرج {$what}. الحقولُ المسموحةُ وحدَها: " . implode('، ', $spec)
            . '. لا تخترع أسماءَ أشخاصٍ ولا معرّفات، واترك الحقلَ الذي لا يدلّ عليه النصُّ. اليوم ' . $today . '. '
            . ($def['many'] ? 'أعِد {"items": [{…}, …]} (قائمةٌ فارغةٌ إن لم يُتّخذ قرار).' : 'أعِد {"items": [{…}]} بعنصرٍ واحد.');
    }
}
