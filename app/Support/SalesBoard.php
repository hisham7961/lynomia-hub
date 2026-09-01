<?php

namespace App\Support;

use App\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * لوحةُ المبيعات وتحليلاتُ العروض (CPQ د) — أرقامٌ ذاتُ معنًى تجاريّ لا زخرفة.
 *
 * **لا محرّكَ عملات في النظام**، فكلُّ مبلغٍ يُجمَّع **مجمَّعاً بالعملة** (لا خلطَ
 * جنيهٍ بدولار)، وتُوسَم «مختلطة» عند تعدّدها. القيمُ من `quotes` القائمة و`clients`
 * (خطّ الأنابيب) — بلا جدولٍ جديد. تُقرأ منطَّقةً بنطاق المستخدم وصلاحيته.
 */
class SalesBoard
{
    /** حالاتٌ مقبولة/مربوحة */
    protected const ACCEPTED = ['مقبول', 'محوّل'];
    /** حالاتٌ مرسَلةٌ للعميل (قيد التفاوض) */
    protected const SENT = ['مُرسل', 'قيد التفاوض', 'اطُّلع عليه', 'طُلب تعديل'];
    /** حالاتٌ داخليةٌ قبل الإرسال */
    protected const DRAFT = ['مسودة', 'مراجعة داخلية', 'معتمد'];
    /** حالاتٌ خاسرة/منتهية */
    protected const LOST = ['مرفوض', 'منتهي', 'ملغى'];

    public static function data(): array
    {
        return hub_screen('sales.dash', 120, function () {
            $q = fn () => hub_scope(Quote::whereNull('deleted_at'), 'quotes');
            $rows = (clone $q())->get([
                'id', 'status', 'total', 'cost', 'discount', 'mrr', 'arr', 'currency',
                'owner_id', 'sent_at', 'accepted_at', 'valid', 'meta',
            ]);

            $accepted = $rows->whereIn('status', self::ACCEPTED);
            $sent = $rows->whereIn('status', self::SENT);
            $draft = $rows->whereIn('status', self::DRAFT);
            $lost = $rows->whereIn('status', self::LOST);

            // معدّلُ الفوز: مقبول ÷ (مقبول + خاسر) — على المحسوم فقط
            $decided = $accepted->count() + $lost->count();
            $winRate = $decided > 0 ? (int) round($accepted->count() * 100 / $decided) : null;

            // تحويلُ العرض إلى مشروع: كم من المقبول صار مشروعاً فعلاً
            $converted = $accepted->filter(fn ($r) => ! empty(((array) $r->meta)['project_id']))->count();
            $convRate = $accepted->count() > 0 ? (int) round($converted * 100 / $accepted->count()) : null;

            // متوسّطُ الهامش والخصم على المقبول (الهامش داخليّ)
            $marg = $accepted->map(fn ($r) => (float) $r->total > 0 ? round(((float) $r->total - (float) $r->cost) / (float) $r->total * 100, 1) : null)
                ->filter(fn ($m) => $m !== null);
            $avgMargin = $marg->isNotEmpty() ? round($marg->avg(), 1) : null;
            $discs = $accepted->map(fn ($r) => (float) $r->total + (float) $r->discount > 0
                ? round((float) $r->discount / ((float) $r->total + (float) $r->discount) * 100, 1) : 0.0);
            $avgDisc = $discs->isNotEmpty() ? round($discs->avg(), 1) : null;

            // متوسّطُ أيام القبول (من الإرسال للقبول)
            $days = $accepted->filter(fn ($r) => $r->sent_at && $r->accepted_at)
                ->map(fn ($r) => \Illuminate\Support\Carbon::parse($r->sent_at)->diffInDays(\Illuminate\Support\Carbon::parse($r->accepted_at)));
            $avgDays = $days->isNotEmpty() ? (int) round($days->avg()) : null;

            // ينتهي قريباً: عروضٌ مفتوحةٌ صلاحيتُها خلال ٧ أيام
            $openStatuses = array_merge(self::SENT, self::DRAFT);
            $expiring = $rows->whereIn('status', $openStatuses)->filter(function ($r) {
                if (! $r->valid) return false;
                $d = \Illuminate\Support\Carbon::parse($r->valid);

                return $d->gte(now()->startOfDay()) && $d->lte(now()->addDays(7)->endOfDay());
            })->count();

            return [
                'counts' => [
                    'draft' => $draft->count(), 'sent' => $sent->count(),
                    'accepted' => $accepted->count(), 'lost' => $lost->count(),
                    'expiring' => $expiring,
                ],
                'valueByCur' => [
                    'draft' => self::byCur($draft), 'sent' => self::byCur($sent), 'accepted' => self::byCur($accepted),
                ],
                'recurringByCur' => [
                    'mrr' => self::byCur($accepted, 'mrr'), 'arr' => self::byCur($accepted, 'arr'),
                ],
                'winRate' => $winRate, 'convRate' => $convRate, 'converted' => $converted,
                'avgMargin' => $avgMargin, 'avgDisc' => $avgDisc, 'avgDays' => $avgDays,
                'byOwner' => self::byOwner($accepted),
                'lostReasons' => self::lostReasons(),
                'pipeline' => self::pipeline(),
            ];
        }, ['quotes', 'clients']);
    }

    /** مجموعُ حقلٍ مجمَّعاً بالعملة (افتراضاً total) */
    protected static function byCur($rows, string $field = 'total'): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cur = filled($r->currency) ? (string) $r->currency : (string) setting('app.currency', 'د.ك');
            $out[$cur] = round(($out[$cur] ?? 0) + (float) $r->{$field}, 3);
        }
        arsort($out);

        return $out;
    }

    /** أعلى المسؤولين بقيمة المقبول وعدده (بالعملة الغالبة) */
    protected static function byOwner($accepted): array
    {
        $g = $accepted->groupBy('owner_id')->map(function ($rows) {
            $cur = hub_cur_label($rows->pluck('currency'));

            return ['count' => $rows->count(), 'value' => round($rows->sum(fn ($r) => (float) $r->total), 3),
                    'cur' => $cur['cur'], 'mixed' => $cur['mixed']];
        })->sortByDesc('value')->take(10);
        $names = hub_ref_labels('users', $g->keys()->filter()->values()->all());

        return $g->map(fn ($v, $k) => $v + ['name' => $names[$k] ?? '—'])->values()->all();
    }

    /** أسبابُ الخسارة من خطّ أنابيب العملاء (stage=خسارة) */
    protected static function lostReasons(): array
    {
        if (! Schema::hasTable('clients')) return [];
        try {
            return hub_scope(DB::table('clients')->whereNull('deleted_at'), 'clients')
                ->where('stage', 'خسارة')->whereNotNull('lost_reason')
                ->select('lost_reason', DB::raw('COUNT(*) as n'))
                ->groupBy('lost_reason')->orderByDesc('n')->limit(8)
                ->get()->map(fn ($r) => ['reason' => $r->lost_reason, 'n' => (int) $r->n])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * تنبّؤُ خطّ الأنابيب (مرجَّح): مراحلُ العميل المفتوحة × احتمالِ الإغلاق.
     * القيمُ بلا عملةٍ صريحةٍ تُنسَب للعملة الافتراضية (لا محرّك تحويل).
     */
    protected static function pipeline(): array
    {
        if (! Schema::hasTable('clients')) return ['weighted' => 0, 'open' => 0];
        $open = ['عميل محتمل', 'تم التواصل', 'عرض سعر', 'تفاوض'];
        $rows = hub_scope(DB::table('clients')->whereNull('deleted_at'), 'clients')
            ->whereIn('stage', $open)->get(['value', 'prob', 'stage']);
        $weighted = round($rows->sum(fn ($r) => (float) $r->value * (float) ($r->prob ?: 0) / 100), 3);
        $raw = round($rows->sum(fn ($r) => (float) $r->value), 3);

        return ['weighted' => $weighted, 'raw' => $raw, 'open' => $rows->count(),
                'cur' => (string) setting('app.currency', 'د.ك')];
    }
}
