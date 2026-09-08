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

    /* ══════════ دورةُ حياة الأصل (Work OS · الطور F · WP-F.2 · §29–31 · C11) ══════════ */

    /**
     * **الحالاتُ الإحدى عشرة** — القائمةُ المرجعُ الوحيدة، تُعكَس في خيارات
     * `config/hub.php` (يحرس التطابقَ `WorkOsAssetUpgradeTest`). الحالةُ حقلٌ
     * **مقفل**: تُكتَب عبر هذا الصنف وحدَه (`transition`/`move`/`permit`)، لا من
     * نموذج CRUD العامّ ولا من سحب الكانبان — فلا حالةَ إلا بانتقالٍ شرعيّ مُدقَّق.
     *
     * الخمسُ الأُولى هي حالاتُ ما قبل الطور F (`LEGACY_STATUSES`) وتبقى **قيمها
     * كما هي** — توافقٌ رجعيٌّ تامّ (§86): بياناتٌ ومسارٌ قديمان يعملان بلا مساس.
     */
    public const STATUSES = [
        // ── الخمسُ القديمة (تبقى حرفاً — أساسُ التوافق الرجعيّ) ──
        'قيد الاستخدام',   // بيد حائزٍ أو على محطةٍ عاملة
        'متاح',            // في المخزن جاهزٌ للإسناد
        'صيانة',           // قيد الإصلاح
        'تالف',            // معطوبٌ ينتظر قراراً (إصلاحٌ أو استبعاد) — **مفتوحة**
        'مستبعد',          // خرج من الخدمة نهائياً — **نهائيّة**
        // ── الستُّ الجديدة (الطور F) ──
        'قيد الطلب',       // مطلوبٌ لم يُستلَم بعد
        'محجوز',           // مخصَّصٌ لجهةٍ لم يُسلَّم بعد
        'خارج مؤقتاً',      // خارج المقرّ بموعد عودة (تصريحُ خروجٍ مؤقت)
        'مفقود',           // غير موجودٍ يحتاج تحرّياً/شطباً — **مفتوحة**
        'مباع',            // بِيع — **نهائيّة**
        'مُعاد للمورد',    // رُدَّ للمورد/الضمان — **نهائيّة**
    ];

    /** الحالاتُ الخمس السابقة للطور F — تبقى عاملةً قيمةً وتصنيفاً (§86) */
    public const LEGACY_STATUSES = ['قيد الاستخدام', 'متاح', 'صيانة', 'تالف', 'مستبعد'];

    /**
     * **مُرادفاتُ التوافق الرجعيّ:** صيغٌ حرّةٌ قديمةٌ محتملةٌ في بياناتٍ مُرحَّلة
     * تُحلُّ لقيمتها المعياريّة. الخمسُ القديمةُ **قيمٌ معياريّةٌ بذاتها** (لا تُرحَّل)،
     * وهذه لالتقاط ما كُتب بصيغةٍ مغايرة. والتشكيلُ/الهمزاتُ يحلُّها `hub_ar_norm`.
     */
    public const STATUS_ALIASES = [
        'مستخدم'       => 'قيد الاستخدام',
        'قيد التشغيل'  => 'قيد الاستخدام',
        'متوفر'        => 'متاح',
        'في الصيانة'   => 'صيانة',
        'معطل'         => 'تالف',
        'خارج الخدمة'  => 'مستبعد',
        'ضائع'         => 'مفقود',
        'مفقودة'       => 'مفقود',
        'مُعاد'         => 'مُعاد للمورد',
    ];

    /**
     * **خريطةُ الانتقال:** من كلِّ حالةٍ إلى الحالاتِ المشروعةِ بعدها فقط — فلا
     * تُبلَغ حالةٌ إلا بانتقالٍ صحيح (`transition`). الحالاتُ النهائيّةُ الثلاث
     * (مستبعد/مباع/مُعاد للمورد) بلا مخرج — لا رجوعَ من الاستبعاد. والحالةُ المعدومة
     * (أصلٌ لم يُصنَّف) تدخل `ENTRY_STATES` أوّلَ مرةٍ (`canTransition`).
     */
    public const TRANSITIONS = [
        'قيد الطلب'      => ['متاح', 'محجوز', 'مُعاد للمورد', 'مفقود'],
        'متاح'          => ['محجوز', 'قيد الاستخدام', 'صيانة', 'خارج مؤقتاً', 'تالف', 'مفقود', 'مستبعد', 'مباع', 'مُعاد للمورد'],
        'محجوز'         => ['متاح', 'قيد الاستخدام'],
        'قيد الاستخدام' => ['متاح', 'صيانة', 'خارج مؤقتاً', 'تالف', 'مفقود', 'مستبعد'],
        'صيانة'         => ['متاح', 'قيد الاستخدام', 'تالف', 'مستبعد', 'مُعاد للمورد'],
        'خارج مؤقتاً'    => ['متاح', 'قيد الاستخدام', 'مفقود', 'تالف', 'مستبعد'],
        'تالف'          => ['صيانة', 'مستبعد', 'مباع', 'مُعاد للمورد'],
        'مفقود'         => ['متاح', 'قيد الاستخدام', 'مستبعد'],
        'مستبعد'        => [],   // نهائيّة — لا رجوع
        'مباع'          => [],   // نهائيّة
        'مُعاد للمورد'   => [],   // نهائيّة
    ];

    /**
     * **حالاتُ الدخول:** ما يجوز أن تبدأ به دورةُ حياة أصلٍ لم تُصنَّف بعد (حالةٌ
     * معدومة). الحالةُ مقفلةٌ عن CRUD، فأصلٌ حديثٌ يبدأ بلا حالة (مفتوح)؛ ثم يُصنَّف
     * أولَ مرةٍ عبر `transition` إلى إحدى هذه: مطلوبٌ بعد، أو في المخزن، أو محجوز.
     */
    public const ENTRY_STATES = ['قيد الطلب', 'متاح', 'محجوز'];

    /**
     * القيمةُ المعياريّة لحالةٍ مُدخَلة: تُقبل المعياريّةُ كما هي، ثم المُرادفُ
     * المُعلَن، ثم مطابقةٌ مُطبَّعة (تشكيل/همزات) — فقيمةٌ قديمةٌ بصورةٍ أخرى تُحلّ
     * لقيمتها. المجهولةُ تُعاد كما هي فيرفضها المتحقّق. الفارغةُ → null.
     */
    public static function canonicalStatus(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') return null;
        if (in_array($s, self::STATUSES, true)) return $s;
        if (isset(self::STATUS_ALIASES[$s])) return self::STATUS_ALIASES[$s];

        $n = hub_ar_norm($s);
        foreach (self::STATUS_ALIASES as $alias => $canon) {
            if (hub_ar_norm($alias) === $n) return $canon;
        }
        foreach (self::STATUSES as $canon) {
            if (hub_ar_norm($canon) === $n) return $canon;
        }

        return $s;
    }

    /**
     * هل الانتقالُ من حالةٍ إلى أخرى مشروع؟ الحالةُ المعدومة (أصلٌ لم يُصنَّف) تدخل
     * إحدى `ENTRY_STATES` فقط (مطلوب/متاح/محجوز)؛ وما عداها يتبع `TRANSITIONS`.
     * الثباتُ (الحالةُ نفسُها) مقبولٌ (idempotent).
     */
    public static function canTransition(?string $from, ?string $to): bool
    {
        $to = self::canonicalStatus($to);
        if ($to === null || ! in_array($to, self::STATUSES, true)) return false;

        $from = self::canonicalStatus($from);
        if ($from === null) return in_array($to, self::ENTRY_STATES, true);   // تصنيفٌ أوّل
        if (! in_array($from, self::STATUSES, true)) $from = 'متاح';          // قيمةٌ قديمةٌ لا تُعرَف → مبدأٌ متاح
        if ($from === $to) return true;                                       // ثباتٌ (idempotent)

        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * **تغييرُ حالة الأصل — الطريقُ الوحيدُ المقفل المُدقَّق (C11).** الحالةُ حقلٌ
     * مقفلٌ عن CRUD؛ فتغييرُها يمرّ هنا: معاملةٌ واحدةٌ تُقفل الأصلَ (`lockForUpdate`)
     * فلا يتسابق معالجان، وتتحقّق من مشروعيّة الانتقال (`canTransition`) قبل الكتابة،
     * وتكتب صفَّ حركةٍ في السجل يحفظ «من ← إلى» — فلا حالةَ تتبدّل بلا أثر ولا قفزةَ
     * تتخطّى الخريطة. انتقالٌ غير مشروعٍ يرمي `InvalidArgumentException` (٤٢٢ في المتحكّم).
     */
    public static function transition(Asset $a, string $to, string $at,
                                      ?string $note = null, array $ctx = []): AssetCustody
    {
        return DB::transaction(function () use ($a, $to, $at, $note, $ctx) {
            $locked = Asset::whereKey($a->id)->lockForUpdate()->firstOrFail();

            $from = self::canonicalStatus($locked->status);
            $canon = self::canonicalStatus($to);
            if (! self::canTransition($from, $to)) {
                throw new \InvalidArgumentException(
                    'انتقالُ حالةٍ غير مشروع: «' . ($from ?? '—') . '» ⟵ «' . ((string) $to) . '»');
            }

            $entry = AssetCustody::create([
                'asset_id'   => $locked->id,
                'user_id'    => $locked->holder_id,      // من يحمله حين تغيّرت حالتُه (أثرٌ لا حائزٌ جديد)
                'station_id' => $locked->station_id,
                'company_id' => $locked->company_id,
                'action'     => 'تغيير حالة',
                'at'         => substr($at, 0, 10),
                'note'       => $note === null || $note === '' ? null : hub_fit($note, 500),
                'by_id'      => auth()->id(),
                'meta'       => ['from' => $from, 'to' => $canon],
                'project_id' => $ctx['project_id'] ?? null,
                'client_id'  => $ctx['client_id'] ?? ($locked->client_id ?: null),
            ]);

            $locked->status = $canon;
            $locked->save();
            $a->setRawAttributes($locked->getAttributes(), true);

            // §3 — اتساقُ النقطةِ الطرفية: أصلٌ بلغ حالةً نهائيّة ⇒ يُعلَّق جهازُه النشط
            // (داخلَ المعاملة نفسِها — الأثرُ والتعليقُ أو لا شيء). لا يمسّ ذلك أصلاً
            // بلا جهاز، ولا يُعيد كتابةَ حدثٍ تاريخيّ.
            \App\Support\Endpoint::onAssetStatusChanged($a);

            return $entry;
        });
    }

    /**
     * **إسنادُ الأصل لمحطةٍ (أو إخلاؤه منها) — عبر Custody وحدَها (قاعدةُ الطور F).**
     * المقعدُ (`station_id`) حقلٌ مقفلٌ عن CRUD؛ فتغييرُه يمرّ هنا: معاملةٌ مقفلةٌ
     * (`lockForUpdate`) تكتب صفَّ حركةٍ في السجل (`إسناد لمحطة`/`إخلاء من محطة`)
     * **وتحدّث** `assets.station_id` معاً — الأثرُ والحالةُ أو لا شيء.
     *
     * `station_id` عمودٌ **منفصلٌ عن holder_id**: أصلٌ قد يكون بيد موظفٍ وعلى محطةٍ
     * معاً، وإسنادُه لمحطةٍ لا يمسّ حائزَه ولا حالتَه (لا إجبار §30). `$stationId=null`
     * إخلاءٌ من المحطة.
     */
    public static function assignStation(Asset $a, ?string $stationId, string $at,
                                         ?string $note = null, array $ctx = []): AssetCustody
    {
        return DB::transaction(function () use ($a, $stationId, $at, $note, $ctx) {
            $locked = Asset::whereKey($a->id)->lockForUpdate()->firstOrFail();

            $entry = AssetCustody::create([
                'asset_id'   => $locked->id,
                'user_id'    => null,                    // إسنادُ مقعدٍ لا حائزٌ شخص
                'station_id' => $stationId,
                'company_id' => $locked->company_id,
                'action'     => $stationId === null ? 'إخلاء من محطة' : 'إسناد لمحطة',
                'at'         => substr($at, 0, 10),
                'note'       => $note === null || $note === '' ? null : hub_fit($note, 500),
                'by_id'      => auth()->id(),
                'meta'       => ['from_station' => $locked->station_id, 'to_station' => $stationId],
                'project_id' => $ctx['project_id'] ?? null,
                'client_id'  => $ctx['client_id'] ?? ($locked->client_id ?: null),
            ]);

            $locked->station_id = $stationId;
            $locked->save();
            $a->setRawAttributes($locked->getAttributes(), true);

            return $entry;
        });
    }

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

        // بلا whereNull('deleted_at') يدويّاً: SoftDeletes على النموذج تُسقط المحذوف.
        // وقيمةُ الشراء لممتلكاتنا وحدها: أصلُ عميلٍ يُدار لدينا يُعدّ ويُدار
        // ويُلصَق — ولا يدخل قيمةَ ما نملك (owner_scope الفارغ = لينوميا).
        $rows = self::scoped()
            ->select('type', DB::raw('COUNT(*) as n'),
                DB::raw('SUM(CASE WHEN holder_id IS NULL THEN 0 ELSE 1 END) as held'),
                DB::raw("SUM(CASE WHEN owner_scope IS NULL OR owner_scope = 'لينوميا' THEN price ELSE 0 END) as value"))
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
                                string $at, ?string $note = null, array $ctx = []): AssetCustody
    {
        return DB::transaction(function () use ($a, $action, $userId, $at, $note, $ctx) {
            $entry = AssetCustody::create([
                'asset_id'   => $a->id,
                'user_id'    => $userId,
                // المحطةُ الحاليّةُ للأصل تُختم في صفِّ الحركة (أثرٌ لا إسناد):
                // تسليمٌ لموظفٍ لا يمسّ المقعد — المقعدُ يُغيَّر بـassignStation وحدَها
                'station_id' => $ctx['station_id'] ?? ($a->station_id ?: null),
                'company_id' => $a->company_id,
                'action'     => $action,
                'at'         => $at,
                'note'       => $note === null || $note === '' ? null : hub_fit($note, 500),
                'by_id'      => auth()->id(),
                // سياقُ الحركة: لأي مشروعٍ/عميلٍ سُلّمت — يبقى في السجل حتى
                // بعد انتقال الموظف، فالتاريخُ لا يُعاد تفسيره بأثرٍ رجعي
                'project_id' => $ctx['project_id'] ?? null,
                'client_id'  => $ctx['client_id'] ?? ($a->client_id ?: null),
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
        $projects = DB::table('projects')
            ->whereIn('id', $rows->pluck('project_id')->filter()->unique())
            ->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'id'       => $r->id,
            'project'  => $r->project_id ? ($projects[$r->project_id] ?? '—') : null,
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
