<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Engagement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **ربحيةُ الارتباط وصحةُ العميل — أرقامٌ من مصادرها لا من خانة تُملأ.**
 *
 * إيرادُ الارتباط من مدفوعات مستنداته المالية على مشاريعه، وتكلفتُه من محرّك
 * ربحية المشروع القائم (`hub_project_pl`: ساعاتُ الفريق والسيرفرات والأدوات
 * والمشتريات) — لا رقمَ ربحٍ يُكتب باليد ثم يَعِد بما لا تشهد به الدفاتر.
 * وصحةُ العميل إشارةٌ مركّبة تُسمّي أسبابَها — لا درجةٌ سحرية تقرر وحدها.
 */
class Engagements
{
    public const TTL = 300;

    /** ربحيةُ ارتباط: تجميعُ ربحية مشاريعه + مشترياتُ عميله المفوترة وغير المفوترة */
    public static function pl(Engagement $e): array
    {
        $projects = $e->projects()->whereNull('deleted_at')->pluck('id');

        $revenue = 0.0;
        $collected = 0.0;
        $cost = 0.0;
        $hours = 0.0;
        $mixed = false;
        foreach ($projects as $pid) {
            $pl = hub_project_pl($pid);
            $revenue += (float) ($pl['revenue']['invoiced'] ?? 0);
            $collected += (float) ($pl['revenue']['collected'] ?? 0);
            $cost += (float) ($pl['cost']['total'] ?? 0);
            $hours += (float) ($pl['hours']['logged'] ?? 0);
            $mixed = $mixed || (bool) ($pl['mixed'] ?? false);
        }

        // مشترياتٌ لصالح العميل خارج مشاريع الارتباط (استضافةٌ عامة مثلاً):
        // المفوترةُ منها بمبلغها للعميل، وغيرُ المفوترة تكلفةٌ صامتة تُسمّى
        $extra = ['billable' => 0.0, 'unbilled' => 0.0];
        if ($e->client_id && Schema::hasTable('purchases')) {
            $rows = DB::table('purchases')->whereNull('deleted_at')
                ->where('client_id', $e->client_id)
                ->when($projects->isNotEmpty(), fn ($q) => $q->whereNotIn('project_id', $projects))
                ->whereNotIn('status', ['ملغى', 'مرتجع'])
                ->get(['amount', 'billable', 'markup', 'charge']);
            foreach ($rows as $r) {
                if ($r->billable) {
                    $extra['billable'] += self::charge($r);
                } else {
                    $extra['unbilled'] += (float) ($r->amount ?? 0);
                }
            }
        }

        $profit = $revenue - $cost;

        return [
            'revenue' => round($revenue, 2),
            'collected' => round($collected, 2),
            'cost' => round($cost, 2),
            'profit' => round($profit, 2),
            'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
            'hours' => round($hours, 1),
            'contract' => (float) ($e->revenue ?? 0),       // القيمة التعاقدية للمقارنة
            'projects' => $projects->count(),
            'extraBillable' => round($extra['billable'], 2),
            'extraUnbilled' => round($extra['unbilled'], 2),
            'mixed' => $mixed,
        ];
    }

    /** مبلغُ فوترة الشراء: اليدويُّ يغلب، وإلا الإجمالي مضروباً بالهامش */
    public static function charge(object $p): float
    {
        if (($p->charge ?? null) !== null && (float) $p->charge > 0) return (float) $p->charge;
        $amount = (float) ($p->amount ?? 0);
        $markup = (float) ($p->markup ?? 0);

        return round($amount * (1 + max(0, $markup) / 100), 3);
    }

    /**
     * صحةُ العميل: أخضر/أصفر/أحمر **بأسبابٍ مسمّاة** — تذاكرُ حرجة مفتوحة،
     * فواتيرُ متأخرة، مشاريعُ متعثرة، وتجديدٌ يقترب بلا حسم.
     */
    public static function health(Client $c): array
    {
        return hub_screen('eng:health:' . $c->id, self::TTL, function () use ($c) {
            $why = [];

            if (Schema::hasTable('tickets')) {
                $n = DB::table('tickets')->whereNull('deleted_at')->where('client_id', $c->id)
                    ->whereIn('priority', ['عاجلة', 'عاجلة جداً'])
                    ->whereNotIn('status', ['تم الحل', 'مغلقة'])->count();
                if ($n) $why[] = ['w' => 2, 'txt' => $n . ' تذكرة عاجلة مفتوحة'];
            }

            if (Schema::hasTable('fin_documents')) {
                $n = DB::table('fin_documents')->whereNull('deleted_at')->where('client_id', $c->id)
                    ->whereIn('kind', config('hub.fin.income', []))
                    ->whereNotIn('state', ['مدفوعة', 'ملغاة', 'مسودة'])
                    ->whereNotNull('due')->where('due', '<', now()->toDateString())->count();
                if ($n) $why[] = ['w' => 2, 'txt' => $n . ' فاتورة متأخرة السداد'];
            }

            if (Schema::hasTable('projects')) {
                $n = DB::table('projects')->whereNull('deleted_at')->where('client_id', $c->id)
                    ->where('status', 'متوقف')->count();
                if ($n) $why[] = ['w' => 1, 'txt' => $n . ' مشروع متوقف'];
                $late = DB::table('projects')->whereNull('deleted_at')->where('client_id', $c->id)
                    ->whereNotIn('status', ['مكتمل', 'ملغى'])
                    ->whereNotNull('launch_exp')->where('launch_exp', '<', now()->toDateString())->count();
                if ($late) $why[] = ['w' => 1, 'txt' => $late . ' مشروع تجاوز موعد إطلاقه'];
            }

            if (Schema::hasTable('engagements')) {
                $n = DB::table('engagements')->whereNull('deleted_at')->where('client_id', $c->id)
                    ->whereNotIn('status', ['منتهٍ', 'ملغى'])
                    ->whereNotNull('renewal')
                    ->whereBetween('renewal', [now()->toDateString(), now()->addDays(30)->toDateString()])
                    ->count();
                if ($n) $why[] = ['w' => 1, 'txt' => $n . ' ارتباط يستحق التجديد خلال ٣٠ يوماً'];
            }

            $score = array_sum(array_column($why, 'w'));
            $tone = $score >= 3 ? 'أحمر' : ($score >= 1 ? 'أصفر' : 'أخضر');

            return ['tone' => $tone, 'why' => array_column($why, 'txt')];
        }, ['tickets', 'fin_documents', 'projects', 'engagements']);
    }
}
