<?php

namespace App\Support\Ai\Ask;

use App\Models\AskThread;
use App\Models\AskTurn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **ذاكرةُ «اسأل Hub»** — المرحلة ٢ (`docs/ai-hub/46-ai-roadmap.md` §٤).
 *
 * المرحلةُ ٣ رفضت حفظَ المحادثة لسببٍ مكتوبٍ في `AskController`: الحفظُ يُنشئ مخزناً حسّاساً يُقرأ
 * لاحقاً **بصلاحيّاتٍ غيرِ صلاحيّةِ سائله**، ويحتاج «مِلكيّةً ونطاقاً وتحقّقاً عند كلِّ قراءةٍ وسياسةَ
 * احتفاظٍ وحذف — ولا يُبنى بنصفه». وهذا الصنفُ هو تلك الشروطُ كاملة:
 *
 *  ① **المِلكيّة:** كلُّ قراءةٍ بـ`user_id` صاحبِ الجلسة — خيطُ غيرِه ٤٠٤ كغيرِ الموجود، **ولا يستثني المالك**.
 *  ② **التحقّقُ عند كلِّ قراءة:** الجوابُ المحفوظُ يُعرَض فقط إن بقيت مصادرُه كلُّها في نطاق صاحبه
 *     **الآن** (`AskTools::stillVisible` — الحارسُ نفسُه الذي قرأها) وبقيت حقولُها مرئيّةً له. وإلّا يُخفى
 *     الجوابُ ويبقى سؤالُه — فسحبُ صلاحيّةٍ لا يُبقي نسخةً من بياناتها في التاريخ.
 *  ③ **ولا بياناتٍ قديمةً تعود إلى النموذج:** سؤالُ المتابعة يُرفَق بـ**أسئلة** الخيط السابقة وحدَها
 *     (نصُّ صاحبها) — **لا بأجوبتها** — فيقرأ النموذجُ البياناتِ من جديد بالأدوات المُنطَّقة.
 *  ④ **مشفَّرٌ وخارجَ التدقيق:** `encrypted` على النصوص، ولا `Auditable`.
 *  ⑤ **احتفاظٌ ومحو:** `prune()` في `hub:automation` بعد `ask.memory_days`، و`forget()` لصاحبه.
 */
final class AskMemory
{
    /** أسئلةٌ سابقةٌ تُرفَق بسؤال المتابعة — وكلٌّ مقصوص */
    public const EARLIER = 4;

    public const EARLIER_CHARS = 300;

    /** خيوطٌ تُعرَض في الشريط */
    public const LIST = 20;

    /** أدوارٌ في الخيط الواحد — ما بعدها خيطٌ جديد (سقفٌ للسياق وللقاعدة) */
    public const MAX_TURNS = 50;

    public static function enabled(): bool
    {
        return (string) setting('ask.memory', '1') === '1' && Schema::hasTable('ask_turns');
    }

    public static function days(): int
    {
        return max(1, min(365, (int) setting('ask.memory_days', 30)));
    }

    /** خيطُ هذا المستخدمِ بمعرّفه — أو `null` (غيرُ موجودٍ وليس لك سواء) */
    public static function open(User $u, ?string $id): ?AskThread
    {
        if ($id === null || $id === '' || ! Str::isUuid($id) || ! self::enabled()) return null;

        return AskThread::query()->where('id', $id)->where('user_id', $u->id)->first();
    }

    /** @return \Illuminate\Support\Collection<int, AskThread> */
    public static function threads(User $u)
    {
        if (! self::enabled()) return collect();

        return AskThread::query()->where('user_id', $u->id)
            ->orderByDesc('last_at')->orderByDesc('id')->limit(self::LIST)->get();
    }

    /** أسئلةُ الخيط السابقة (الأقدمُ أوّلاً) — **لا أجوبتُها** @return list<string> */
    public static function earlierQuestions(?AskThread $t): array
    {
        if ($t === null) return [];

        return AskTurn::query()->where('thread_id', $t->id)->where('user_id', $t->user_id)
            ->orderByDesc('id')->limit(self::EARLIER)->get()
            ->reverse()->map(fn (AskTurn $x) => Str::limit((string) $x->question, self::EARLIER_CHARS, '…'))
            ->values()->all();
    }

    /** أيُتابَع هذا الخيط أم بلغ سقفَه؟ */
    public static function full(AskThread $t): bool
    {
        return AskTurn::query()->where('thread_id', $t->id)->count() >= self::MAX_TURNS;
    }

    /**
     * يحفظ السؤالَ ونتيجتَه — وينشئ الخيطَ إن لم يكن. والمصادرُ تُحفظ **بلا قيم**: الوحدة والمعرّفات
     * وحقولُ الوحدة المرئيّةُ للسائل حينها (ليُعرَف لاحقاً أنّ حقلاً حُجب بعدها).
     */
    public static function record(User $u, ?AskThread $t, string $question, array $result): ?AskThread
    {
        if (! self::enabled()) return null;
        $q = AskPolicy::sanitizeQuestion($question);
        if ($q === null) return $t;

        $catalog = AskTools::catalog($u);
        $scope = AskTools::scopeShape($u);
        $sources = [];
        foreach ((array) ($result['sources'] ?? []) as $s) {
            $module = $s['module'] ?? null;
            $refs = array_values(array_filter((array) ($s['refs'] ?? []), 'is_string'));
            $mods = $module !== null ? [(string) $module] : array_values(array_unique(array_map(fn ($r) => Str::before($r, ':'), $refs)));
            $fields = [];
            foreach ($mods as $m) $fields[$m] = $catalog[$m]['fields'] ?? [];
            $ids = array_values(array_map('strval', (array) ($s['ids'] ?? [])));
            $sources[] = ['tool' => (string) ($s['tool'] ?? ''), 'module' => $module, 'ids' => $ids,
                'refs' => $refs, 'fields' => $fields,
                'findings' => array_values(array_filter((array) ($s['findings'] ?? []), 'is_string')),
                // جوابٌ بلا معرّفاتٍ (عدٌّ · قائمةٌ فارغة) يُتحقَّق من أنّ النطاقَ لم يضِق بعده
                'scope' => ($ids === [] && $refs === []) ? $scope : null];
        }
        $ok = (bool) ($result['ok'] ?? false);

        return DB::transaction(function () use ($u, $t, $q, $result, $sources, $ok) {
            if ($t === null) {
                $t = AskThread::create(['user_id' => $u->id, 'title' => Str::limit($q, 80, '…'), 'last_at' => now()]);
            }
            AskTurn::create([
                'thread_id' => $t->id, 'user_id' => $u->id, 'question' => $q,
                'answer' => $ok ? (string) ($result['answer'] ?? '') : null,
                'ok' => $ok, 'failure' => $ok ? null : Str::limit((string) ($result['failure'] ?? ''), 40, ''),
                'sources' => $sources,
            ]);
            $t->forceFill(['last_at' => now()])->save();

            return $t;
        });
    }

    /**
     * أدوارُ الخيط للعرض — **والجوابُ مُعادُ التحقّق**: `answer` فارغٌ و`hidden` صادقٌ متى سقطت مصادرُه.
     *
     * @return list<array{question:string, answer:?string, ok:bool, failure:?string, hidden:bool, at:\DateTimeInterface}>
     */
    public static function turns(User $u, AskThread $t): array
    {
        if ((string) $t->user_id !== (string) $u->id) return [];
        $out = [];
        foreach (AskTurn::query()->where('thread_id', $t->id)->where('user_id', $u->id)->orderBy('id')->get() as $x) {
            $visible = $x->ok && self::answerVisible($u, $x);
            $out[] = ['question' => (string) $x->question, 'answer' => $visible ? (string) $x->answer : null,
                'ok' => (bool) $x->ok, 'failure' => $x->failure, 'hidden' => $x->ok && ! $visible, 'at' => $x->created_at];
        }

        return $out;
    }

    /** هل يُعرَض هذا الجوابُ المحفوظُ لصاحبه **الآن**؟ — كلُّ مصدرٍ يجتاز الحارسَ من جديد */
    public static function answerVisible(User $u, AskTurn $x): bool
    {
        if (! AskPolicy::canAsk($u)) return false;
        $liveFindings = null;
        foreach ((array) $x->sources as $s) {
            $fields = (array) ($s['fields'] ?? []);
            // ملاحظاتُ المدقّق: كلُّ ملاحظةٍ بُني عليها الجوابُ يجب أن تبقى ظاهرةً له الآن بحارس المدقّق
            // (الشاهدُ كلُّه · الحقول · ليست عملَه · بوّابةُ المراجعة · لم تُرفَض ولم تُؤجَّل)
            if (($s['tool'] ?? '') === 'hub_findings') {
                $keys = (array) ($s['findings'] ?? []);
                if ($keys === [] && ((array) ($s['ids'] ?? []) !== [] || (array) ($s['refs'] ?? []) !== [])) return false;
                $liveFindings ??= AskTools::liveFindingKeys($u);
                foreach ($keys as $k) if (! isset($liveFindings[$k])) return false;
            }
            // ولا معرّفات؟ فالنطاقُ نفسُه يجب ألّا يكون قد ضاق (عدٌّ حُسب على نطاقٍ أوسع)
            if (is_array($s['scope'] ?? null) && ! AskTools::scopeCovers($s['scope'], $u)) return false;
            $module = $s['module'] ?? null;
            if (is_string($module) && $module !== '') {
                if (! AskTools::stillVisible($u, $module, (array) ($s['ids'] ?? []), (array) ($fields[$module] ?? []))) return false;
                continue;
            }
            // عابرٌ للوحدات: كلُّ سجلٍّ في وحدته
            $by = [];
            foreach ((array) ($s['refs'] ?? []) as $r) {
                if (! is_string($r) || ! str_contains($r, ':')) return false;
                $by[Str::before($r, ':')][] = Str::after($r, ':');
            }
            // معرّفاتٌ بلا وحداتها لا يُتحقَّق منها — فيُحجَب الجوابُ لا يُفترَض الأمان
            if ($by === [] && ((array) ($s['ids'] ?? [])) !== []) return false;
            foreach ($by as $m => $ids) {
                if (! AskTools::stillVisible($u, $m, $ids, (array) ($fields[$m] ?? []))) return false;
            }
        }

        return true;
    }

    /** محوُ خيطٍ واحدٍ أو كلِّ خيوطه — لصاحبه وحدَه @return int خيوطٌ مُحيت */
    public static function forget(User $u, ?string $threadId = null): int
    {
        if (! Schema::hasTable('ask_threads')) return 0;
        $q = AskThread::query()->where('user_id', $u->id);
        if ($threadId !== null) $q->where('id', $threadId);
        $ids = $q->pluck('id')->all();
        if ($ids === []) return 0;

        return DB::transaction(function () use ($ids, $u) {
            AskTurn::query()->whereIn('thread_id', $ids)->where('user_id', $u->id)->delete();

            return AskThread::query()->whereIn('id', $ids)->delete();
        });
    }

    /** مقصُّ العمر — خيوطٌ لم تُمَسّ منذ `ask.memory_days` @return int */
    public static function prune(): int
    {
        if (! Schema::hasTable('ask_threads')) return 0;
        $cut = now()->subDays(self::days());
        $n = 0;
        do {
            $ids = AskThread::query()->where(fn ($w) => $w->where('last_at', '<', $cut)->orWhereNull('last_at'))
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if ($ids === []) break;
            AskTurn::query()->whereIn('thread_id', $ids)->delete();
            $n += AskThread::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === 500);

        return $n;
    }
}
