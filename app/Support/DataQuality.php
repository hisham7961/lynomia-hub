<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * جودة البيانات — فحوصٌ **مشتقّة من سجل الوحدات**، لا تسعة فحوصٍ مكتوبة بيدٍ.
 *
 * كان المركز يفحص ثلاث وحداتٍ من ثلاثٍ وسبعين: عملاء بلا شركة، ومشاريع بلا
 * مسؤول، ودومينات بلا انتهاء… وبقيّة النظام بلا رقيب. والأهم أنه كان **يعرض
 * النقص ولا يقود إليه**: رقمٌ في بطاقة، ثم ابحث عن السجلات بنفسك.
 *
 * هنا تُشتقّ الفحوص من السجل نفسه فتشمل كل وحدةٍ وكل حقل:
 *   • `req:*`    حقلٌ **مطلوب** وقيمته فارغة (سجلاتٌ سبقت اشتراطه أو استُوردت)
 *   • `ref:*`    مرجعٌ **معلّق**: يشير إلى سجلٍ غير موجود أو محذوف
 *   • `exp:*`    تاريخ انتهاءٍ فارغ — بلا تاريخٍ لا تنبيه تجديد
 *   • `mail:*`   بريدٌ لا يطابق صيغةً صالحة · `url:*` رابطٌ بلا بروتوكول
 *   • `co`       سجلٌ بلا شركة فيسقط من كل تقرير شركة
 *   • `status`   حالةٌ خارج خيارات الوحدة — انحرافُ بياناتٍ صامت
 *
 * وكل فحصٍ يحمل مفتاحه إلى شاشة الوحدة (`?qc=`)، فالنقص **يُفتح لا يُقرأ**.
 *
 * ولكلِّ فحصٍ **شدّةٌ** (`sev`) مشتقّةٌ من صنفه بمفردات `Severity` الواحدة — لا
 * جدولَ شدّاتٍ ولا محرّكَ ثانٍ. بلا الشدّة كانت ستّمئةُ قاعدةٍ بمرتبةٍ واحدة،
 * والترتيبُ بالعدد وحده يدفن مرجعاً ماليّاً مكسوراً تحت آلاف «بلا شركة».
 */
class DataQuality
{
    /** ثوانٍ لتخبئة المسح — الفحص الكامل مئات الاستعلامات */
    public const TTL = 600;

    /** أعمدةٌ يدل اسمها على انتهاء صلاحية */
    protected const EXPIRY_HINTS = ['expiry', 'expires', 'expire_at', 'date_end', 'end_date',
                                    'valid_to', 'renew_at', 'warranty_end', 'due'];

    /**
     * وحداتٌ يُسقِط كسرُ المرجع إليها المالَ أو المساءلة ⇒ **حرج** (spec §6.3:
     * «broken financial reference · orphan security reference»). قيدٌ يشير إلى
     * مستندٍ ماليٍّ محذوف يخرج من كل ميزان، وسجلٌّ يشير إلى مستخدمٍ محذوف لا
     * يُسأل عنه أحد. و`purchases` مُدرَجةٌ وإن لم يشِر إليها حقلٌ اليوم — فالسجل
     * ينمو، والقاعدةُ تسبق الحقل.
     */
    protected const CRITICAL_REFS = ['fin', 'contracts', 'purchases', 'users'];

    /**
     * شدّةُ كل صنفِ فحصٍ — الخريطةُ **بمفردات `Severity`** لا بسلّمٍ سادس:
     *   • `ref`        مرجعٌ مكسور: بنيةٌ منقوضة ⇒ مرتفع (وحرجٌ إن كان هدفُه في CRITICAL_REFS)
     *   • `req`        حقلٌ يشترطه النموذج وهو فارغ ⇒ مرتفع (spec: missing required business data)
     *   • `blank_when` مطلوبٌ بشرطِ نوعِ المستند (فاتورةٌ بلا طرف) ⇒ مرتفع
     *   • `mail|url|status` صيغةٌ أو حالةٌ خارج المعرَّف ⇒ متوسط (spec: invalid email/URL/state)
     *   • `null|stale` عمودٌ اختياريٌّ فارغ (بلا شركة، بلا تاريخ انتهاء) وركودٌ ⇒ منخفض
     * وspec §6.3 صريحة: **لا يُصنَّف كلُّ شيءٍ حرجاً** — إنذارٌ يصرخ دائماً لا يُسمَع.
     */
    protected const KIND_SEV = [
        'ref' => 'high', 'req' => 'high', 'blank_when' => 'high',
        'mail' => 'medium', 'url' => 'medium', 'status' => 'medium',
        'null' => 'low', 'stale' => 'low',
    ];

    /**
     * كل الفحوص الممكنة لوحدةٍ ما — من تعريفها في السجل.
     *
     * @return array<string, array{kind:string,col:?string,label:string,why:string,fix:string}>
     */
    public static function rules(string $module): array
    {
        static $memo = [];
        if (isset($memo[$module])) return $memo[$module];

        $def = hub_mod($module);
        if (! $def || empty($def['table'])) return $memo[$module] = [];

        $table = (string) $def['table'];
        $cols = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        if (! $cols) return $memo[$module] = [];

        $out = [];
        foreach ((array) ($def['fields'] ?? []) as $f) {
            $col = (string) ($f['col'] ?? '');
            $key = (string) ($f['key'] ?? $col);
            $type = (string) ($f['type'] ?? 'text');
            $label = (string) ($f['label'] ?? $col);
            if ($col === '' || ! in_array($col, $cols, true)) continue;
            if (in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) continue;

            // حقلٌ مطلوبٌ اليوم وفارغٌ في سجلاتٍ سبقت اشتراطه
            if (! empty($f['required'])) {
                $out["req:$key"] = ['kind' => 'req', 'col' => $col,
                    // العدديُّ لا يُقارَن بنصٍّ فارغ — انظر `apply('req')`
                    'numeric' => in_array((string) ($f['type'] ?? ''), ['num', 'big', 'money', 'pct'], true),
                    'label' => "«{$label}» مطلوب وفارغ",
                    'why' => 'الحقل مشترطٌ في النموذج، وهذه سجلاتٌ سبقت الاشتراط أو دخلت باستيراد — فتكسر كل تقريرٍ يقوم عليه.',
                    'fix' => "افتح السجل واملأ «{$label}»."];
            }

            // مرجعٌ مفرد: يشير إلى سجلٍ غير موجود أو محذوف
            if ($type === 'ref' && empty($f['multi']) && ! empty($f['ref'])) {
                $target = hub_mod((string) $f['ref']);
                if ($target && ! empty($target['table']) && Schema::hasTable($target['table'])) {
                    $out["ref:$key"] = ['kind' => 'ref', 'col' => $col, 'ref' => (string) $f['ref'],
                        'label' => "«{$label}» يشير إلى سجلٍ غير موجود",
                        'why' => 'المرجع معلّق: الهدف حُذف أو أُدخل معرّفٌ لا يقابله سجل — فتظهر الخانة فارغةً في العرض ويسقط الربط من التقارير.',
                        'fix' => 'أعِد اختيار المرجع الصحيح أو أفرغ الحقل.'];
                }
            }

            // تاريخ انتهاءٍ فارغ — بلا تاريخٍ لا يعمل أي تنبيه تجديد
            if (in_array($type, ['date', 'datetime'], true) && self::looksLikeExpiry($col)) {
                $out["exp:$key"] = ['kind' => 'null', 'col' => $col,
                    'label' => "«{$label}» بلا تاريخ",
                    'why' => 'قواعد التنبيه تُحسب من هذا التاريخ — وبلا تاريخٍ لا يصلك إنذارُ تجديدٍ ولا انتهاء إطلاقاً.',
                    'fix' => "سجّل «{$label}» ليدخل السجل في التنبيهات."];
            }

            // البريد يُعرَف بعموده لا بنوعه: السجل كله يخزّنه `text` — فالاعتماد
            // على النوع وحده كان يعني **صفر فحوصِ بريد** في نظامٍ يرسل بالبريد
            if ($type === 'email' || preg_match('/(^|_)e?mails?$/', $col)) {
                $out["mail:$key"] = ['kind' => 'mail', 'col' => $col, 'type' => $type,
                    'label' => "«{$label}» بصيغةٍ غير صالحة",
                    'why' => 'بريدٌ لا يطابق صيغةً صحيحة لن يصله إشعارٌ ولا مستند — والإرسال يفشل صامتاً في صفّ الصادر.',
                    'fix' => 'صحّح الصيغة أو أفرغ الحقل.'];
            }
            if ($type === 'url') {
                $out["url:$key"] = ['kind' => 'url', 'col' => $col,
                    'label' => "«{$label}» رابطٌ بلا بروتوكول",
                    'why' => 'رابطٌ لا يبدأ بـhttp(s) يفتح مساراً نسبياً داخل النظام بدل الوجهة المقصودة.',
                    'fix' => 'أضِف https:// في أول الرابط.'];
            }
        }

        // سجلٌ بلا شركة يسقط من كل تقرير شركة ومن عزل الشركات
        if (in_array('company_id', $cols, true)) {
            $out['co'] = ['kind' => 'null', 'col' => 'company_id',
                'label' => 'بلا شركة',
                'why' => 'السجل خارج كل تقرير شركة، ولا يراه أي مستخدمٍ معزولٍ بشركة — موجودٌ ولا يُرى.',
                'fix' => 'اربط السجل بشركته.'];
        }

        // حالةٌ خارج خيارات الوحدة — انحرافٌ يكسر كل عدٍّ حسب الحالة
        // **العمودُ لا المفتاح** (v2.339): `$def['status']` مفتاحُ الحقل، وقد
        // يخالف عمودَه (`docStatus` ↔ `doc_status`) — فالمقارنةُ بأسماء الأعمدة
        // كانت تُسقط الفحصَ كلياً عن كل وحدةٍ من هذا الصنف، وهي وحداتٌ حقيقيّة.
        $statusKey = (string) ($def['status'] ?? '');
        $statusCol = $statusKey ? (string) (hub_status_col((string) ($def['key'] ?? '')) ?: $statusKey) : '';
        if ($statusCol && in_array($statusCol, $cols, true)) {
            $opts = collect($def['fields'] ?? [])->firstWhere('key', $statusKey)['options']
                 ?? collect($def['fields'] ?? [])->firstWhere('col', $statusCol)['options'] ?? [];
            if (is_array($opts) && count($opts) > 1) {
                $out['status'] = ['kind' => 'status', 'col' => $statusCol, 'options' => array_values($opts),
                    'label' => 'حالةٌ خارج الخيارات المعرَّفة',
                    'why' => 'قيمةٌ لا يعرفها السجل (استيرادٌ أو تعديلٌ مباشر) — فلا تُعدّ في أي إحصاءٍ حسب الحالة ولا تُطلق أتمتة.',
                    'fix' => 'اختر حالةً من قائمة الوحدة.'];
            }
        }

        // الشدّةُ تُلحَق **في مكانٍ واحد** بالمشتقّ والمنتقى معاً — فأيُّ قاعدةٍ
        // تُضاف غداً تولد بشدّةٍ ولا تظهر بمرتبة «بلا تصنيف».
        $all = $out + self::curated($module, $cols);
        foreach ($all as $k => $r) $all[$k]['sev'] = self::severity($r);

        return $memo[$module] = $all;
    }

    /**
     * شدّةُ قاعدةٍ من صنفها وهدفها — بمفردات `Severity` (spec §6.3).
     *
     * والمجهولُ يهبط إلى **الوسط** لا إلى أحد الطرفين: صنفٌ جديد لم يُصنَّف بعد
     * إن جُعل «حرجاً» أطلق إنذاراً كاذباً يُدرَّب الناسُ على تجاهله، وإن جُعل
     * «معلوماتياً» دُفن تحت السطر فلم يره أحد. الوسطُ يُبقيه مرئيّاً حتى يُصنَّف.
     */
    public static function severity(array $rule): string
    {
        $kind = (string) ($rule['kind'] ?? '');

        if ($kind === 'ref' && in_array((string) ($rule['ref'] ?? ''), self::CRITICAL_REFS, true)) {
            return 'critical';
        }

        return self::KIND_SEV[$kind] ?? 'medium';
    }

    /**
     * فحوصٌ لا يُشتقّ معناها من نوع الحقل — دلالةُ عملٍ لا بنيةُ بيانات:
     * «مشروعٌ بلا مسؤول» عمودٌ اختياريّ في السجل لكنه ثقبُ مساءلة، و«فاتورةٌ
     * بلا طرف» شرطُها نوعُ المستند. تُكتب هنا صراحةً وتنال رابطها كالبقية.
     */
    protected static function curated(string $module, array $cols): array
    {
        $all = [
            'projects' => ['manager' => ['kind' => 'null', 'col' => 'manager_id',
                'label' => 'مشروعٌ بلا مسؤول',
                'why' => 'لا أحد يُسأل عنه: لا يدخل حِمل أحد، ولا يصل تنبيهُ تعثّرٍ إلى شخصٍ بعينه.',
                'fix' => 'عيّن مسؤول المشروع.']],

            'hr' => ['manager' => ['kind' => 'null', 'col' => 'manager_id',
                'label' => 'موظفٌ بلا مدير مباشر',
                'why' => 'مسارات الإجازات والتقييم والطلبات تصعد إلى المدير المباشر — وبلا مديرٍ تتوقف عنده.',
                'fix' => 'حدّد المدير المباشر في ملف الموظف.']],

            // **نوعُ المستند من سجلّ المال لا من نصٍّ لا يُكتب** (v2.339): كان
            // الشرطُ `kind = 'فاتورة'` المجرّدة — ونوعٌ لا يكتبه النظام أصلاً
            // (الحقيقيّ «فاتورة مبيعات»/«فاتورة مشتريات»). فالفحصُ يُعرض في مركز
            // الجودة وعدُّه صفرٌ أبداً، وصفرٌ هناك يُقرأ «لا نقص» وهو «لم يُبحث».
            'fin' => ['partner' => ['kind' => 'blank_when', 'col' => 'partner',
                'whenCol' => 'kind', 'whenVal' => array_values(array_unique(array_merge(
                    (array) config('hub.fin.income', []), (array) config('hub.fin.expense', [])))),
                'label' => 'فاتورةٌ بلا طرف',
                'why' => 'بلا عميلٍ أو مورد لا تدخل الفاتورة أي كشف حساب ولا تقرير أعمار ديون.',
                'fix' => 'حدّد الطرف على المستند.']],

            'clients' => ['stale' => ['kind' => 'stale', 'col' => 'updated_at', 'days' => 90,
                'label' => 'عميلٌ راكد (٩٠ يوماً بلا تحديث)',
                'why' => 'موقفٌ لم يُلمس منذ ثلاثة أشهر: إمّا فرصةٌ منسيّة أو سجلٌّ يجب أرشفته — وكلاهما يشوّه التقارير.',
                'fix' => 'حدّث الموقف أو أرشف السجل بحذفٍ ناعم.']],
        ];

        $out = [];
        foreach ($all[$module] ?? [] as $k => $rule) {
            if (! in_array((string) $rule['col'], $cols, true)) continue;
            if (isset($rule['whenCol']) && ! in_array((string) $rule['whenCol'], $cols, true)) continue;
            $out[$k] = $rule;
        }

        return $out;
    }

    protected static function looksLikeExpiry(string $col): bool
    {
        foreach (self::EXPIRY_HINTS as $h) if (str_contains($col, $h)) return true;

        return false;
    }

    /** يطبّق قيد الفحص على استعلام — نفس القيد الذي عُدَّ به، فالرابط يقود لما عُدّ */
    public static function apply($q, string $module, string $rule)
    {
        $r = self::rules($module)[$rule] ?? null;
        if (! $r) return $q;
        $col = (string) $r['col'];

        return match ($r['kind']) {
            // **والفراغُ نصٌّ لا صفر** (v2.339): `orWhere($col, '')` على عمودٍ
            // عدديّ يُقارِن رقماً بنصٍّ فارغ — وMySQL يحوّله صفراً، فكميةٌ نافدة
            // وباقةٌ مجانية تُعدّان «مطلوبٌ وفارغ». إنذارٌ كاذبٌ يُدرَّب المستخدمُ
            // على تجاهله فيضيع الصادقُ معه. المقارنةُ النصّية للأعمدة النصّية وحدها.
            'req'  => $q->where(function ($w) use ($col, $r) {
                $w->whereNull($col);
                if (($r['numeric'] ?? null) !== true) $w->orWhere($col, '');
            }),
            'null' => $q->whereNull($col),
            'mail' => $q->whereNotNull($col)->where($col, '!=', '')->where($col, 'NOT LIKE', '%_@_%._%'),
            'url'  => $q->whereNotNull($col)->where($col, '!=', '')
                        ->where($col, 'NOT LIKE', 'http://%')->where($col, 'NOT LIKE', 'https://%'),
            'status' => $q->whereNotNull($col)->where($col, '!=', '')
                          ->whereNotIn($col, (array) ($r['options'] ?? [])),
            'ref'  => $q->whereNotNull($col)->where($col, '!=', '')
                        ->whereNotIn($col, fn ($sub) => $sub->select('id')
                            ->from(hub_mod((string) $r['ref'])['table'])),
            // `whenVal` قد تكون قيمةً واحدة أو قائمةَ أنواعٍ من سجلّ المال
            'blank_when' => $q->where(fn ($w) => is_array($r['whenVal'])
                                  ? $w->whereIn((string) $r['whenCol'], $r['whenVal'])
                                  : $w->where((string) $r['whenCol'], $r['whenVal']))
                              ->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
            'stale' => $q->where($col, '<', now()->subDays((int) ($r['days'] ?? 90))),
            default => $q,
        };
    }

    /**
     * المسح الكامل: كل وحدةٍ ذات سجلات، وكل فحصٍ يخصّها.
     *
     * وحدةٌ فارغة تُتخطّى باستعلام عدٍّ واحد — فتنصيبٌ جديد يمرّ بثلاثةٍ
     * وسبعين استعلاماً لا بمئات.
     *
     * @return array{checks:array, byModule:array, totals:array}
     */
    public static function scan(bool $fresh = false): array
    {
        $key = 'dq:scan';
        if ($fresh) Cache::forget($key);

        return Cache::remember($key, self::TTL, function () {
            $checks = [];
            $byModule = [];
            $rows = 0;
            $bad = 0;
            $sevAll = array_fill_keys(Severity::LEVELS, 0);

            // الترتيبُ **بالشدّة قبل العدد**: خمسةُ آلافِ «بلا شركة» لا تسبق
            // مرجعاً ماليّاً واحداً مكسوراً. وبعد الشدّة والعدد يُحسم التساوي
            // بالوحدة ثم بالمفتاح — فترتيبُ الشاشة واحدٌ على المحرّكين.
            $order = fn ($a, $b) => (Severity::rank($b['sev'] ?? '') <=> Severity::rank($a['sev'] ?? ''))
                ?: ($b['count'] <=> $a['count'])
                ?: (strcmp((string) $a['module'], (string) $b['module']))
                ?: (strcmp((string) $a['key'], (string) $b['key']));

            foreach (hub_modules() as $mk => $def) {
                $table = (string) ($def['table'] ?? '');
                if ($table === '' || ! Schema::hasTable($table)) continue;

                $base = fn () => DB::table($table)
                    ->when(Schema::hasColumn($table, 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'));

                $total = (int) $base()->count();
                if (! $total) continue;                   // وحدةٌ فارغة: لا نقص فيها
                $rows += $total;

                $disp = hub_display_col($mk);
                $modBad = 0;
                $modChecks = [];
                $modSev = array_fill_keys(Severity::LEVELS, 0);

                foreach (self::rules($mk) as $rk => $rule) {
                    try {
                        $n = (int) self::apply($base(), $mk, $rk)->count();
                    } catch (\Throwable $e) {
                        continue;                          // فحصٌ لا يصلح لهذا المحرّك لا يُسقط المسح
                    }
                    if (! $n) continue;

                    $sample = [];
                    try {
                        $sample = self::apply($base(), $mk, $rk)->limit(5)
                            ->get(['id', $disp])->map(fn ($x) => ['id' => $x->id, 'name' => $x->{$disp} ?? $x->id])->all();
                    } catch (\Throwable $e) {
                    }

                    $sev = (string) ($rule['sev'] ?? self::severity($rule));
                    $modSev[$sev] = ($modSev[$sev] ?? 0) + $n;
                    $sevAll[$sev] = ($sevAll[$sev] ?? 0) + $n;

                    $modBad += $n;
                    $modChecks[] = $rule + ['key' => $rk, 'module' => $mk, 'count' => $n,
                                            'total' => $total, 'sample' => $sample, 'sev' => $sev];
                }

                if ($modChecks) {
                    usort($modChecks, $order);
                    $checks = array_merge($checks, $modChecks);
                }

                $bad += $modBad;
                // `worst` = أشدُّ درجةٍ مأهولةٍ في الوحدة — عمودُ «بمَ نبدأ؟»
                $worst = collect(Severity::LEVELS)->filter(fn ($l) => ($modSev[$l] ?? 0) > 0)
                    ->sortByDesc(fn ($l) => Severity::rank($l))->first();
                $byModule[$mk] = ['key' => $mk, 'label' => $def['label'] ?? $mk, 'rows' => $total,
                                  'defects' => $modBad, 'checks' => count($modChecks),
                                  'sev' => $modSev, 'worst' => $worst,
                                  'score' => $total ? max(0, 100 - (int) round(min($modBad, $total) / $total * 100)) : 100];
            }

            usort($checks, $order);
            // الأسوأُ درجةً أولاً، ثم الأشدُّ نتائجَ حرجة، ثم المفتاح — لا قرعة
            uasort($byModule, fn ($a, $b) => ($a['score'] <=> $b['score'])
                ?: (($b['sev']['critical'] ?? 0) <=> ($a['sev']['critical'] ?? 0))
                ?: ($b['defects'] <=> $a['defects'])
                ?: strcmp((string) $a['key'], (string) $b['key']));

            return [
                'checks' => $checks,
                'byModule' => $byModule,
                'totals' => [
                    'rows' => $rows, 'defects' => $bad, 'checks' => count($checks),
                    'modules' => count($byModule),
                    // عدّادُ الشدّة = مجموعُ سجلات الفحوص بتلك الشدّة، لا رقمٌ ثانٍ
                    'sev' => $sevAll,
                    'clean' => count(array_filter($byModule, fn ($m) => $m['defects'] === 0)),
                    'score' => $rows ? max(0, 100 - (int) round(min($bad, $rows) / $rows * 100)) : 100,
                    'at' => now()->toDateTimeString(),
                ],
            ];
        });
    }

    /**
     * لقطةٌ يومية في السلسلة الزمنية — بها وحدها يصير للجودة **تاريخٌ**:
     * ما تحسّن هذا الشهر، وكم عيباً أُغلق هذا الأسبوع. بلا لقطةٍ لا إنجاز
     * يُقاس، ورقمُ اليوم وحده لا يقول إن كنا نتقدّم أم نتراجع.
     *
     * وتُكتب نقطةٌ **لكل وحدة** لا لـ`org` وحدها (spec §6.11): درجةٌ واحدةٌ
     * لثلاثٍ وسبعين وحدة تُجيب «هل تحسّنّا؟» ولا تُجيب أبداً «**أيُّ** وحدةٍ
     * تتدهور؟» — والجوابُ بلا سلسلةٍ لكل وحدةٍ ادّعاءٌ لا حساب.
     */
    public static function snapshot(?string $at = null): void
    {
        if (! Schema::hasTable('metric_points')) return;
        $t = $at ? \Illuminate\Support\Carbon::parse($at) : now()->startOfDay();
        $scan = self::scan(true);
        $s = $scan['totals'];

        hub_metric_put('quality', 'org', 'score', (float) $s['score'], $t, 'auto');
        hub_metric_put('quality', 'org', 'defects', (float) $s['defects'], $t, 'auto');
        hub_metric_put('quality', 'org', 'clean_modules', (float) $s['clean'], $t, 'auto');

        // مفتاحُ الوحدة يسع عمودَ `record_id` (أطولُ مفتاحٍ في السجل ١٢ حرفاً
        // والعمود ٣٦) — وسابقةُ `org` قائمةٌ منذ أول لقطة. والوحداتُ الفارغة
        // خارجَ `byModule` أصلاً: لا نكتب صفراً لوحدةٍ لم تُفحَص.
        foreach ($scan['byModule'] as $mk => $m) {
            hub_metric_put('quality', (string) $mk, 'defects', (float) $m['defects'], $t, 'auto');
            hub_metric_put('quality', (string) $mk, 'score', (float) $m['score'], $t, 'auto');
        }
    }

    /**
     * اتّجاهُ كل وحدة من لقطاتها — «أيُّ الوحدات تتدهور؟ ما الذي تحسّن؟»
     * (spec §6.11 · §47) **محسوباً** بفرقٍ بين نقطتين في السلسلة نفسِها.
     *
     * قاعدتان تحكمان الجواب:
     *  • **بلا لقطتين لا اتّجاه**: `delta`/`dir` تبقى `null` — «صفرُ تغيّر»
     *    جوابٌ كاذبٌ عن سؤالٍ لم يُقَس بعد، وبلا لقطةٍ واحدة تعود المصفوفة فارغة
     *    فتُصارح الشاشة بدل أن تخترع.
     *  • **العمرُ من أوّل نقطة**: `first_seen` أوّلُ لقطةٍ ظهر فيها نقصٌ في
     *    الوحدة (بلا حدّ المدى — العمرُ لا تقصّه نافذةُ العرض)، و`age_days`
     *    مسافتُها إلى اليوم؛ ووحدةٌ لم تعرف نقصاً قطُّ لا عمرَ لها (`null` لا صفر).
     *
     * @return array<string, array{key:string,label:string,points:int,defects:?int,was:?int,
     *                             delta:?int,score:?int,score_delta:?int,dir:?string,
     *                             first_seen:?string,age_days:?int}>
     */
    public static function moduleTrend(int $days = 60): array
    {
        if (! Schema::hasTable('metric_points')) return [];

        // ١) **طرفا كل سلسلة بالتجميع** لا السلسلةُ كلُّها: ثلاثٌ وسبعون وحدةً ×
        //    مقياسان × ستّون يوماً = نحوُ عشرةِ آلافِ صفٍّ تُسحب إلى الذاكرة عند كل
        //    فتحةِ شاشةٍ لقراءة نقطتين. التجميعُ يردّها إلى صفٍّ لكل سلسلة.
        $ends = DB::table('metric_points')->where('module', 'quality')
            ->whereIn('metric', ['defects', 'score'])
            ->where('at', '>=', now()->subDays($days))
            ->groupBy('record_id', 'metric')->orderBy('record_id')->orderBy('metric')
            ->get(['record_id', 'metric', DB::raw('MIN(at) as first_at'),
                   DB::raw('MAX(at) as last_at'), DB::raw('COUNT(*) as n')]);
        if ($ends->isEmpty()) return [];

        // ٢) قيمُ تلك الأطراف وحدها — واللقطةُ اليومية تكتب كلَّ الوحدات بالطابع
        //    نفسِه (`startOfDay`)، فالمجموعةُ طابعان في الغالب لا مئتان.
        $stamps = $ends->flatMap(fn ($e) => [(string) $e->first_at, (string) $e->last_at])
            ->unique()->values()->all();
        $val = [];
        foreach (DB::table('metric_points')->where('module', 'quality')
            ->whereIn('metric', ['defects', 'score'])->whereIn('at', $stamps)
            ->orderBy('at')->orderBy('id')->get(['record_id', 'metric', 'value', 'at']) as $v) {
            $val[(string) $v->record_id][(string) $v->metric][self::stamp($v->at)] = (float) $v->value;
        }

        // ٣) أوّلُ رصدٍ فعليّ (نقصٌ > صفر) — باستعلامٍ مجمَّعٍ واحد لا واحدٍ لكل وحدة
        $firstSeen = DB::table('metric_points')->where('module', 'quality')->where('metric', 'defects')
            ->where('value', '>', 0)->groupBy('record_id')
            ->pluck(DB::raw('MIN(at) as first_at'), 'record_id');

        // فهرسةُ الأطراف مرّةً واحدة: (وحدة، مقياس) ⇒ الطرفان وعددُ النقاط
        $idx = [];
        foreach ($ends as $e) {
            $rid = (string) $e->record_id;
            if ($rid === 'org' || ! hub_mod($rid)) continue;      // `org` تجميعٌ لا وحدة
            $m = (string) $e->metric;
            $idx[$rid][$m] = ['n' => (int) $e->n,
                              'first' => $val[$rid][$m][self::stamp($e->first_at)] ?? null,
                              'last'  => $val[$rid][$m][self::stamp($e->last_at)] ?? null];
        }

        $out = [];
        foreach ($idx as $mk => $metrics) {
            $blank = ['n' => 0, 'first' => null, 'last' => null];
            $d = $metrics['defects'] ?? $blank;
            $sc = $metrics['score'] ?? $blank;
            // نقطةٌ واحدة ⇒ لا فرق: «صفرُ تغيّر» جوابٌ عن سؤالٍ لم يُقَس بعد
            $delta = $d['n'] > 1 && $d['first'] !== null && $d['last'] !== null
                ? (int) round($d['last'] - $d['first']) : null;
            $seen = $firstSeen[$mk] ?? null;

            $out[$mk] = [
                'key' => $mk,
                'label' => hub_mod($mk)['label'] ?? $mk,
                'points' => $d['n'],
                'defects' => $d['last'] !== null ? (int) round($d['last']) : null,
                'was' => $d['n'] > 1 && $d['first'] !== null ? (int) round($d['first']) : null,
                'delta' => $delta,
                'score' => $sc['last'] !== null ? (int) round($sc['last']) : null,
                'score_delta' => $sc['n'] > 1 && $sc['first'] !== null && $sc['last'] !== null
                    ? (int) round($sc['last'] - $sc['first']) : null,
                // النقصُ ينزل ⇒ تحسّن، يصعد ⇒ تدهور، يثبت ⇒ استقرار
                'dir' => $delta === null ? null : ($delta > 0 ? 'worsening' : ($delta < 0 ? 'improving' : 'stable')),
                'first_seen' => $seen ? (string) $seen : null,
                'age_days' => $seen ? (int) \Illuminate\Support\Carbon::parse($seen)->startOfDay()
                    ->diffInDays(now()->startOfDay()) : null,
            ];
        }

        // الأشدُّ تدهوراً أولاً، ثم الأكثرُ نقصاً، ثم المفتاح — والذي بلا اتّجاهٍ آخِراً
        uasort($out, fn ($a, $b) => (($b['delta'] ?? PHP_INT_MIN) <=> ($a['delta'] ?? PHP_INT_MIN))
            ?: (($b['defects'] ?? 0) <=> ($a['defects'] ?? 0))
            ?: strcmp($a['key'], $b['key']));

        return $out;
    }

    /**
     * طابعُ لحظةٍ موحَّد للمطابقة بين استعلامَي التجميع والقيم — MIN/MAX تعودان
     * نصّاً من المحرّك، وصيغةُ النصّ تختلف بين sqlite وMySQL. المطابقةُ على نصٍّ
     * خامٍ **قرعةٌ** تُسقط القيمة صامتةً فتظهر الوحدةُ «بلا اتّجاه» وهي مقيسة.
     */
    protected static function stamp($at): string
    {
        return \Illuminate\Support\Carbon::parse($at)->format('Y-m-d H:i:s');
    }

    /** التاريخ: نقاطٌ مرتّبة + الفرق عن أول نقطةٍ في المدى */
    public static function history(int $days = 60): array
    {
        if (! Schema::hasTable('metric_points')) return ['points' => [], 'delta' => null, 'fixed' => null];

        $pts = DB::table('metric_points')->where('module', 'quality')->where('record_id', 'org')
            ->whereIn('metric', ['score', 'defects'])
            ->where('at', '>=', now()->subDays($days))->orderBy('at')->get(['metric', 'value', 'at']);

        $score = $pts->where('metric', 'score')->values();
        $def   = $pts->where('metric', 'defects')->values();

        return [
            'points' => $score->map(fn ($p) => ['at' => $p->at, 'value' => (float) $p->value])->all(),
            'delta'  => $score->count() > 1 ? (int) round($score->last()->value - $score->first()->value) : null,
            'fixed'  => $def->count() > 1 ? (int) round($def->first()->value - $def->last()->value) : null,
        ];
    }
}
