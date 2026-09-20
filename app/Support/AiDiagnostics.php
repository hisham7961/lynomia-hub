<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\AiProvider;

/**
 * **أينَ انقطع الخيط؟** (المرحلة ٢ · W8 · §١١).
 *
 * ── **لماذا شجرةُ قرارٍ لا رسالةُ خطأ؟** ──
 *
 * «‏401 Unauthorized» رسالةٌ صحيحةٌ عديمةُ النفع: أمفتاحُ إدارةِ البوّابةِ
 * خاطئ؟ أم اعتمادُ المزوّدِ عندها؟ الرمزُ واحدٌ والموضعُ مختلفٌ **والإصلاحُ
 * مختلفٌ تماماً**. فمن يقرأ الرمزَ وحدَه يُصلح ما ليس معطوباً.
 *
 * فالشجرةُ أربعُ حلقاتٍ مرتّبةٍ بالاعتماد:
 *
 * ```
 * الشبكةُ ← البوّابةُ ← الاعتمادُ ← النموذج
 * ```
 *
 * **وأوّلُ حلقةٍ مقطوعةٍ تُوقِف القراءة**: لا معنى لفحصِ اعتمادٍ ما دامت
 * البوّابةُ لا تردّ، وعرضُ «الاعتمادُ سليم» حينئذٍ **كذبةٌ مطمئنّة**.
 *
 * ── **ولا نداءَ شبكةٍ هنا** ──
 *
 * تُقرَأ الحالاتُ المخزّنةُ من آخرِ فحصٍ صريح. وصفحةُ تشخيصٍ تتّصل عند كلِّ
 * عرضٍ تصير أبطأَ ما في المركزِ لخدمةٍ متوقّفة — وهي الحالةُ التي تُفتَح فيها.
 *
 * ── **وكلُّ نصٍّ هنا مرّ بـ`Redactor` عند مصدرِه** ──
 *
 * `last_error` يُكتَب بعد `ConnectionProbe::row()` وحدَها، وهي **نقطةُ
 * الاختناقِ الواحدةُ** التي تطمس وتقصّ. فلا تُطمَس هنا مرّةً ثانيةً — ولا
 * تُعرَض حيث لم تُطمَس.
 */
final class AiDiagnostics
{
    /** حالاتُ الحلقة — مرتّبةً من الأسوأ */
    public const LINK_STATES = ['broken', 'unknown', 'ok'];

    /**
     * **أربعُ حلقاتٍ يُقرَأ أوّلُ مقطوعٍ منها.**
     *
     * @return list<array{key: string, label: string, state: string, why: string,
     *                    fix: ?string, route: ?string, halts: bool}>
     */
    public static function chain(): array
    {
        $out = [];

        // ① الشبكةُ وبوّابةُ الخروج — يُقاس قبل كلِّ شيءٍ لأنّ سقوطَه يُفسّر الباقي
        $url  = AiGateway::baseUrl();
        $gate = $url === '' ? ['ok' => false, 'why' => 'لا عنوانَ محفوظ']
            : AiGateway::outboundGate(AiGateway::url('/v1/models'));

        $out[] = [
            'key' => 'network', 'label' => 'الشبكةُ وبوّابةُ الخروج',
            'state' => $url === '' ? 'unknown' : ($gate['ok'] ? 'ok' : 'broken'),
            'why' => $url === ''
                ? 'لا عنوانَ بوّابةٍ محفوظٌ بعد'
                : ($gate['ok']
                    ? 'العنوانُ مسموحٌ بطلبِه من هذا الخادم'
                    : 'حارسُ الخروجِ يمنع هذا العنوان: ' . (string) $gate['why']),
            'fix' => $gate['ok'] ? null : 'العنوانُ المتوقَّعُ بوّابةٌ محلّيّةٌ على 127.0.0.1',
            'route' => 'ai.settings',
        ];

        // ② البوّابة — أتردّ وتقبل مفتاحَ الإدارة؟
        $configured = AiGateway::configured();
        $probed     = AiGateway::probePassed();

        $out[] = [
            'key' => 'gateway', 'label' => 'بوّابةُ النماذج',
            'state' => ! $configured ? 'unknown' : ($probed ? 'ok' : 'broken'),
            'why' => ! $configured
                ? (string) (AiGateway::whyNotReady() ?? 'الإعدادُ ناقص')
                : ($probed
                    ? 'آخرُ فحصٍ نجح — والبوّابةُ تردّ وتقبل مفتاحَ الإدارة'
                    : 'الإعدادُ مكتملٌ **ولم يُختبر الاتصالُ بعد** — لا دليلَ أنّها تردّ'),
            'fix' => $probed ? null : 'اضبط العنوانَ والمفتاحَ ثمّ افحص الاتصال',
            'route' => 'ai.settings',
        ];

        // ③ الاعتمادات — أيقبل المزوّدُ مفاتيحَنا؟
        $providers = AiProvider::query()->orderBy('label')->get();
        $verified  = $providers->where('credential_state', 'verified')->count();
        $missing   = $providers->where('credential_state', 'missing')->count();

        $out[] = [
            'key' => 'credential', 'label' => 'اعتماداتُ المزوّدين',
            'state' => $providers->isEmpty() ? 'unknown' : ($verified > 0 ? 'ok' : ($missing === $providers->count() ? 'broken' : 'unknown')),
            'why' => $providers->isEmpty()
                ? 'لا مزوّدَ مُهيَّأٌ بعد'
                : ($verified > 0
                    ? $verified . ' اعتماداً مُتحقَّقاً من ' . $providers->count()
                    : 'لا اعتمادَ مُتحقَّقٌ — الفاحصُ B يُثبته **ويُنفق رصيداً**'),
            'fix' => $verified > 0 ? null : 'أضِف مزوّداً واعتمادَه ثمّ افحصه',
            'route' => 'ai.providers.index',
        ];

        // ④ النماذج — أمُسجَّلٌ ومُفعَّلٌ وبالغ؟
        $models  = AiModel::query()->count();
        $enabled = AiModel::query()->where('enabled', true)->count();
        $gone    = AiModel::query()->where('health', 'UNAVAILABLE')->count();

        $out[] = [
            'key' => 'model', 'label' => 'النماذج',
            'state' => $models === 0 ? 'unknown' : ($enabled > 0 ? 'ok' : 'broken'),
            'why' => $models === 0
                ? 'لا نموذجَ مُسجَّلٌ بعد'
                : ($enabled > 0
                    ? $enabled . ' نموذجاً مُفعَّلاً من ' . $models
                        . ($gone > 0 ? ' — و' . $gone . ' وُسم متعطّلاً' : '')
                    : 'نماذجُ مُسجَّلةٌ ولا واحدَ مُفعَّل — **والاستيرادُ لا يُفعِّل**'),
            'fix' => $enabled > 0 ? null : 'فعّل ما تحتاجه من النماذجِ المُسجَّلة',
            'route' => 'ai.models.all',
        ];

        // **أوّلُ مقطوعٍ يُوقِف القراءة** — وما بعدَه لا يُقرَأ دليلاً
        $halted = false;
        foreach ($out as $i => $link) {
            $out[$i]['halts'] = false;
            if ($halted) {
                $out[$i]['state'] = 'unknown';
                $out[$i]['why']   = 'لا يُقاس ما دامت حلقةٌ قبلَه مقطوعة — أصلِحها أوّلاً';
                $out[$i]['fix']   = null;
                continue;
            }
            if ($link['state'] === 'broken') { $out[$i]['halts'] = true; $halted = true; }
        }

        return $out;
    }

    /**
     * **آخرُ ما سُجّل من فحوص** — من الإعداداتِ ومن صفوفِ المزوّدين والنماذج.
     *
     * **والترتيبُ يُطلَب صراحةً** بالوقتِ ثمّ بالاسم: `ORDER BY` على وقتٍ
     * تتساوى قيمُه قرعةٌ تفترق بين المحرّكين.
     *
     * @return list<array{scope: string, name: string, up: ?bool, at: ?string,
     *                    ms: ?int, error: ?string}>
     */
    public static function recentProbes(): array
    {
        $rows = [];

        $at = AiGateway::probedAt();
        if ($at !== null || AiGateway::configured()) {
            $rows[] = [
                'scope' => 'البوّابة', 'name' => 'فحصُ الاتصال',
                'up'    => $at === null ? null : AiGateway::probePassed(),
                'at'    => $at === null ? null : (string) $at,
                'ms'    => null, 'error' => null,
            ];
        }

        foreach (AiProvider::query()->orderBy('label')->orderBy('id')->get() as $p) {
            if ($p->last_probe_at === null && $p->last_error === null) continue;
            $rows[] = [
                'scope' => 'مزوّد', 'name' => (string) $p->label,
                'up'    => $p->last_probe_at === null ? null : ($p->last_error === null),
                'at'    => $p->last_probe_at?->toDateTimeString(),
                'ms'    => $p->last_latency_ms === null ? null : (int) $p->last_latency_ms,
                'error' => $p->last_error === null ? null : (string) $p->last_error,
            ];
        }

        foreach (AiModel::query()->orderBy('litellm_model_name')->orderBy('id')->get() as $m) {
            if ($m->last_probe_at === null && $m->last_error === null) continue;
            $rows[] = [
                'scope' => 'نموذج', 'name' => (string) $m->litellm_model_name,
                'up'    => $m->last_probe_at === null ? null
                    : (mb_strtoupper((string) $m->health) === 'CONNECTED'),
                'at'    => $m->last_probe_at?->toDateTimeString(),
                'ms'    => $m->last_latency_ms === null ? null : (int) $m->last_latency_ms,
                'error' => $m->last_error === null ? null : (string) $m->last_error,
            ];
        }

        return $rows;
    }
}
