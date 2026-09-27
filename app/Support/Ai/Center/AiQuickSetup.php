<?php

namespace App\Support\Ai\Center;

use App\Models\AiBudget;
use App\Models\AiModel;
use App\Models\AiProfile;
use App\Models\AiProfileModel;
use App\Support\Ai\Routing\AiProfiles;
use App\Support\Platform\Settings;
use App\Support\Platform\Tri;

/**
 * **الإعدادُ السريع** — ما بعد مفاتيح المزوّدين (§٩ من الخارطة): يملأ الأغراضَ **الفارغةَ وحدَها** من النماذج
 * التي تعرفها البوّابةُ فعلاً، ويُنشئ ميزانيّةً شهريّةً عامّةً إن لم تكن ميزانيّة، ويفعّل البحثَ بالمعنى بطلبٍ صريح.
 *
 *  · **لا يمسّ سلسلةً ضبطها المدير** — غرضٌ فيه نموذجٌ واحدٌ على الأقلّ يُترك كما هو.
 *  · **لا يخترع قدرة**: الأهليّةُ حكمُ `AiProfiles::eligibility` نفسُه (المجهولُ لا يُرقّى بالصمت)، والمُعطَّلُ
 *    وما لا يُوجَّه إليه (`UNROUTABLE`) خارجَ الترشيح.
 *  · **الترتيبُ مُعلَن**: المُثبَتُ صحّةً أوّلاً، ثمّ ما يُصدر طلبَ أداةٍ للأغراض المحاوِرة؛ ثمّ السعر — الأرخصُ
 *    للاقتصاديّ والسريع والتضمين، والأعلى (وكيلُ القوّة) للعامّ والبرمجة.
 *  · **مفاتيحُ المزوّدين لا تُضبط هنا** — تلك على الخادم بيد المالك (`/admin/ai/providers`).
 */
final class AiQuickSetup
{
    /** الأغراضُ التي يملؤها — والاستدلالُ والرؤيةُ يشترطان قدرةً مُثبَتةً فلا تُملأ آليّاً */
    public const TARGETS = ['general', 'coding', 'cheap', 'fast', 'embedding'];

    /** فعلُ التدقيق الواحد للإعداد السريع — ثابتٌ لا صياغةٌ حرفيّةٌ في كلِّ نداء */
    public const AUDIT_ACTION = 'إعدادٌ سريعٌ للذكاء';

    /** سقفُ كلِّ غرضٍ من السقف العامّ: شهريّاً ويوميّاً (٪) */
    public const PURPOSE_MONTHLY_PCT = 50;

    public const PURPOSE_DAILY_PCT = 10;

    /** نموذجان: أساسيٌّ واحتياط */
    public const PER_PROFILE = 2;

    /** الميزانيّةُ المقترحة (دولار/شهر) — تُعدَّل قبل التطبيق */
    public const SUGGESTED_MONTHLY_USD = 50;

    /**
     * @return array{profiles: list<array{key:string, label:string, state:string, models:list<string>, why:?string}>,
     *               budget: ?array{usd:int}, brain: bool}
     */
    public static function plan(int $monthlyUsd = self::SUGGESTED_MONTHLY_USD): array
    {
        AiProfiles::seed();
        $out = ['profiles' => [], 'budget' => null, 'brain' => false];
        foreach (self::TARGETS as $key) {
            $p = AiProfile::query()->where('key', $key)->orderBy('id')->first();
            if ($p === null) continue;
            if (AiProfileModel::query()->where('profile_id', $p->id)->exists()) {
                $out['profiles'][] = ['key' => $key, 'label' => (string) $p->label, 'state' => 'configured', 'models' => [], 'why' => null];
                continue;
            }
            $picks = self::pick(self::candidates($p))->map(fn (AiModel $m) => (string) $m->litellm_model_name)->values()->all();
            $out['profiles'][] = ['key' => $key, 'label' => (string) $p->label, 'state' => $picks ? 'fill' : 'none', 'models' => $picks,
                'why' => $picks ? null : 'لا نموذجَ مُفعَّلاً في البوّابة يصلح لهذا الغرض — أضِف مزوّداً أو اكتشف نماذجَه أوّلاً'];
        }
        if (! AiBudget::query()->exists() && $monthlyUsd > 0) $out['budget'] = ['usd' => $monthlyUsd];
        $out['brain'] = collect($out['profiles'])->contains(fn ($x) => $x['key'] === 'embedding' && $x['state'] !== 'none');

        return $out;
    }

    /**
     * يطبّق الخطّة — وكلُّ ضمٍّ يمرّ بـ`AiProfiles::attach` (أهليّةٌ · حدُّ السلسلة · أثرُ تدقيق).
     *
     * @return array{attached: int, budget: bool, brain: bool, plan: array}
     */
    public static function apply(int $monthlyUsd = self::SUGGESTED_MONTHLY_USD, bool $enableBrain = false): array
    {
        $plan = self::plan($monthlyUsd);
        $attached = 0;
        foreach ($plan['profiles'] as $row) {
            if ($row['state'] !== 'fill') continue;
            $p = AiProfile::query()->where('key', $row['key'])->orderBy('id')->firstOrFail();
            foreach ($row['models'] as $name) {
                $m = AiModel::query()->where('litellm_model_name', $name)->orderBy('id')->first();
                if ($m !== null && AiProfiles::attach($p, $m)['ok']) $attached++;
            }
        }

        $budget = false;
        if ($plan['budget'] !== null) {
            $total = $plan['budget']['usd'] * 1_000_000;
            $make = function (string $key, string $label, string $scope, ?string $scopeId, string $period, int $micro) {
                AiBudget::create(['key' => $key, 'label' => $label, 'scope_type' => $scope, 'scope_id' => $scopeId,
                    'period' => $period, 'limit_micro' => $micro, 'currency' => 'USD', 'enforce' => true, 'enabled' => true,
                    'notes' => 'أُنشئت بالإعداد السريع — عدّلها من «الميزانيّات».']);
            };
            $make('global-monthly', 'سقفُ الشهر العامّ (الإعدادُ السريع)', 'global', null, 'monthly', $total);
            // **ولكلِّ غرضٍ سقفٌ شهريٌّ ويوميّ** (§٩) — فلا يستنفد غرضٌ واحدٌ الشهرَ كلَّه، ولا يومٌ واحدٌ الغرض
            foreach ($plan['profiles'] as $row) {
                if ($row['state'] === 'none') continue;
                $make('purpose-' . $row['key'] . '-monthly', 'غرضُ «' . $row['label'] . '» — شهريّ', 'purpose', $row['key'], 'monthly', intdiv($total * self::PURPOSE_MONTHLY_PCT, 100));
                $make('purpose-' . $row['key'] . '-daily', 'غرضُ «' . $row['label'] . '» — يوميّ', 'purpose', $row['key'], 'daily', intdiv($total * self::PURPOSE_DAILY_PCT, 100));
            }
            hub_audit(self::AUDIT_ACTION, AiBudget::MODULE, 'global-monthly',
                'سقفٌ شهريٌّ عامّ ' . $plan['budget']['usd'] . ' USD، ولكلِّ غرضٍ ' . self::PURPOSE_MONTHLY_PCT . '٪ شهريّاً و' . self::PURPOSE_DAILY_PCT . '٪ يوميّاً');
            $budget = true;
        }

        $brain = false;
        if ($enableBrain && $plan['brain']) {
            Settings::put('brain.enabled', '1', 'quick-setup');
            hub_audit(self::AUDIT_ACTION, 'settings', 'brain.enabled', 'تفعيلُ البحث بالمعنى: brain.enabled = 1');
            $brain = true;
        }

        return ['attached' => $attached, 'budget' => $budget, 'brain' => $brain, 'plan' => $plan];
    }

    /**
     * الأساسيُّ ثمّ الاحتياط — **ومن مزوّدٍ آخرَ إن وُجد** (§٩: «نموذجٌ بديلٌ من مزوّدٍ ثانٍ»): احتياطٌ من المزوّد نفسِه
     * يسقط معه حين يسقط.
     *
     * @return \Illuminate\Support\Collection<int, AiModel>
     */
    private static function pick($cands)
    {
        // مجموعاتٌ مرتّبةٌ سلفاً (لا استعلامات) — الفهرسُ ٠ هو الأوّلُ في الترتيب المُعلَن
        $first = $cands->values()->get(0);
        if ($first === null) return collect();
        $rest = $cands->slice(1)->values();
        $other = $rest->filter(fn (AiModel $m) => (string) $m->provider_id !== (string) $first->provider_id)->values()->get(0)
            ?? $rest->get(0);

        return collect(array_filter([$first, $other]))->take(self::PER_PROFILE);
    }

    /** المرشّحون بترتيبٍ مُعلَن @return \Illuminate\Support\Collection<int, AiModel> */
    private static function candidates(AiProfile $p)
    {
        $cheapFirst = in_array((string) $p->key, ['cheap', 'fast', 'embedding'], true);
        $converses = (string) $p->required_capability === 'chat';

        return AiModel::query()->with('provider')->where('enabled', true)->orderBy('id')->get()
            ->filter(fn (AiModel $m) => $m->provider === null || (bool) $m->provider->enabled)
            ->reject(fn (AiModel $m) => in_array(mb_strtoupper((string) $m->health), AiProfiles::UNROUTABLE, true))
            ->filter(fn (AiModel $m) => AiProfiles::eligibility($p, $m)['ok'])
            ->sort(function (AiModel $a, AiModel $b) use ($cheapFirst, $converses) {
                $healthy = fn (AiModel $m) => in_array(mb_strtoupper((string) $m->health), ['HEALTHY', 'OK'], true) ? 0 : 1;
                $tools = fn (AiModel $m) => $converses && Tri::of(self::fact($m, 'tools')) === true ? 0 : 1;
                $price = fn (AiModel $m) => (float) (((array) $m->pricing)['input_per_1k']['v'] ?? ($cheapFirst ? INF : 0));

                return [$healthy($a), $tools($a), $cheapFirst ? $price($a) : -$price($a), (string) $a->litellm_model_name]
                    <=> [$healthy($b), $tools($b), $cheapFirst ? $price($b) : -$price($b), (string) $b->litellm_model_name];
            })->values();
    }

    private static function fact(AiModel $m, string $cap): mixed
    {
        $f = ((array) $m->capabilities)[$cap] ?? null;

        return is_array($f) ? ($f['v'] ?? null) : $f;
    }
}
