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
 */
class DataQuality
{
    /** ثوانٍ لتخبئة المسح — الفحص الكامل مئات الاستعلامات */
    public const TTL = 600;

    /** أعمدةٌ يدل اسمها على انتهاء صلاحية */
    protected const EXPIRY_HINTS = ['expiry', 'expires', 'expire_at', 'date_end', 'end_date',
                                    'valid_to', 'renew_at', 'warranty_end', 'due'];

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
        $statusCol = (string) ($def['status'] ?? '');
        if ($statusCol && in_array($statusCol, $cols, true)) {
            $opts = collect($def['fields'] ?? [])->firstWhere('col', $statusCol)['options'] ?? [];
            if (is_array($opts) && count($opts) > 1) {
                $out['status'] = ['kind' => 'status', 'col' => $statusCol, 'options' => array_values($opts),
                    'label' => 'حالةٌ خارج الخيارات المعرَّفة',
                    'why' => 'قيمةٌ لا يعرفها السجل (استيرادٌ أو تعديلٌ مباشر) — فلا تُعدّ في أي إحصاءٍ حسب الحالة ولا تُطلق أتمتة.',
                    'fix' => 'اختر حالةً من قائمة الوحدة.'];
            }
        }

        return $memo[$module] = $out + self::curated($module, $cols);
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

            'fin' => ['partner' => ['kind' => 'blank_when', 'col' => 'partner',
                'whenCol' => 'kind', 'whenVal' => 'فاتورة',
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
            'req'  => $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
            'null' => $q->whereNull($col),
            'mail' => $q->whereNotNull($col)->where($col, '!=', '')->where($col, 'NOT LIKE', '%_@_%._%'),
            'url'  => $q->whereNotNull($col)->where($col, '!=', '')
                        ->where($col, 'NOT LIKE', 'http://%')->where($col, 'NOT LIKE', 'https://%'),
            'status' => $q->whereNotNull($col)->where($col, '!=', '')
                          ->whereNotIn($col, (array) ($r['options'] ?? [])),
            'ref'  => $q->whereNotNull($col)->where($col, '!=', '')
                        ->whereNotIn($col, fn ($sub) => $sub->select('id')
                            ->from(hub_mod((string) $r['ref'])['table'])),
            'blank_when' => $q->where((string) $r['whenCol'], $r['whenVal'])
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

                    $modBad += $n;
                    $modChecks[] = $rule + ['key' => $rk, 'module' => $mk, 'count' => $n,
                                            'total' => $total, 'sample' => $sample];
                }

                if ($modChecks) {
                    usort($modChecks, fn ($a, $b) => $b['count'] <=> $a['count']);
                    $checks = array_merge($checks, $modChecks);
                }

                $bad += $modBad;
                $byModule[$mk] = ['label' => $def['label'] ?? $mk, 'rows' => $total,
                                  'defects' => $modBad, 'checks' => count($modChecks),
                                  'score' => $total ? max(0, 100 - (int) round(min($modBad, $total) / $total * 100)) : 100];
            }

            usort($checks, fn ($a, $b) => $b['count'] <=> $a['count']);
            uasort($byModule, fn ($a, $b) => $a['score'] <=> $b['score']);

            return [
                'checks' => $checks,
                'byModule' => $byModule,
                'totals' => [
                    'rows' => $rows, 'defects' => $bad, 'checks' => count($checks),
                    'modules' => count($byModule),
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
     */
    public static function snapshot(?string $at = null): void
    {
        if (! Schema::hasTable('metric_points')) return;
        $t = $at ? \Illuminate\Support\Carbon::parse($at) : now()->startOfDay();
        $s = self::scan(true)['totals'];

        hub_metric_put('quality', 'org', 'score', (float) $s['score'], $t, 'auto');
        hub_metric_put('quality', 'org', 'defects', (float) $s['defects'], $t, 'auto');
        hub_metric_put('quality', 'org', 'clean_modules', (float) $s['clean'], $t, 'auto');
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
