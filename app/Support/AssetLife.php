<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دورة حياة الأصل — العهدة والضمان والصيانة وقرار الإحلال.
 *
 * كانت حقول الأصل الأغلى ثمناً **ديكوراً**: `price` و`warranty` و`life`
 * و`maint` و`disposal` تُملأ ولا يقرؤها أحد. فالنتيجة:
 *
 *   — جهازٌ انتهى ضمانه أمس، ويُدفع إصلاحه من الجيب وكان مجانياً.
 *   — جهازٌ صُرف على صيانته أكثر من ثمنه، ولا أحد جمع الفواتير فيرى.
 *   — موظفٌ خرج من الشركة وعهدتُه ما زالت باسمه — لا لأن أحداً أخفاها،
 *     بل لأن لا شاشة تسأل «مَن يحمل ماذا؟».
 *   — أصلٌ «تالف» في الدفاتر منذ سنتين بلا تاريخ استبعاد، يُحسَب أصلاً.
 *
 * كل ما هنا مقروءٌ من الحقول القائمة — لا عمود جديد ولا إدخالٌ يُطلب.
 */
class AssetLife
{
    /** عتبة الإحلال: صيانةٌ بلغت نصف ثمن الأصل تُساوي شراءه من جديد تقريباً */
    public const REPLACE_AT = 0.5;
    public const TTL = 300;

    public static function may(): bool
    {
        return hub_can(auth()->user(), 'assets', 'v');
    }

    /** هل يرى القارئ ثمن الأصل؟ المجموع يكشف الحقل المحجوب كما يكشفه الصفّ */
    public static function seesPrice(): bool
    {
        return ! hub_masked('assets', 'price');
    }

    /** من يحمل ماذا، وبكم — والعهدة التي بيد من لم يعد معنا */
    public static function custody(): array
    {
        return hub_screen('al:cust', self::TTL, fn () => self::custodyCalc(), ['assets', 'users']);
    }

    protected static function custodyCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('assets')) return [];

        $rows = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
            ->whereNotNull('holder_id')
            ->get(['id', 'name', 'type', 'holder_id', 'price', 'status']);

        $users = DB::table('users')->whereIn('id', $rows->pluck('holder_id')->filter()->unique())
            ->get(['id', 'name', 'status', 'deleted_at'])->keyBy('id');

        $by = [];
        foreach ($rows as $a) {
            $u = $users[$a->holder_id] ?? null;
            // الحساب المحذوف أو الموقوف: عهدةٌ باسم من لا يعمل — تُستردّ أو تُنقل
            $gone = ! $u || $u->deleted_at || $u->status !== 'نشط';
            $k = $a->holder_id;
            $by[$k] ??= ['id' => $k, 'name' => $u->name ?? 'حسابٌ محذوف',
                         'gone' => $gone, 'n' => 0, 'value' => 0.0, 'items' => []];
            $by[$k]['n']++;
            // الثمن حقلٌ قد يُحجب بقيود الدور — والمجموع يكشفه لو جُمع
            $by[$k]['value'] += self::seesPrice() ? (float) ($a->price ?? 0) : 0.0;
            if (count($by[$k]['items']) < 6) $by[$k]['items'][] = $a->name;
        }

        usort($by, fn ($a, $b) => [$b['gone'], $b['value']] <=> [$a['gone'], $a['value']]);

        return array_values($by);
    }

    /**
     * الأشياء التي لها ثمنٌ إن أُهملت — كلٌّ بسببه وأثره.
     */
    public static function flags(): array
    {
        return hub_screen('al:flags', self::TTL, fn () => self::flagsCalc(), ['assets', 'asset_maintenance']);
    }

    protected static function flagsCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('assets')) return [];

        $out = [];
        $today = now()->toDateString();
        $q = fn () => hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets');

        // ضمانٌ ينتهي أو انتهى: إصلاحٌ كان مجانياً يصير على الحساب
        foreach ($q()->whereNotNull('warranty')
                    ->where('warranty', '<=', now()->addDays(60)->toDateString())
                    // NOT IN تُسقط NULL صامتةً — وأصلٌ بلا حالة أصلٌ قائم
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['مستبعد', 'تالف']))
                    ->orderBy('warranty')->limit(12)->get(['id', 'name', 'warranty']) as $a) {
            $d = (int) now()->startOfDay()->diffInDays(Carbon::parse($a->warranty)->startOfDay(), false);
            $out[] = ['icon' => '🛡️', 'tone' => $d < 0 ? 'bad' : 'wn', 'id' => $a->id,
                'kind' => $d < 0 ? 'ضمانٌ منتهٍ' : 'ضمانٌ ينتهي قريباً', 'title' => $a->name,
                'why' => $d < 0
                    ? 'انتهى منذ ' . abs($d) . ' يوماً — أيُّ إصلاحٍ من الآن على حساب الشركة.'
                    : "ينتهي بعد {$d} يوماً — ما يُشتبه فيه يُفحص الآن لا بعده."];
        }

        // صيانةٌ مجدولة فات موعدها
        if (Schema::hasTable('asset_maintenance')) {
            $names = $q()->pluck('name', 'id');
            // **التنطيقُ قبل الحدّ**: كان `limit(12)` يقع على صيانات الجميع ثم
            // يُرشَّح ما يراه القارئ — فاثنتا عشرة صيانةً أقدمَ لشركةٍ لا يراها
            // تُخفي صيانتَه الفائتة تماماً. و`orderBy('id')` فاصلُ تعادلٍ لأن
            // `next` تاريخٌ بلا وقت فتتساوى قيمُه كثيراً.
            // التنطيقُ من الأصل نفسِه: `$names` مُنطَّقةٌ أصلاً بـ`hub_scope` على
            // `assets`، وعمودُ الشركة على جدول الصيانة يُترك فارغاً في مساراتٍ
            // كثيرة — فالفرزُ به يُسقط صيانةً مملوكةً فعلاً.
            $ids = $names->keys()->all();
            foreach (DB::table('asset_maintenance')->whereNull('deleted_at')
                        ->whereIn('asset_id', $ids ?: ['-'])
                        ->where('status', 'مجدولة')->whereNotNull('next')
                        ->where('next', '<', $today)->orderBy('next')->orderBy('id')->limit(12)
                        ->get(['id', 'title', 'asset_id', 'next']) as $m) {
                if (! isset($names[$m->asset_id])) continue;      // خارج نطاق القارئ
                $late = (int) Carbon::parse($m->next)->diffInDays(now());
                $out[] = ['icon' => '🔧', 'tone' => 'wn', 'id' => $m->asset_id,
                    'kind' => 'صيانة فات موعدها', 'title' => $names[$m->asset_id] . ' — ' . $m->title,
                    'why' => "مجدولةٌ منذ {$late} يوماً ولم تُنفَّذ — الصيانة المؤجَّلة تُدفع عطلاً."];
            }
        }

        // أصلٌ تجاوز عمره الافتراضي
        foreach ($q()->whereNotNull('life')->where('life', '>', 0)->whereNotNull('buy_date')
                    ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', ['مستبعد', 'تالف']))
                    ->orderBy('buy_date')->limit(80)->get(['id', 'name', 'life', 'buy_date']) as $a) {
            $years = Carbon::parse($a->buy_date)->diffInYears(now());
            if ($years < (float) $a->life) continue;
            $out[] = ['icon' => '⏳', 'tone' => 'wn', 'id' => $a->id,
                'kind' => 'تجاوز عمره الافتراضي', 'title' => $a->name,
                'why' => 'عمره ' . (int) $years . ' سنة والافتراضي ' . rtrim(rtrim(number_format((float) $a->life, 1), '0'), '.')
                    . ' — خطّط لإحلاله قبل أن يتعطّل في وقتٍ لا يُحتمل.'];
        }

        // «تالف/مستبعد» بلا تاريخ استبعاد: أصلٌ ميتٌ يُحسَب حيّاً في الدفاتر
        foreach ($q()->whereIn('status', ['تالف', 'مستبعد'])->whereNull('disposal')
                    ->limit(12)->get(['id', 'name', 'status', 'price']) as $a) {
            $out[] = ['icon' => '🪦', 'tone' => 'bad', 'id' => $a->id,
                'kind' => 'خارج الخدمة بلا استبعاد', 'title' => $a->name,
                'why' => 'حالته «' . $a->status . '» وبلا تاريخ استبعاد — يبقى في الجرد أصلاً قائماً'
                    . ($a->price && self::seesPrice() ? ' بقيمة ' . number_format((float) $a->price, 0) : '') . '.'];
        }

        // أصلٌ قيد الاستخدام بلا حائز: مسؤوليةٌ بلا صاحب
        foreach ($q()->where('status', 'قيد الاستخدام')->whereNull('holder_id')
                    ->limit(12)->get(['id', 'name']) as $a) {
            $out[] = ['icon' => '❓', 'tone' => 'wn', 'id' => $a->id,
                'kind' => 'قيد الاستخدام بلا حائز', 'title' => $a->name,
                'why' => 'يُستخدَم ولا اسمَ عليه — فإذا ضاع أو تلف لم يُسأل أحد.'];
        }

        usort($out, fn ($a, $b) => ['bad' => 0, 'wn' => 1][$a['tone']] <=> ['bad' => 0, 'wn' => 1][$b['tone']]);

        return $out;
    }

    /**
     * قرار الإحلال بالأرقام: مجموعُ ما صُرف على صيانة الأصل مقابل ثمنه.
     * لا أحد يجمع الفواتير — فيُصرَف على جهازٍ أكثرُ من ثمن بديله بلا أن يُلاحَظ.
     */
    public static function replace(): array
    {
        return hub_screen('al:repl', self::TTL, fn () => self::replaceCalc(), ['assets', 'asset_maintenance']);
    }

    protected static function replaceCalc(): array
    {
        // اللوحة كلها ثمنٌ ونسبةٌ منه: تُطوى كاملةً لمن حُجب عنه الثمن
        if (! self::may() || ! self::seesPrice()) return [];
        if (! Schema::hasTable('assets') || ! Schema::hasTable('asset_maintenance')) return [];

        $assets = hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets')
            ->whereNotNull('price')->where('price', '>', 0)
            ->get(['id', 'name', 'price', 'status', 'buy_date'])->keyBy('id');
        if ($assets->isEmpty()) return [];

        // «صُرف» تعني المكتملة: قيدٌ مجدولٌ لم يُدفع بعد فلا يُحتسب في قرار الإحلال
        $spend = DB::table('asset_maintenance')->whereNull('deleted_at')
            ->whereIn('asset_id', $assets->keys())->where('status', 'مكتملة')
            ->select('asset_id', DB::raw('SUM(cost) as c'), DB::raw('COUNT(*) as n'))
            ->groupBy('asset_id')->get();

        $out = [];
        foreach ($spend as $s) {
            $a = $assets[$s->asset_id] ?? null;
            if (! $a || ! $s->c) continue;
            $ratio = (float) $s->c / (float) $a->price;
            if ($ratio < self::REPLACE_AT) continue;
            $out[] = ['id' => $a->id, 'name' => $a->name, 'price' => (float) $a->price,
                'spent' => (float) $s->c, 'times' => (int) $s->n,
                'pct' => (int) round($ratio * 100), 'status' => $a->status,
                'verdict' => $ratio >= 1
                    ? 'صُرف على صيانته أكثر من ثمنه — إبقاؤه قرارٌ يُبرَّر لا عادةٌ تستمر.'
                    : 'صيانته بلغت ' . (int) round($ratio * 100) . '٪ من ثمنه — قارِن ببديلٍ جديد قبل الإصلاح القادم.'];
        }

        usort($out, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        return array_slice($out, 0, 10);
    }

    /** ملخّصٌ يُقرأ في سطر */
    public static function summary(): array
    {
        return hub_screen('al:sum', self::TTL, fn () => self::summaryCalc(), ['assets', 'asset_maintenance']);
    }

    protected static function summaryCalc(): array
    {
        if (! self::may() || ! Schema::hasTable('assets')) return [];

        $q = fn () => hub_scope(DB::table('assets')->whereNull('deleted_at'), 'assets');
        // بنطاق القارئ وبالمكتملة وحدها — كان يجمع صيانة المنظمة كلها بجانب أصوله هو
        $spend = Schema::hasTable('asset_maintenance')
            ? (float) hub_scope(DB::table('asset_maintenance')->whereNull('deleted_at'), 'assetlog')
                ->where('status', 'مكتملة')
                ->where('date', '>=', now()->subMonths(12)->toDateString())->sum('cost')
            : 0.0;

        return [
            'n' => $q()->count(),
            'value' => self::seesPrice() ? (float) $q()->sum('price') : null,
            'held' => $q()->whereNotNull('holder_id')->count(),
            'inMaint' => $q()->where('status', 'صيانة')->count(),
            'spend12' => $spend,
            'cur' => setting('app.currency', 'د.ك'),
        ];
    }
}
