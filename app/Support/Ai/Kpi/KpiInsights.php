<?php

namespace App\Support\Ai\Kpi;

use App\Models\KpiDef;
use App\Models\User;
use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Reports\ProjectReportDigest;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Insights\KpiCentre;
use App\Support\Platform\SchemaCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **مؤشّراتُ أداءٍ ذكيّة — لا «يعدّل الرقم» بل يفسّره ويقترح** (docs/ai-hub/47 §العمود هـ — المرحلة ٦).
 *
 * القيمةُ الفعليّة للمؤشّر **محسوبةٌ من البيانات** (`hub_kpi_value`)، وتعديلُها يدوياً غشٌّ للمؤشّر. فالذكاءُ هنا:
 *  - **يفسّر الانحراف:** لكلِّ مؤشّرٍ خارج هدفه أو لا يُقاس — لماذا، من معادلته وتاريخه.
 *  - **يقترح إجراءات** (ثلاثةٌ على الأكثر) تُقرأ على صفحة المؤشّرات.
 *  - **يقترح هدفاً واقعيّاً** من تاريخ ٩٠ يوماً: رقمٌ في مدى نوع المؤشّر، يبتعد عن الحاليّ ٥٪ على الأقل، بسببه —
 *    ويعتمده من يملك باني المؤشّرات بنقرة (يُكتب الهدفُ و«أساسُه» ويُدقَّق) أو يرفضه.
 *  - **يقترح مؤشّراتٍ ناقصة** (خمسةٌ على الأكثر) من الوحدات المتاحة والمؤشّرات القائمة — نصٌّ لا صيغة:
 *    بناءُ المعادلة يبقى في الباني بمدقّقه.
 * أسبوعيٌّ (أو حين تتغيّر بصمةُ المؤشّر)، ومطفأٌ افتراضاً.
 */
final class KpiInsights
{
    public const FEATURE = 'kpi_insights';

    public const AUDIT_TARGET = 'اعتماد هدف مؤشر مقترح';

    public const HISTORY_DAYS = 90;

    public const MAX_KPIS = 15;

    public const SYSTEM = 'أنت محلّلُ مؤشّرات أداء. تتسلّم مؤشّراً واحداً: اسمَه ومعادلتَه (نصّاً) ووحدتَه واتّجاهَه الجيّد ("up" الأعلى أفضل، "down" الأدنى أفضل) '
        . 'وقيمتَه الحاليّة وهدفَه وسلسلةَ قيمه الأسبوعيّة. أعِد: {"explanation": جملتان أو ثلاث تفسّران الوضعَ من البيانات (لا تخمين)، '
        . '"actions": [ثلاثةُ إجراءاتٍ عمليّة على الأكثر لتحسينه]، "target": {"value": هدفٌ واقعيٌّ مقترح للدورة القادمة من التاريخ أو null إن كان الحاليّ مناسباً، '
        . '"why": جملة} }.';

    public const CATALOG_SYSTEM = 'أنت مستشارُ مؤشّرات أداء. تتسلّم المؤشّراتِ القائمة (اسماً ومعادلةً) ووحداتِ النظام المتاحة. '
        . 'اقترح حتى خمسة مؤشّراتٍ **ناقصةٍ ومفيدة** لا تكرّر القائم، كلٌّ منها يُبنى من وحدةٍ متاحة. '
        . 'الشكل: {"ideas": [{"name": اسمٌ قصير، "why": لماذا يفيد، "module": مفتاحُ الوحدة من القائمة}]}.';

    public static function ready(): bool
    {
        return SchemaCache::hasTable('kpi_insights') && SchemaCache::hasTable('kpi_defs');
    }

    public static function enabled(): bool
    {
        return self::ready() && (string) setting('ai.kpi_insights', '0') === '1';
    }

    /** @return array{analysed: int, skipped: int, failed: int, ideas: int, code: ?string} */
    public static function run(bool $dry = false, bool $force = false, ?User $actor = null): array
    {
        $out = ['analysed' => 0, 'skipped' => 0, 'failed' => 0, 'ideas' => 0, 'code' => null];
        if (! self::ready()) return $out;

        $rows = collect(KpiCentre::rows($actor, false))->filter(fn ($r) => $r['active'] ?? true)->values();
        if ($rows->isEmpty()) return $out;
        $series = hub_metric_bulk_series(KpiCentre::MODULE, $rows->pluck('id')->all(), [KpiCentre::METRIC], self::HISTORY_DAYS);
        $prev = DB::table('kpi_insights')->where('kind', 'kpi')->get()->keyBy('kpi_id');

        $gc = null;
        foreach ($rows as $r) {
            if ($out['analysed'] + $out['failed'] >= self::MAX_KPIS) break;
            $weekly = self::weekly($series[$r['id']][KpiCentre::METRIC] ?? []);
            $worth = in_array($r['health'], ['off', 'dead', 'nodata'], true) || count($weekly) >= 6;
            if (! $worth) { $out['skipped']++; continue; }

            $payload = ['name' => FollowUp::clip($r['name'], 150), 'formula' => FollowUp::clip($r['explain'], 300),
                'unit' => $r['unit'], 'kind' => $r['kind_label'], 'period' => $r['period'], 'good' => $r['good'],
                'value' => $r['value'], 'target' => $r['target'], 'variance_pct' => $r['variance_pct'], 'health' => $r['health'],
                'dead_filters' => array_map(fn ($d) => ($d['label'] ?? '') . ': ' . ($d['status'] ?? ''), (array) $r['dead']),
                'weekly' => $weekly];
            $hash = hash('sha256', (string) json_encode([$payload['formula'], $r['target'], $r['health'], array_slice($weekly, 0, -1)]));
            $old = $prev[$r['id']] ?? null;
            if (! $force && $old && $old->status === 'ok' && $old->hash === $hash
                && $old->generated_at && now()->diffInDays(\Illuminate\Support\Carbon::parse((string) $old->generated_at), true) < 7) {
                $out['skipped']++;
                continue;
            }
            if ($dry) { $out['analysed']++; continue; }

            if ($gc === null) {
                $opened = self::session($actor);
                if (is_string($opened)) return ['code' => $opened] + $out;
                $gc = $opened;
            }
            $res = FollowUp::call($gc, self::SYSTEM, $payload);
            $parsed = $res['ok'] ? self::parse($res['json'], $r) : null;
            $row = ['hash' => $hash, 'updated_at' => now()];
            if ($parsed === null) {
                $row += ['status' => 'failed', 'error_code' => Str::limit($res['ok'] ? AskFailures::MALFORMED_MODEL_RESPONSE : (string) $res['code'], 60, '')];
                $out['failed']++;
            } else {
                $row += ['status' => 'ok', 'error_code' => null, 'explanation' => $parsed['explanation'],
                    'actions' => json_encode($parsed['actions'], JSON_UNESCAPED_UNICODE),
                    'target' => $parsed['target'] === null ? null : json_encode($parsed['target'], JSON_UNESCAPED_UNICODE),
                    'target_decision' => null, 'generated_at' => now()];
                $out['analysed']++;
            }
            $old
                ? DB::table('kpi_insights')->where('id', $old->id)->update($row)
                : DB::table('kpi_insights')->insert($row + ['id' => (string) Str::uuid(), 'kind' => 'kpi', 'kpi_id' => $r['id'], 'created_at' => now()]);
            if (! $res['ok'] && ! in_array($res['code'], AuditorAi::ITEM_FAILURES, true)) return ['code' => (string) $res['code']] + $out;
        }

        if (! $dry) $out['ideas'] = self::catalog($gc, $rows->all(), $force, $actor);

        return $out;
    }

    /** مؤشّراتٌ ناقصة — نداءٌ واحدٌ أسبوعيّاً (أو بالإجبار) */
    private static function catalog(?GovernedCompletion &$gc, array $rows, bool $force, ?User $actor): int
    {
        $old = DB::table('kpi_insights')->where('kind', 'catalog')->orderBy('id')->first();
        if (! $force && $old && $old->generated_at && now()->diffInDays(\Illuminate\Support\Carbon::parse((string) $old->generated_at), true) < 7) return 0;

        $modules = collect(hub_modules())->map(fn ($m, $k) => ['key' => $k, 'label' => $m['label']])->values()->take(80)->all();
        if ($gc === null) {
            $opened = self::session($actor);
            if (is_string($opened)) return 0;
            $gc = $opened;
        }
        $res = FollowUp::call($gc, self::CATALOG_SYSTEM, ['existing' => array_map(fn ($r) => ['name' => $r['name'], 'formula' => $r['explain']], $rows),
            'modules' => $modules]);
        if (! $res['ok']) return 0;

        $ideas = [];
        foreach (array_slice(is_array($res['json']['ideas'] ?? null) ? array_values($res['json']['ideas']) : [], 0, 5) as $i) {
            if (! is_array($i) || ! is_scalar($i['name'] ?? null) || hub_mod((string) ($i['module'] ?? '')) === null) continue;
            $ideas[] = ['name' => AuditorAi::str($i['name'], 120), 'why' => AuditorAi::str($i['why'] ?? '', 300), 'module' => (string) $i['module']];
        }
        $row = ['ideas' => json_encode($ideas, JSON_UNESCAPED_UNICODE), 'status' => 'ok', 'generated_at' => now(), 'updated_at' => now()];
        $old
            ? DB::table('kpi_insights')->where('id', $old->id)->update($row)
            : DB::table('kpi_insights')->insert($row + ['id' => (string) Str::uuid(), 'kind' => 'catalog', 'created_at' => now()]);

        return count($ideas);
    }

    private static function session(?User $actor): GovernedCompletion|string
    {
        $profile = ProjectReportDigest::profile();
        if ($profile === null) return AskFailures::MODEL_UNAVAILABLE;
        $auth = GovernedCompletion::authorize($actor, $profile, self::FEATURE, 'kpi:' . Str::uuid());
        if (! $auth['ok']) return (string) ($auth['code'] ?: AskFailures::POLICY_DENIED);
        $gov = $auth['gov'];
        if ($actor === null) $gov['user'] = $gov['user_id'] = $gov['role_id'] = $gov['company_id'] = null;

        return GovernedCompletion::open($profile, $gov, [
            'feature' => AiPurposes::DIGEST, 'max_calls' => self::MAX_KPIS + 1, 'max_output' => 700, 'in_tokens' => 1500,
        ]);
    }

    /** سلسلةٌ أسبوعيّة (متوسّطُ كلِّ أسبوع) من النقاط اليوميّة — ثلاثَ عشرةَ نقطةً على الأكثر */
    public static function weekly(array $points): array
    {
        $by = [];
        foreach ($points as $p) {
            $at = $p['at'] instanceof \DateTimeInterface ? $p['at'] : \Illuminate\Support\Carbon::parse((string) $p['at']);
            $by[$at->format('o-\WW')][] = (float) $p['value'];
        }
        ksort($by);
        $out = [];
        foreach ($by as $w => $vals) $out[] = ['week' => $w, 'v' => round(array_sum($vals) / count($vals), 2)];

        return array_slice($out, -13);
    }

    /**
     * الردُّ يُقرأ ولا يُصدَّق: تفسيرٌ غيرُ فارغ، وإجراءاتٌ محدودة، وهدفٌ في مدى النوع ويبتعد ٥٪ على الأقل — وإلا لا هدف.
     *
     * @return array{explanation: string, actions: list<string>, target: ?array{value: float, why: string}}|null
     */
    public static function parse(array $json, array $row): ?array
    {
        $exp = is_scalar($json['explanation'] ?? null) ? AuditorAi::str($json['explanation'], 700) : '';
        if ($exp === '') return null;
        $actions = array_values(array_filter(array_map(fn ($a) => is_scalar($a) ? AuditorAi::str($a, 250) : '',
            array_slice(is_array($json['actions'] ?? null) ? array_values($json['actions']) : [], 0, 3))));

        $target = null;
        $t = $json['target'] ?? null;
        if (is_array($t) && is_numeric($t['value'] ?? null)) {
            $v = (float) $t['value'];
            $lim = KpiCentre::kindDefaults((string) ($row['kind'] ?? ''));
            $inRange = $v >= (float) ($lim['min'] ?? 0) && (! isset($lim['max']) || $v <= (float) $lim['max']);
            $cur = $row['target'];
            $far = $cur === null || abs($v - (float) $cur) >= max(0.000001, abs((float) $cur) * 0.05);
            if ($inRange && $far) $target = ['value' => round($v, 2), 'why' => is_scalar($t['why'] ?? null) ? AuditorAi::str($t['why'], 300) : ''];
        }

        return ['explanation' => $exp, 'actions' => $actions, 'target' => $target];
    }

    /** @return array<string, object> رؤى المؤشّرات بمعرّف المؤشّر */
    public static function byKpi(): array
    {
        if (! self::ready()) return [];

        return DB::table('kpi_insights')->where('kind', 'kpi')->where('status', 'ok')->get()->keyBy('kpi_id')->all();
    }

    public static function ideas(): array
    {
        if (! self::ready()) return [];

        return (array) json_decode((string) DB::table('kpi_insights')->where('kind', 'catalog')->orderBy('id')->value('ideas'), true);
    }

    /**
     * قرارُ الهدف المقترح: `applied` يكتب الهدفَ وأساسَه في المؤشّر (بأثرٍ في التدقيق)، و`rejected` يُسجَّل وحدَه.
     * الحارسُ في المتحكّم (بابُ باني المؤشّرات نفسُه).
     */
    public static function decideTarget(User $u, string $kpiId, string $action): array
    {
        $row = DB::table('kpi_insights')->where('kind', 'kpi')->where('kpi_id', $kpiId)->orderBy('id')->first();
        $t = $row ? (array) json_decode((string) $row->target, true) : [];
        if ($row === null || ! isset($t['value']) || $row->target_decision !== null) return ['ok' => false, 'why' => 'لا هدفَ مقترحاً مفتوحاً'];
        $k = KpiDef::query()->find($kpiId);
        if (! $k instanceof KpiDef) return ['ok' => false, 'why' => 'المؤشّرُ غيرُ موجود'];

        if ($action === 'applied') {
            $before = $k->target;
            $k->target = $t['value'];
            if (hub_has_col('kpi_defs', 'target_basis')) {
                // «يدويّ» بقاموس الباني (policy · manual · baseline): إنسانٌ اعتمده — والملاحظةُ تقول مصدرَ المقترح
                $k->target_basis = 'manual';
                $k->target_note = Str::limit('اعتُمد من مقترح الذكاء: ' . ($t['why'] ?? ''), 250, '…');
            }
            $k->save();
            hub_audit(self::AUDIT_TARGET, null, $k->id, $k->name, ['before' => ['target' => $before], 'after' => ['target' => $t['value'], 'via' => 'ai']]);
        }
        DB::table('kpi_insights')->where('id', $row->id)->update(['target_decision' => json_encode(
            ['action' => $action, 'by' => (string) $u->id, 'at' => now()->toDateTimeString(), 'value' => $t['value']], JSON_UNESCAPED_UNICODE),
            'updated_at' => now()]);

        return ['ok' => true];
    }
}
