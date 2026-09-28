<?php

namespace App\Support\Ai\Brief;

use App\Support\Ai\Ask\AskFailures;
use App\Support\Ai\Auditor\AuditorAi;
use App\Support\Ai\FollowUp\FollowUp;
use App\Support\Ai\GovernedCompletion;
use App\Support\Ai\Proposals\ProposalService;
use App\Support\Ai\Reports\ProjectReportDigest;
use App\Support\Ai\Routing\AiPurposes;
use App\Support\Platform\SchemaCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * **تقديرُ الطلبات الواردة** (docs/ai-hub/47 §العمود و — المرحلة ٧): طلبٌ جديدٌ بلا تقديرٍ لأيّامه أو تكلفته أو أولويّته
 * يُقارَن بما نُفِّذ من طلباتٍ من نوعه، فيصير التقديرُ **اقتراحاً** في صندوق الاقتراحات (`request_days` ·
 * `request_cost` · `request_priority`) — بدليلٍ مقتبسٍ من نصّ الطلب نفسِه يتحقّق الخادمُ منه، ويعتمده المراجِع.
 */
final class RequestEstimator
{
    public const FEATURE = 'request_estimate';

    public const PER_RUN = 10;

    public const HISTORY = 15;

    /** علامةُ «قُدِّر» في الخبيئة (٣٠ يوماً) — طلبٌ لم يثبت دليلُ تقديره لا يُعاد كلَّ يوم */
    public const TRIED = 'ai:request-estimate:';

    public const SYSTEM = 'تتسلّم طلباً داخليّاً جديداً ("request": عنوانٌ ووصفٌ ومبرّرٌ ونوعٌ وأولويّةُ الطالب) و"history": طلباتٍ منفَّذةً من نوعه '
        . 'بتقديراتها. قدّر للطلب الجديد: {"days": عددُ الأيام أو null، "cost": التكلفةُ بعملة التاريخ أو null، "priority": واحدةٌ من '
        . '"عاجل" "عالية" "متوسطة" "منخفضة" أو null، "why": جملتان تشرحان التقديرَ من التاريخ، "quote": نصٌّ منسوخٌ حرفياً من عنوان الطلب أو وصفه '
        . 'يبرّر التقدير}. لا تقدّر ما لا يسنده التاريخ — null أصدقُ من رقمٍ مخترَع.';

    public static function enabled(): bool
    {
        return (string) setting('ai.request_estimates', '0') === '1' && ProposalService::enabled() && SchemaCache::hasTable('internal_requests');
    }

    /** @return array{requests: int, proposals: int, code: ?string} */
    public static function run(bool $dry = false): array
    {
        $out = ['requests' => 0, 'proposals' => 0, 'code' => null];
        if (! SchemaCache::hasTable('internal_requests')) return $out;

        $cands = DB::table('internal_requests')->whereNull('deleted_at')->whereIn('status', ['جديد', 'قيد التقييم'])
            ->where('created_at', '>=', now()->subDays(30)->toDateTimeString())
            ->where(fn ($w) => $w->whereNull('est_days')->orWhereNull('est_cost')->orWhereNull('prio_final'))
            ->orderBy('created_at')->orderBy('id')->limit(100)
            ->get(['id', 'title', 'description', 'justification', 'req_type', 'prio_req', 'est_days', 'est_cost', 'prio_final']);

        $gc = null;
        foreach ($cands as $r) {
            if ($out['requests'] >= self::PER_RUN) break;
            // سبق اقتراحٌ لهذا الطلب (مفتوحٌ أو مقرَّر)، أو قُدِّر فلم يثبت دليلُه ⇒ لا يُعاد (نداءٌ واحدٌ لكلِّ طلب)
            if (ProposalService::ready() && DB::table('ai_proposals')->where('module', 'requests')->where('record_id', $r->id)->exists()) continue;
            if (\Illuminate\Support\Facades\Cache::has(self::TRIED . $r->id)) continue;
            $history = DB::table('internal_requests')->whereNull('deleted_at')->where('status', 'منفَّذ')
                ->where('req_type', $r->req_type)->where(fn ($w) => $w->whereNotNull('est_days')->orWhereNotNull('est_cost'))
                ->orderByDesc('created_at')->orderByDesc('id')->limit(self::HISTORY)->get(['title', 'est_days', 'est_cost', 'prio_final'])
                ->map(fn ($h) => array_filter(['title' => FollowUp::clip($h->title, 150), 'days' => $h->est_days, 'cost' => $h->est_cost,
                    'priority' => $h->prio_final], fn ($v) => $v !== null && $v !== ''))->all();
            if ($history === []) continue;   // لا تاريخَ من نوعه ⇒ لا تقدير
            $out['requests']++;
            if ($dry) continue;

            if ($gc === null) {
                $profile = ProjectReportDigest::profile();
                if ($profile === null) return ['code' => AskFailures::MODEL_UNAVAILABLE] + $out;
                $auth = GovernedCompletion::authorize(null, $profile, self::FEATURE, 'estimate:' . Str::uuid());
                if (! $auth['ok']) return ['code' => (string) ($auth['code'] ?: AskFailures::POLICY_DENIED)] + $out;
                $gov = $auth['gov'];
                $gov['user'] = $gov['user_id'] = $gov['role_id'] = $gov['company_id'] = null;
                $gc = GovernedCompletion::open($profile, $gov, ['feature' => AiPurposes::DIGEST, 'max_calls' => self::PER_RUN,
                    'max_output' => 500, 'in_tokens' => 1500]);
            }
            \Illuminate\Support\Facades\Cache::put(self::TRIED . $r->id, true, now()->addDays(30));
            $res = FollowUp::call($gc, self::SYSTEM, ['request' => array_filter(['title' => FollowUp::clip($r->title, 200),
                'description' => FollowUp::clip($r->description, 800), 'justification' => FollowUp::clip($r->justification, 400),
                'type' => $r->req_type, 'requested_priority' => $r->prio_req]), 'history' => $history]);
            if (! $res['ok']) {
                if (! in_array($res['code'], AuditorAi::ITEM_FAILURES, true)) return ['code' => (string) $res['code']] + $out;
                continue;
            }

            $j = $res['json'];
            $why = is_scalar($j['why'] ?? null) ? AuditorAi::str($j['why'], 500) : '';
            $ev = [['module' => 'requests', 'record_id' => (string) $r->id, 'quote' => is_string($j['quote'] ?? null) ? $j['quote'] : '']];
            $wanted = ['request_days' => [$r->est_days, $j['days'] ?? null], 'request_cost' => [$r->est_cost, $j['cost'] ?? null],
                'request_priority' => [$r->prio_final, $j['priority'] ?? null]];
            foreach ($wanted as $kind => [$current, $value]) {
                if ($current !== null && $current !== '') continue;   // ما قدّره إنسانٌ لا يُقترح فوقه
                if ($value === null || $value === '') continue;
                if (ProposalService::propose($kind, (string) $r->id, $value, $why, $ev, null, self::FEATURE)['ok']) $out['proposals']++;
            }
        }

        return $out;
    }
}
