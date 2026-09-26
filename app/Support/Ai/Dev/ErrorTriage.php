<?php

namespace App\Support\Ai\Dev;

use App\Models\AiProfile;
use App\Models\ErrorEvent;
use App\Models\User;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskPolicy;
use App\Support\Ai\Assist\DraftAssistant;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Ops\ErrorSnippet;
use Illuminate\Support\Str;

/**
 * **مساعدُ التطوير — فرزُ الخطأ** (المرحلة ٥ · `docs/ai-hub/46-ai-roadmap.md` §٧): «لا مطوّرٌ ذاتيّ».
 *
 * زرٌّ في صفحة الخطأ (مركزُ الأخطاء — للمالك وحدَه) يشرح **السببَ المرجَّح واقتراحَ الإصلاح**، ثمّ يفتح
 * **نموذجَ «مشكلة» القائمَ معبّأً** (`issues`) لمن يملك إضافتَها — والحفظُ فعلُه. **لا يُكتَب شيء**: لا مشكلة،
 * ولا حالةُ الخطأ، ولا ملفّ، ولا التزام؛ والشيفرةُ لا تُعدَّل إلّا بيد إنسان.
 *
 *  · **المدخلُ ما تعرضه الشاشةُ نفسُها**: الرسالة والنوع والتكرار والموضع، ومقتطفُ الشيفرة **بحارس الجذر
 *    نفسِه** (`ErrorSnippet` — لا `.env` ولا ملفّ خارج المشروع)، وأوّلُ أثر النداء، وأخطاءٌ شقيقة. كلُّه
 *    منقّحٌ من الأسرار (`Redactor`) داخل سياجٍ يُقرأ ولا يُطاع.
 *  · **المقترحُ بالقائمة البيضاء نفسِها** (`DraftAssistant::proposable/value`)؛ والموضعُ وتاريخُ الاكتشاف
 *    ونوعُ «مشكلة» **من الخادم** لا من النموذج.
 *  · **نداءٌ محكوم** (`feature=dev` · غرضُ `coding` افتراضاً).
 */
final class ErrorTriage
{
    public const MAX_OUTPUT = 1200;

    /** ما يجوز للنموذج اقتراحُه في «مشكلة» */
    public const FIELDS = ['title' => 'text', 'cause' => 'ta', 'fix' => 'ta', 'severity' => 'sel', 'priority' => 'sel'];

    public static function enabled(): bool
    {
        return (string) setting('dev.enabled', '1') === '1';
    }

    public static function profileKey(): string
    {
        $k = trim((string) setting('dev.profile', 'coding'));

        return $k === '' ? 'coding' : $k;
    }

    public static function profile(): ?AiProfile
    {
        $p = AiProfile::query()->where('key', self::profileKey())->where('enabled', true)->orderBy('id')->first();

        return $p !== null && AiProfiles::chain($p, AiPurposes::DEV)->isNotEmpty() ? $p : null;
    }

    /** بابُ مركز الأخطاء (المالك) + شروطُ «اسأل Hub» + غرضٌ له سلسلة */
    public static function ready(?User $u): bool
    {
        return $u !== null && hub_is_owner($u) && self::enabled() && AskPolicy::ready($u) && self::profile() !== null;
    }

    /**
     * @return array{ok: bool, code: ?string, message: string, explain: array{title: string, cause: string, fix: string},
     *               draft: ?array{fields: array<string,string>, url: string}, dropped: list<string>}
     */
    public static function explain(User $u, ErrorEvent $e): array
    {
        $out = ['ok' => false, 'code' => null, 'message' => '', 'explain' => ['title' => '', 'cause' => '', 'fix' => ''],
            'draft' => null, 'dropped' => []];
        if (! self::ready($u)) return ['code' => AskFailures::UNAUTHORIZED, 'message' => 'مساعدُ التطوير غيرُ متاحٍ لك الآن'] + $out;

        $canIssue = hub_can($u, 'issues', 'a');
        $allowed = $canIssue ? DraftAssistant::proposable($u, 'issues', self::FIELDS) : [];

        $auth = GovernedCompletion::authorize($u, self::profile(), AiPurposes::DEV, 'dev:' . Str::uuid());
        if (! $auth['ok']) return ['code' => (string) $auth['code'], 'message' => AskFailures::message((string) $auth['code'])] + $out;
        $gc = GovernedCompletion::open(self::profile(), $auth['gov'], [
            'feature' => AiPurposes::DEV, 'max_calls' => 1, 'max_output' => self::MAX_OUTPUT, 'in_tokens' => 3000,
        ]);

        $res = AuditorAi::ask($gc, self::system($allowed), self::evidence($e), 'items', 4000);
        if (! $res['ok']) return ['code' => (string) $res['code'], 'message' => AskFailures::message((string) $res['code'])] + $out;

        $item = ((array) $res['json']['items'])[0] ?? null;
        if (! is_array($item)) return ['code' => AskFailures::MODEL_NO_OUTPUT, 'message' => AskFailures::message(AskFailures::MODEL_NO_OUTPUT)] + $out;

        // الشرحُ يُعرَض لصاحب الشاشة ولو لم يملك إضافةَ مشكلة — منقّحاً مقصوصاً
        $out['explain'] = [
            'title' => AuditorAi::str($item['title'] ?? '', 190),
            'cause' => AuditorAi::str($item['cause'] ?? '', 1500),
            'fix' => AuditorAi::str($item['fix'] ?? '', 1500),
        ];
        if ($out['explain']['cause'] === '' && $out['explain']['fix'] === '') {
            return ['code' => AskFailures::MODEL_NO_OUTPUT, 'message' => AskFailures::message(AskFailures::MODEL_NO_OUTPUT)] + $out;
        }

        if ($allowed !== []) {
            $fields = [];
            foreach ($item as $k => $v) {
                if (! is_string($k) || ! isset(self::FIELDS[$k])) continue;
                $clean = isset($allowed[$k]) ? DraftAssistant::value($allowed[$k], $v) : null;
                if ($clean === null) { $out['dropped'][] = $k; continue; }
                $fields[$k] = $clean;
            }
            if (($fields['title'] ?? '') === '') $fields['title'] = Str::limit('🐞 ' . preg_replace('/\s+/u', ' ', (string) $e->message), 180, '…');
            $fields += self::server($u, $e);
            $out['draft'] = ['fields' => $fields, 'url' => route('m.create', ['module' => 'issues']) . '?' . http_build_query($fields)];
        }

        return ['ok' => true] + $out;
    }

    /** ما يملؤه الخادمُ لا النموذج — وكلُّ حقلٍ محجوبٍ أو للقراءة عند السائل لا يُملأ @return array<string,string> */
    private static function server(User $u, ErrorEvent $e): array
    {
        $rel = $e->file ? str_replace(base_path() . '/', '', (string) $e->file) : '';
        $want = ['kind' => 'مشكلة', 'found' => now()->toDateString(),
            'affected' => $rel !== '' ? Str::limit($rel . ($e->line ? ':' . $e->line : ''), 190, '') : ''];
        $fields = collect((array) (hub_mod('issues')['fields'] ?? []))->keyBy('key');
        $out = [];
        foreach ($want as $k => $v) {
            if ($v === '' || ! $fields->has($k) || hub_field_mode($u, 'issues', $k) !== '') continue;
            $opts = (array) ($fields[$k]['options'] ?? []);
            if ($opts !== [] && ! in_array($v, $opts, true)) continue;
            $out[$k] = $v;
        }

        return $out;
    }

    /** ما تعرضه صفحةُ الخطأ لصاحبها — بحارس الجذر نفسِه للمقتطف @return list<string> */
    private static function evidence(ErrorEvent $e): array
    {
        $rel = $e->file ? str_replace(base_path() . '/', '', (string) $e->file) : '—';
        $url = $e->url ? (string) strtok((string) $e->url, '?') : '—';
        $parts = ["النوع: {$e->kind}\nالتكرار: {$e->count}\nالموضع: {$rel}" . ($e->line ? ':' . $e->line : '')
            . "\nالرابط: " . ($e->method ? $e->method . ' ' : '') . $url . "\nالرسالة:\n" . $e->message];

        $real = ErrorSnippet::realPath($e);
        if ($real !== null && $e->line) {
            $code = array_map(fn ($s) => str_pad((string) $s['n'], 5) . ($s['hot'] ? '▶ ' : '  ') . $s['code'],
                ErrorSnippet::around($real, (int) $e->line, 12, 8));
            if ($code !== []) $parts[] = "الشيفرةُ حول السطر:\n" . implode("\n", $code);
        }
        if ($e->trace) $parts[] = "أوّلُ أثر النداء:\n" . Str::limit((string) $e->trace, 2500, '…');

        $sib = ErrorEvent::query()->whereKeyNot($e->id)
            ->where(fn ($q) => $q->when($e->file, fn ($w) => $w->orWhere('file', $e->file))
                ->when($e->url, fn ($w) => $w->orWhere('url', $e->url)))
            ->orderByDesc('last_seen')->orderByDesc('id')->limit(5)->pluck('message')->all();
        if ($sib !== []) $parts[] = "أخطاءٌ شقيقة (الملفُّ أو الرابطُ نفسُه):\n- " . implode("\n- ", array_map('strval', $sib));

        return $parts;
    }

    private static function system(array $allowed): string
    {
        $opts = fn (string $k) => isset($allowed[$k]) ? ' (واحدٌ من: ' . implode(' | ', $allowed[$k]['options']) . ')' : '';

        return 'أنت مساعدُ تطويرٍ لنظامٍ مبنيٍّ على Laravel/PHP. اقرأ الخطأَ وما حوله بين السياجين، واشرح بالعربيّة '
            . 'السببَ الأرجحَ (cause) وخطواتِ إصلاحٍ محدّدة (fix) تُشير إلى الملفّ والسطر، بلا اختلاقِ ملفّاتٍ أو دوالَّ لم تُذكر، '
            . 'وقل «غيرُ مؤكَّد» حين لا تكفي الأدلّة. وعنواناً قصيراً للمشكلة (title)'
            . ', وشدّةً (severity)' . $opts('severity') . ' وأولويّةً (priority)' . $opts('priority') . '. '
            . 'أعِد {"items": [{"title": "…", "cause": "…", "fix": "…", "severity": "…", "priority": "…"}]} بعنصرٍ واحد.';
    }
}
