<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\AssetCustody;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **العهدة: صنفٌ له كود، وجهازٌ له هويّة، وحائزٌ له إثبات.**
 *
 * كانت الأصولُ قائمةً مسطّحة: مئتا صفٍّ اسمُها في عمودٍ ونوعُها في آخر، تُقرأ
 * بالبحث لا بالتصفّح. ومن أراد أن يعرف «كم لابتوباً عندنا ومن يحملها» لم يكن
 * أمامه إلا فلترٌ يدويّ وجمعٌ بالعين. فهنا سكّتان:
 *
 *   · **الكتالوج** — الأصنافُ أولاً بكودها الأساسي وعددها وقيمتها وما في
 *     عهدة الناس منها، ثم يُفتح الصنفُ فيُرى ما فيه، ثم يُفتح العنصر فتُرى
 *     تفاصيلُه. تصفّحٌ من العامّ إلى الخاصّ لا بحثٌ عن اسمٍ يُتذكَّر.
 *   · **المواصفاتُ الداخلية** — قالبٌ لكل صنف (`config/hub_assets.php`) يُخزَّن
 *     في `assets.specs`، فيُطبع في ورقة A5 ويُقرأ في الشاشة. وما كان يُكتب في
 *     «ملاحظات» نصّاً حرّاً صار حقولاً تُقرأ.
 *
 * وكلُّ قراءةٍ هنا **مُنطَّقة**: `hub_scope` + نطاقُ الشركة النشطة — فلا يعدّ
 * الكتالوجُ أصولَ شركةٍ لا يراها القارئ، ولا تكشف القيمةُ المجموعة ثمناً
 * محجوباً عنه بقيود الدور.
 */
class Custody
{
    public const TTL = 120;

    /** حركاتُ الحيازة: أوّلتان تُقيَّدان بعد وقوعهما، والثلاثُ الباقية **تصاريح** تسبقها */
    public const MOVES = ['تسليم', 'استرداد'];
    public const PERMITS = ['نقل', 'خروج مؤقت', 'خروج نهائي'];

    /** ما يشرحه كلُّ نوع تصريح للمستخدم — الاسمُ وحده لا يقول أثره */
    public const PERMIT_HINTS = [
        'نقل'         => 'انتقالُ العهدة من يدٍ إلى يد — الحائزُ يتغيّر والأصلُ يبقى عندنا.',
        'خروج مؤقت'   => 'خروجٌ من المقرّ ثم عودة (صيانةٌ أو معرضٌ أو عملٌ خارجي) — له موعدُ عودةٍ يُتابَع.',
        'خروج نهائي'  => 'خروجٌ بلا عودة (بيعٌ أو إتلافٌ أو ردٌّ للمورد) — يُستبعَد الأصل ويُختم تاريخُ استبعاده.',
    ];

    /** سجل الأصناف كما هو */
    public static function cats(): array
    {
        return (array) config('hub_assets.cats', []);
    }

    /** تعريفُ صنفٍ بعينه — وصنفٌ غيرُ مسجَّل يأخذ تعريف «أخرى» فلا يسقط شيء */
    public static function cat(?string $type): array
    {
        $cats = self::cats();
        $def = $cats[(string) $type] ?? $cats['أخرى'] ?? [];

        return [
            'name'  => (string) $type !== '' ? (string) $type : 'بلا صنف',
            'code'  => (string) ($def['code'] ?? config('hub_assets.fallback', 'GN')),
            'icon'  => (string) ($def['icon'] ?? '📦'),
            'specs' => (array) ($def['specs'] ?? []),
        ];
    }

    /** الكود الأساسي للصنف — بادئةُ كود العهدة (LT · SV · PH …) */
    public static function catCode(?string $type): string
    {
        return self::cat($type)['code'];
    }

    /** قالبُ المواصفات الداخلية لصنفٍ — مفاتيحُه وحدها تُقبل في التخزين */
    public static function specTemplate(?string $type): array
    {
        return self::cat($type)['specs'];
    }

    /**
     * تنقيةُ المواصفات المُدخَلة: **مفاتيحُ القالب وحدها** تُقبل — فلا يُحقَن
     * مفتاحٌ من خارج التعريف في عمود JSON، ولا تُكتب قيمةٌ أطولُ ممّا يُعرض.
     * القيمة الفارغة تُسقط المفتاح، فلا يمتلئ العمود بمفاتيحَ بلا قيم.
     */
    public static function sanitizeSpecs(?string $type, array $input): array
    {
        $out = [];
        foreach (self::specTemplate($type) as $f) {
            $k = (string) $f['key'];
            $v = trim(hub_str($input[$k] ?? ''));
            if ($v === '') continue;
            $out[$k] = hub_fit($v, 200);
        }

        return $out;
    }

    /** المواصفات المسجَّلة مقرونةً بتسمياتها — للعرض والطباعة */
    public static function specRows(Asset $a): array
    {
        $vals = (array) ($a->specs ?? []);
        $out = [];
        foreach (self::specTemplate($a->type) as $f) {
            $k = (string) $f['key'];
            if (! isset($vals[$k]) || $vals[$k] === '') continue;
            $out[] = ['key' => $k, 'label' => (string) $f['label'],
                      'val' => (string) $vals[$k], 'ltr' => (bool) ($f['ltr'] ?? false)];
        }

        return $out;
    }

    /** استعلامُ الأصول بنطاق القارئ وشركته النشطة — مصدرٌ واحدٌ لكل قارئ هنا */
    public static function scoped()
    {
        return hub_company_scope(hub_scope(Asset::query(), 'assets'), 'assets');
    }

    /** هل يرى القارئ ثمن الأصل؟ المجموعُ يكشف الحقلَ المحجوب كما يكشفه الصفّ */
    public static function seesPrice(): bool
    {
        return ! hub_masked('assets', 'price');
    }

    /**
     * الكتالوج: صنفٌ في كل سطر — كودُه وعددُه وما في عهدة الناس منه وقيمتُه.
     * استعلامٌ مُجمَّعٌ واحد لا صفٌّ لكل صنف.
     */
    public static function catalog(): array
    {
        return hub_screen('cust:cat', self::TTL, fn () => self::catalogCalc(), ['assets']);
    }

    protected static function catalogCalc(): array
    {
        if (! hub_can(auth()->user(), 'assets', 'v') || ! Schema::hasTable('assets')) return [];

        // بلا whereNull('deleted_at') يدويّاً: SoftDeletes على النموذج تُسقط المحذوف
        $rows = self::scoped()
            ->select('type', DB::raw('COUNT(*) as n'),
                DB::raw('SUM(CASE WHEN holder_id IS NULL THEN 0 ELSE 1 END) as held'),
                DB::raw('SUM(price) as value'))
            ->groupBy('type')->get();

        $out = [];
        foreach ($rows as $r) {
            $cat = self::cat($r->type);
            $out[] = [
                'type'  => (string) ($r->type ?? ''),
                'name'  => $cat['name'],
                'code'  => $cat['code'],
                'icon'  => $cat['icon'],
                'n'     => (int) $r->n,
                'held'  => (int) $r->held,
                'free'  => (int) $r->n - (int) $r->held,
                'value' => self::seesPrice() ? (float) ($r->value ?? 0) : null,
            ];
        }

        // الأكثرُ عدداً أولاً، وعند التساوي بالاسم — ترتيبٌ ثابتٌ على المحرّكين
        usort($out, fn ($a, $b) => [$b['n'], $a['name']] <=> [$a['n'], $b['name']]);

        return $out;
    }

    /**
     * تسليمُ العهدة واستردادُها — **حركةٌ واحدة**: الحائزُ يتغيّر على الأصل،
     * والحركةُ تُقيَّد في سجلٍّ لا يُمحى. كان `holder_id` وحده يقول من يحمل
     * الآن ولا يقول من حمل قبله ومتى سلّم، فعهدةٌ مرّت على ثلاثةٍ لا أثر لاثنين.
     *
     * صفقةٌ واحدة: أصلٌ تغيّر حائزُه بلا قيدٍ في السجل عهدةٌ انتقلت بلا إثبات.
     */
    public static function move(Asset $a, string $action, ?string $userId,
                                string $at, ?string $note = null): AssetCustody
    {
        return DB::transaction(function () use ($a, $action, $userId, $at, $note) {
            $entry = AssetCustody::create([
                'asset_id'   => $a->id,
                'user_id'    => $userId,
                'company_id' => $a->company_id,
                'action'     => $action,
                'at'         => $at,
                'note'       => $note === null || $note === '' ? null : hub_fit($note, 500),
                'by_id'      => auth()->id(),
            ]);

            $a->holder_id = $userId;
            // الاسترداد يُعيد الأصل «متاحاً» ما لم يكن في صيانةٍ أو تالفاً —
            // فأصلٌ رُدَّ للمخزن ويبقى «قيد الاستخدام» يُحسب مستعمَلاً وهو رفٌّ.
            if ($userId === null && in_array((string) $a->status, ['قيد الاستخدام', ''], true)) {
                $a->status = 'متاح';
            }
            if ($userId !== null && (string) $a->status === 'متاح') {
                $a->status = 'قيد الاستخدام';
            }
            $a->save();

            return $entry;
        });
    }

    /**
     * **تصريحُ نقلٍ أو خروج** — ورقةٌ مرقّمةٌ تسبق الحركة لا تُقيّدها بعدها.
     *
     * كان خروجُ الجهاز من المقرّ حدثاً شفهياً: يُقال «أخذوه للصيانة» فلا رقمَ
     * ولا موعدَ عودةٍ ولا توقيعَ من أخذه — فإذا لم يعد لم يُعرف متى خرج ولا بإذن
     * من. التصريحُ يُغلق هذا كلَّه: رقمٌ فريد، وموعدُ عودةٍ يُتابَع، وورقةٌ
     * تُطبَع وتُوقَّع عند البوابة، وطلبُ توقيعٍ إلكترونيّ يُربَط بها فتصير حجةً.
     *
     * وأثرُه على الأصل نفسِه بحسب نوعه: النقلُ يُغيّر الحائز، والخروجُ المؤقت
     * يُبقيه ويفتح موعدَ عودة، والنهائيُّ يُستبعد الأصلَ ويختم تاريخ استبعاده.
     * صفقةٌ واحدة — تصريحٌ بلا أثرٍ على الأصل ورقةٌ لا تصف الواقع.
     */
    public static function permit(Asset $a, string $kind, array $in): AssetCustody
    {
        return DB::transaction(function () use ($a, $kind, $in) {
            $entry = AssetCustody::create([
                'asset_id'   => $a->id,
                'user_id'    => $in['userId'] ?? null,
                'company_id' => $a->company_id,
                'action'     => $kind,
                'at'         => $in['at'],
                'due'        => $in['due'] ?? null,
                'to_loc'     => isset($in['to']) && $in['to'] !== '' ? hub_fit((string) $in['to'], 300) : null,
                'note'       => isset($in['note']) && $in['note'] !== '' ? hub_fit((string) $in['note'], 500) : null,
                'permit_no'  => self::nextPermitNo(),
                'status'     => 'ساري',
                'by_id'      => auth()->id(),
            ]);

            if ($kind === 'نقل') {
                $a->holder_id = $in['userId'] ?? null;
                if ($in['userId'] !== null && (string) $a->status === 'متاح') $a->status = 'قيد الاستخدام';
            } elseif ($kind === 'خروج نهائي') {
                // خروجٌ بلا عودة: الأصلُ يخرج من الجرد فعلاً — وإبقاؤه «قيد
                // الاستخدام» بيدِ حائزٍ يجعله يُحسَب أصلاً قائماً بقيمته سنين.
                $a->holder_id = null;
                $a->status = 'مستبعد';
                $a->disposal = $a->disposal ?: $in['at'];
            }
            // الخروجُ المؤقت لا يمسّ الحائز: الجهازُ ما زال في عهدته وإن كان خارج المبنى
            $a->save();

            return $entry;
        });
    }

    /**
     * تسجيلُ عودة ما خرج مؤقتاً — التصريحُ يُغلق، ويعود الأصلُ لموقعه.
     * تصريحٌ لا يُغلق يبقى «سارياً» أبداً فيفقد جدولُ المتأخرات معناه.
     */
    public static function closePermit(AssetCustody $p, string $at, ?string $note = null): void
    {
        $p->returned_at = $at;
        $p->status = 'أُعيد';
        if ($note !== null && $note !== '') {
            $p->note = hub_fit(trim(($p->note ? $p->note . ' — ' : '') . $note), 500);
        }
        $p->save();
    }

    /** رقمُ التصريح التالي: تسلسلٌ سنويٌّ فريد (PRM-2026-0001) */
    public static function nextPermitNo(): string
    {
        $format = (string) (setting('assets.permit_format') ?: 'PRM-{YEAR}-{SEQ}');
        $year = now()->format('Y');
        $prefix = str_replace(['{YEAR}', '{SEQ}'], [$year, ''], $format);

        $last = AssetCustody::withTrashed()->where('permit_no', 'like', $prefix . '%')
            ->orderByDesc('permit_no')->value('permit_no');
        $n = $last ? ((int) preg_replace('/\D/', '', substr((string) $last, strlen($prefix)))) + 1 : 1;

        do {
            $candidate = str_replace(['{YEAR}', '{SEQ}'], [$year, sprintf('%04d', $n)], $format);
            $n++;
        } while (AssetCustody::withTrashed()->where('permit_no', $candidate)->exists());

        return $candidate;
    }

    /**
     * ما خرج ولم يعد: تصاريحُ خروجٍ ساريةٌ فات موعدُ عودتها — **بنطاق القارئ**.
     * التنطيقُ من الأصول نفسِها: عمودُ الشركة على جدول الحركة قد يُترك فارغاً.
     */
    public static function overdue(int $limit = 20): array
    {
        if (! Schema::hasTable('asset_custody') || ! hub_can(auth()->user(), 'assets', 'v')) return [];

        $names = self::scoped()->pluck('name', 'id');
        if ($names->isEmpty()) return [];

        $rows = AssetCustody::whereIn('asset_id', $names->keys())
            ->where('status', 'ساري')->whereNotNull('due')
            ->whereDate('due', '<', now()->toDateString())
            ->orderBy('due')->orderBy('id')->limit($limit)->get();

        return $rows->map(fn ($p) => [
            'id'      => $p->id,
            'assetId' => $p->asset_id,
            'asset'   => (string) ($names[$p->asset_id] ?? '—'),
            'permit'  => (string) $p->permit_no,
            'action'  => (string) $p->action,
            'to'      => (string) ($p->to_loc ?? '—'),
            'due'     => substr((string) $p->due, 0, 10),
            // موجَبٌ دائماً: من التاريخ الفائت إلى اليوم — لا العكس فيصير سالباً
            'late'    => (int) \Illuminate\Support\Carbon::parse($p->due)->startOfDay()
                            ->diffInDays(now()->startOfDay()),
        ])->all();
    }

    /** سجلُّ حيازة أصلٍ — الأحدثُ أولاً، ومعه أسماءُ الأشخاص */
    public static function history(string $assetId, int $limit = 20): array
    {
        if (! Schema::hasTable('asset_custody')) return [];

        // `at` تاريخٌ بلا وقت فتتساوى قيمُه في اليوم الواحد — و`created_at`
        // بدقّة الثانية تتساوى في الإدخال الدفعيّ. المفتاحُ فاصلُ التعادل الحاسم.
        $rows = AssetCustody::where('asset_id', $assetId)
            ->orderByDesc('at')->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)->get();

        $names = DB::table('users')
            ->whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('by_id'))->filter()->unique())
            ->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id'       => $r->id,
            'action'   => (string) $r->action,
            'at'       => substr((string) $r->at, 0, 10),
            'who'      => $r->user_id ? ($names[$r->user_id] ?? 'حسابٌ محذوف') : 'المخزن',
            'whoId'    => $r->user_id,
            'by'       => $r->by_id ? ($names[$r->by_id] ?? '—') : '—',
            'note'     => $r->note,
            'permit'   => $r->permit_no,
            'to'       => $r->to_loc,
            'due'      => $r->due ? substr((string) $r->due, 0, 10) : null,
            'returned' => $r->returned_at ? substr((string) $r->returned_at, 0, 10) : null,
            'status'   => $r->status,
            'signId'   => $r->sign_id,
            // متأخرٌ = تصريحٌ سارٍ فات موعدُ عودته — يُميَّز في السجل بلونه
            'late'     => $r->status === 'ساري' && $r->due
                          && substr((string) $r->due, 0, 10) < now()->toDateString(),
        ])->all();
    }
}
