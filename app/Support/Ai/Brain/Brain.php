<?php

namespace App\Support\Ai\Brain;

use App\Models\AiProfile;
use App\Models\User;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Ask\AskTools;
use App\Support\Ai\Gateway\AiGateway;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Platform\Redactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * **العقلُ الثاني — بحثٌ دلاليٌّ مُنطَّق** (المرحلة ٤ · `docs/ai-hub/46-ai-roadmap.md` §٦).
 *
 *  · **الفهرسة (`index`)** بهويّة خدمة: حقولٌ نصّيّةٌ من وحداتٍ معرفيّة (`SOURCES`) تُقطَّع وتُضمَّن عبر
 *    `GovernedCompletion::embed` (السياسة · الميزانيّة · سجلُّ الاستهلاك، feature=brain) — **وما لم يتغيّر
 *    لا يُعاد تضمينُه** (بصمةُ النموذج|النصّ). لا نصَّ يُخزَّن — المتّجهُ وحدَه.
 *  · **البحث (`search`)** **بصلاحيّة القارئ وقتَ الاستعلام** — لا فهرسَ لكلِّ مستخدم: أقربُ المقاطع ضمن وحداتٍ
 *    يراها وشركاته (تضييقٌ رخيص)، ثمّ `AskTools::visibleIds` (الاستعلامُ المُنطَّق نفسُه) وحقلُ المقطع يجب أن
 *    يكون مرئيّاً له — قبل أن يبلغ أيُّ مرشَّحٍ النموذجَ أو الشاشة.
 *  · **مطفأٌ افتراضاً** (`brain.enabled`): نصوصُ السجلّات تغادر إلى مزوّد التضمين، وهذا قرارُ المالك.
 */
final class Brain
{
    /** الوحداتُ المعرفيّة وحقولُها النصّيّة (مفاتيحُ حقول السجلّ) — والتقاريرُ اليوميّةُ خارجها عمداً (شخصيّةٌ كثيرة) */
    public const SOURCES = [
        'kb' => ['title', 'body'],
        'policies' => ['title', 'body'],
        'meetings' => ['title', 'agenda', 'notes', 'decs'],
        'decisions' => ['title', 'reason', 'notes'],
        'projects' => ['name', 'desc'],
        'tasks' => ['title', 'desc'],
        'issues' => ['title', 'cause', 'fix', 'finalFix'],
        'tickets' => ['subject', 'body'],
    ];

    public const CHUNK = 800;

    public const BATCH = 16;

    public static function enabled(): bool
    {
        return (string) setting('brain.enabled', '0') === '1' && Schema::hasTable('ai_embeddings');
    }

    public static function profileKey(): string
    {
        $k = trim((string) setting('brain.profile', 'embedding'));

        return $k === '' ? 'embedding' : $k;
    }

    public static function profile(): ?AiProfile
    {
        $p = AiProfile::query()->where('key', self::profileKey())->where('enabled', true)->orderBy('id')->first();

        return $p !== null && AiProfiles::chain($p, AiPurposes::BRAIN)->isNotEmpty() ? $p : null;
    }

    /** لماذا لا يعمل؟ — `null` إن كان جاهزاً */
    public static function whyNot(): ?string
    {
        if (! self::enabled()) return 'العقلُ الثاني مطفأ (brain.enabled) — تفعيلُه قرارُ المالك لأنّ نصوصَ السجلّات تغادر إلى مزوّد التضمين';
        if (! AiGateway::enabled()) return (string) (AiGateway::whyNotReady() ?? 'بوّابةُ النماذجِ غيرُ مهيّأة');
        if (! AiGateway::probePassed()) return 'لم يُفحَص الاتصالُ بالبوّابةِ على الإعدادِ الحاليّ';
        if (self::profile() === null) return 'غرضُ «' . self::profileKey() . '» بلا نموذجِ تضمينٍ صالح';

        return null;
    }

    public static function ready(): bool
    {
        return self::whyNot() === null;
    }

    public static function store(): VectorStore
    {
        return new PhpVectorStore();
    }

    public static function maxPerRun(): int
    {
        return max(16, min(5000, (int) setting('brain.max_per_run', 400)));
    }

    // ══════════════════ الفهرسة ══════════════════

    /**
     * جولةُ فهرسة: تُضمَّن المقاطعُ الجديدةُ أو المتغيّرةُ حتى `maxPerRun`، وتُمحى مقاطعُ سجلٍّ حُذف أو فرغ حقلُه.
     *
     * @return array{records:int, embedded:int, skipped:int, removed:int, stopped:?string}
     */
    public static function index(bool $dry = false): array
    {
        $stats = ['records' => 0, 'embedded' => 0, 'skipped' => 0, 'removed' => 0, 'stopped' => null];
        if (($why = self::whyNot()) !== null) return ['stopped' => $why] + $stats;

        $store = self::store();
        $pending = [];   // مقاطعُ تنتظر التضمين
        $budget = self::maxPerRun();
        $gc = null;

        $flush = function () use (&$pending, &$gc, &$stats, $store, $dry): ?string {
            if ($pending === [] || $dry) { $stats['embedded'] += $dry ? count($pending) : 0; $pending = []; return null; }
            if ($gc === null) {
                $auth = GovernedCompletion::authorize(null, self::profile(), AiPurposes::BRAIN, 'brain:' . Str::uuid());
                if (! $auth['ok']) return (string) $auth['code'];
                $gov = $auth['gov'];
                $gov['user'] = $gov['user_id'] = $gov['role_id'] = $gov['company_id'] = null;
                $gc = GovernedCompletion::open(self::profile(), $gov, ['feature' => AiPurposes::BRAIN,
                    'max_calls' => (int) ceil(self::maxPerRun() / self::BATCH) + 2, 'max_output' => 1, 'in_tokens' => 2000]);
            }
            $texts = array_column($pending, 'text');
            $res = $gc->embed($texts, array_sum(array_map('mb_strlen', $texts)));
            if (! $res['ok']) return (string) $res['code'];
            $vecs = (array) ($res['data']['data'] ?? []);
            // **الموسومُ بالنموذج الذي خدم فعلاً** — احتياطيٌّ خدم؟ تُوسَم متّجهاتُه باسمه وبصمتِه، فلا تُقارَن
            // بمتّجهات الأساسيّ، وتُعاد بالأساسيّ في الجولة التالية (بصمتُه لا تطابق)
            $served = (string) ($res['model'] ?? '');
            $rows = [];
            foreach ($pending as $i => $p) {
                $v = $vecs[$i]['embedding'] ?? null;
                if (! is_array($v) || $v === [] || $served === '') continue;
                $rows[] = ['vector' => $v, 'hash' => sha1($served . '|' . $p['text']), 'model' => $served] + $p['row'];
            }
            $store->upsert($rows);
            $stats['embedded'] += count($rows);
            $pending = [];

            return null;
        };

        // متّجهاتٌ من نموذجٍ احتياطيٍّ في السلسلة **سارية** (لا يُعاد الأوّلون كلَّ جولةٍ فيجوع من بعدهم)؛
        // وتُرقّى إلى الأساسيّ **بعد** أن يُفهرَس الجديدُ، بما بقي من سقف الجولة
        $chainNames = self::chainNames();
        $upgrade = [];

        foreach (self::SOURCES as $module => $keys) {
            $def = hub_mod($module);
            // السجلُّ يسمّي الموديلَ قصيراً (`Decision`) — كما يحلّه `AskTools`
            $class = is_array($def) && is_string($def['model'] ?? null) ? 'App\\Models\\' . $def['model'] : null;
            if ($class === null || ! class_exists($class)) continue;
            $cols = [];
            foreach ((array) $def['fields'] as $f) if (in_array($f['key'] ?? '', $keys, true)) $cols[$f['key']] = (string) ($f['col'] ?? $f['key']);
            $ccol = hub_company_col($module);
            $model = self::modelName();

            foreach ($class::query()->orderBy('id')->lazyById(200) as $rec) {
                $stats['records']++;
                $have = $store->hashes($module, (string) $rec->id);
                $cid = $ccol ? ($rec->{$ccol} ?: null) : null;
                $cid = $cid !== null ? (string) $cid : null;
                $live = [];
                foreach ($cols as $key => $col) {
                    $text = trim((string) ($rec->{$col} ?? ''));
                    if ($text === '') continue;
                    foreach (self::chunks(Redactor::text($text)) as $n => $chunk) {
                        $k = $key . '#' . $n;
                        $live[] = $k;
                        $hash = sha1($model . '|' . $chunk);
                        if (($have[$k]['hash'] ?? null) === $hash) { $stats['skipped']++; continue; }
                        $stored = $have[$k]['hash'] ?? null;
                        if ($stored !== null && in_array($stored, array_map(fn ($n) => sha1($n . '|' . $chunk), $chainNames), true)) {
                            $stats['skipped']++;
                            if (count($upgrade) < self::maxPerRun()) {
                                $upgrade[] = ['text' => $chunk, 'row' => ['module' => $module, 'record_id' => (string) $rec->id,
                                    'field' => $key, 'chunk' => $n, 'company_id' => $cid]];
                            }
                            continue;
                        }
                        if ($budget-- <= 0) { $stats['stopped'] = 'بلغت الجولةُ سقفَها (brain.max_per_run) — تُكمل الجولةُ التالية'; break 4; }
                        $pending[] = ['text' => $chunk, 'row' => ['module' => $module, 'record_id' => (string) $rec->id,
                            'field' => $key, 'chunk' => $n, 'company_id' => $cid]];
                        if (count($pending) >= self::BATCH && ($stop = $flush()) !== null) { $stats['stopped'] = $stop; break 4; }
                    }
                }
                if (! $dry) {
                    // **كلُّ مفتاحٍ لم يعُد حيّاً يُمحى** — لا حين ينقص العددُ فقط (حقلٌ قصُر وآخرُ طال بالعدد نفسِه)
                    if (array_diff(array_keys($have), $live) !== []) $stats['removed'] += $store->forget($module, (string) $rec->id, $live);
                    // **ونُقل إلى شركةٍ أخرى بالنصّ نفسِه؟** تُحدَّث شركتُه بلا تضمين — وإلّا اختفى عن شركته الجديدة
                    foreach ($have as $h) {
                        if ($h['company_id'] !== $cid) { $store->retag($module, (string) $rec->id, $cid); break; }
                    }
                }
            }

            // سجلّاتٌ حُذفت: مقاطعُها تُمحى
            if (! $dry) {
                $gone = DB::table('ai_embeddings')->where('module', $module)->distinct()->orderBy('record_id')->pluck('record_id')
                    ->diff($class::query()->pluck('id')->map(fn ($x) => (string) $x));
                foreach ($gone as $rid) $stats['removed'] += $store->forget($module, (string) $rid);
            }
        }
        if ($stats['stopped'] === null && ($stop = $flush()) !== null) $stats['stopped'] = $stop;

        // الترقيةُ إلى الأساسيّ — بما بقي من السقف، بعد الجديد
        foreach ($upgrade as $p) {
            if ($stats['stopped'] !== null || $budget-- <= 0) break;
            $pending[] = $p;
            if (count($pending) >= self::BATCH && ($stop = $flush()) !== null) $stats['stopped'] = $stop;
        }
        if ($stats['stopped'] === null && ($stop = $flush()) !== null) $stats['stopped'] = $stop;

        return $stats;
    }

    /** @return list<string> مقاطعُ بحدود الجمل ما أمكن */
    public static function chunks(string $text): array
    {
        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? '');
        if ($text === '') return [];
        $out = [];
        while (mb_strlen($text) > self::CHUNK) {
            $cut = mb_strrpos(mb_substr($text, 0, self::CHUNK), "\n") ?: mb_strrpos(mb_substr($text, 0, self::CHUNK), '. ') ?: self::CHUNK;
            $out[] = trim(mb_substr($text, 0, max(200, (int) $cut)));
            $text = trim(mb_substr($text, max(200, (int) $cut)));
        }
        if ($text !== '') $out[] = $text;

        return array_slice($out, 0, 20);
    }

    /** @return list<string> أسماءُ نماذج السلسلة بترتيبها */
    private static function chainNames(): array
    {
        return AiProfiles::chain(self::profile(), AiPurposes::BRAIN)->map(fn ($m) => (string) $m->litellm_model_name)->values()->all();
    }

    private static function modelName(): string
    {
        // رأسُ السلسلة المرتّبة (مجموعةٌ لا استعلام) — ما تبدأ به الفهرسةُ وتُقاس عليه البصمات
        $chain = AiProfiles::chain(self::profile(), AiPurposes::BRAIN)->values();

        return $chain->isEmpty() ? '' : (string) $chain[0]->litellm_model_name;
    }

    // ══════════════════ البحث ══════════════════

    /**
     * @return array{ok: bool, code: ?string, hits: list<array{module:string, label:string, id:string, title:string, field:string, score:float}>, partial: bool}
     */
    public static function search(User $u, string $q, int $k = 8): array
    {
        $out = ['ok' => false, 'code' => null, 'hits' => [], 'partial' => false];
        $q = trim(Redactor::text($q));
        if (mb_strlen($q) < 2) return ['code' => AskFailures::MALFORMED_QUESTION] + $out;
        if (! self::ready()) return ['code' => 'BRAIN_OFF'] + $out;

        $catalog = AskTools::catalog($u);
        $modules = array_values(array_filter(array_keys(self::SOURCES), fn ($m) => isset($catalog[$m])));
        if ($modules === []) return ['ok' => true] + $out;

        $auth = GovernedCompletion::authorize($u, self::profile(), AiPurposes::BRAIN, 'brain-q:' . Str::uuid());
        if (! $auth['ok']) return ['code' => (string) $auth['code']] + $out;
        $gc = GovernedCompletion::open(self::profile(), $auth['gov'], ['feature' => AiPurposes::BRAIN,
            'max_calls' => 1, 'max_output' => 1, 'in_tokens' => 200]);
        $res = $gc->embed([$q], mb_strlen($q));
        $vec = $res['ok'] ? ($res['data']['data'][0]['embedding'] ?? null) : null;
        if (! is_array($vec)) return ['code' => (string) ($res['code'] ?? AskFailures::MODEL_NO_OUTPUT)] + $out;
        $served = (string) ($res['model'] ?? '');

        // ── الحكمُ وقتَ الاستعلام **أثناء المسح**: حقلُ المقطع مرئيّ + النطاقُ المُنطَّق — دفعةً دفعةً بترتيب القرب،
        //    فلا يزاحم ما لا يراه القارئُ ما يراه (ولا يصير غيابُ سجلِّه دليلاً على ما حوله) ──
        $accept = function (array $batch) use ($u, $catalog): array {
            $byModule = [];
            foreach ($batch as $h) {
                if (! in_array($h['field'], $catalog[$h['module']]['fields'] ?? [], true)) continue;
                $byModule[$h['module']][] = $h;
            }
            $ok = [];
            foreach ($byModule as $m => $hits) {
                $seen = array_flip(AskTools::visibleIds($u, $m, array_column($hits, 'record_id')));
                foreach ($hits as $h) if (isset($seen[$h['record_id']])) $ok[] = $h;
            }

            return $ok;
        };
        $near = self::store()->nearest($vec, $modules, hub_company_ids($u), $served, $k, $accept);

        $best = [];
        foreach ($near['hits'] as $h) {
            $key = $h['module'] . ':' . $h['record_id'];
            if (! isset($best[$key]) || $h['score'] > $best[$key]['score']) $best[$key] = $h;
        }
        // مقاطعُ من فضاء نموذجٍ آخر (تبديلٌ لم تكتمل إعادةُ فهرسته، أو احتياطيٌّ خدم) لا تُقارَن — فالتغطيةُ ناقصةٌ وتُعلَن
        $stale = $served !== '' && DB::table('ai_embeddings')->whereIn('module', $modules)->where('model', '!=', $served)->exists();
        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($a['record_id'], $b['record_id']));
        $best = array_slice(array_values($best), 0, $k);

        $titles = [];
        foreach (array_unique(array_column($best, 'module')) as $m) {
            $titles[$m] = AskTools::titles($u, $m, array_column(array_filter($best, fn ($h) => $h['module'] === $m), 'record_id'));
        }
        foreach ($best as $h) {
            $out['hits'][] = ['module' => $h['module'], 'label' => (string) ($catalog[$h['module']]['label'] ?? $h['module']),
                'id' => $h['record_id'], 'title' => $titles[$h['module']][$h['record_id']] ?? '', 'field' => $h['field'],
                'score' => round($h['score'], 4)];
        }

        return ['ok' => true, 'partial' => $near['partial'] || $stale] + $out;
    }
}
