<?php

namespace App\Support\Platform\Modules;

use Illuminate\Http\Request;

/**
 * طرائقُ نُقلت من `ModuleController` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض
 * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ العشرة (`V1Controller` ومتحكّماتُ الجوال الثمانية تحته · `ApprovalDecisionController`)
 * لا يتغيّرون؛ وإعادةُ `MobileResourceController` تعريفَ `buildQuery` نافذةٌ كما كانت — لا طريقةَ منقولةً
 * أخرى تناديها، والمفوِّضُ يصله `parent::buildQuery`.
 */
final class ModuleQuery
{
    public const FL_OPS = [
        'text' => ['has', 'eq', 'neq', 'empty', 'nempty'],
        'ta' => ['has', 'eq', 'neq', 'empty', 'nempty'],
        'url' => ['has', 'empty', 'nempty'],
        'sel' => ['eq', 'neq', 'empty', 'nempty'],
        'num' => ['eq', 'neq', 'gt', 'lt', 'empty', 'nempty'],
        'big' => ['eq', 'neq', 'gt', 'lt', 'empty', 'nempty'],
        'date' => ['eq', 'before', 'after', 'empty', 'nempty'],
        'dt' => ['eq', 'before', 'after', 'empty', 'nempty'],
        'bool' => ['eq'],
    ];

    public const FL_LABELS = [
        'has' => 'يحوي', 'eq' => '=', 'neq' => '≠', 'gt' => '>', 'lt' => '<',
        'before' => 'قبل', 'after' => 'بعد', 'empty' => 'فارغ', 'nempty' => 'غير فارغ',
    ];

    /** استعلام القائمة الموحّد (بحث + حالة + فلاتر مراجع) — يخدم الفهرس والتصدير والكانبان */
    public static function buildQuery(Request $r, array $def, string $class, bool &$trash = false, array &$filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $trash = $r->boolean('trash') && hub_can(auth()->user(), $def['key'] ?? '', 'd');
        $q = $trash ? $class::onlyTrashed() : $class::query();
        $q = hub_scope($q, $def['key'] ?? '');          // نطاق المشاريع للحسابات المحدودة
        $q = hub_company_scope($q, $def['key'] ?? '');  // الشركة النشطة من الشريط العلوي
        $q = hub_client_scope($q, $def['key'] ?? '');   // مساحة عمل العميل من الشريط العلوي

        if ($term = hub_str($r->input('q'))) $q->search($term);

        // فحص جودة (`?qc=`): يفتح **نفس** السجلات التي عدّها مركز الجودة —
        // فالنقص يُفتح لا يُقرأ. والمفتاح يُطابَق على قائمة الفحوص المشتقّة من
        // السجل، فمفتاحٌ مُلفَّق لا يبني قيداً ولا يوسّع القائمة.
        if ($qc = hub_str($r->input('qc'))) {
            $rule = \App\Support\Insights\DataQuality::rules($def['key'] ?? '')[$qc] ?? null;
            if ($rule) {
                $q = \App\Support\Insights\DataQuality::apply($q, (string) ($def['key'] ?? ''), (string) $qc);
                $filters[] = ['key' => 'qc', 'label' => 'فحص جودة', 'val' => $qc, 'name' => $rule['label']];
            }
        }

        // العمود الفيزيائي لا المفتاح — في الوثائق المفتاح docStatus والعمود doc_status
        $statusCol = hub_status_col($def['key'] ?? '');
        if ($statusCol && ($st = hub_str($r->input('status'))) !== '') $q->where($statusCol, $st);

        // (الجولة 1 · F18) «المتأخرةُ فعلاً» بالاستحقاق لا بالحالة المكتوبة: حالةُ
        // «متأخرة» لا يكتبها أحدٌ آلياً، ففلترُ الحالة كان يخفي المستنداتِ المتجاوزةَ
        // استحقاقَها فعلاً. `?overdue=1` يلتقطها من الحقيقة: due فات، ولم تُسدَّد،
        // وليست ميتة (ملغاة/مسودة) — عرضٌ جاهزٌ تشير إليه لافتةُ الوحدة.
        if (($def['key'] ?? '') === 'fin' && $r->boolean('overdue')) {
            $notOverdue = array_merge((array) config('hub.fin.dead', []), ['مدفوعة']);
            $q->whereNotNull('due')->whereDate('due', '<', now()->toDateString())
                ->where(fn ($w) => $w->whereNull('state')->orWhereNotIn('state', $notOverdue))
                ->whereRaw('COALESCE(paid, 0) < COALESCE(total, 0)');
            $filters[] = ['key' => 'overdue', 'label' => 'الاستحقاق', 'val' => '1',
                          'name' => 'متأخرة فعلاً', 'rmurl' => $r->url()];
        }

        $fields = collect($def['fields']);
        foreach ((array) $r->input('f', []) as $fk => $fv) {
            if ($fv === '' || ! is_string($fv) || ! is_string($fk)) continue;

            $f = $fields->firstWhere('key', $fk);
            // حقلٌ محجوبٌ عن هذا المستخدم لا يُرشَّح به: الترشيح ثم رؤية النتائج
            // كاشفٌ لقيمته بالاستدلال — نفس حارس الفلاتر المتقدمة (applyAdvancedFilters).
            if ($f && hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $fk) === 'hide') continue;
            if ($f && ($f['type'] ?? '') === 'ref') {
                // المتعدد احتواءٌ في مصفوفة، والمفرد مساواة
                empty($f['multi'])
                    ? $q->where($f['col'], $fv)
                    : $q->whereJsonContains($f['col'], $fv);
                $filters[] = ['key' => $fk, 'label' => $f['label'], 'val' => $fv,
                              'name' => \App\Support\Platform\Modules\ModuleQuery::chipLabel((string) $f['ref'], $fv)];
                continue;
            }

            // أعمدة الربط الضمنية — قائمة بيضاء بعمودين لا غير. بلا هذا الحصر يصير
            // «عرض الكل» ترشيحاً على أي عمود يُسمّيه الرابط، وهو كاشفٌ للقيم بالاستدلال.
            $implicit = ['company_id' => 'companies', 'project_id' => 'projects'];
            if (isset($implicit[$fk]) && ($tbl = $def['table'] ?? null)
                && \Illuminate\Support\Facades\Schema::hasColumn($tbl, $fk)) {
                $q->where($fk, $fv);
                $filters[] = ['key' => $fk, 'label' => hub_mod($implicit[$fk])['label'] ?? $fk,
                              'val' => $fv,
                              'name' => \App\Support\Platform\Modules\ModuleQuery::chipLabel((string) $implicit[$fk], $fv)];
            }
        }

        \App\Support\Platform\Modules\ModuleQuery::applyAdvancedFilters($r, $def, $fields, $q, $filters);

        return $q;
    }

    /**
     * اسمُ شريحة الترشيح **داخل النطاق وحده** (v2.399): `?f[clientId]=<uuid>` كان يُترجم أيَّ
     * معرّفٍ إلى اسمه — عرّافاً لأسماء عملاء وشركات خارج العزل. خارجُ النطاق يبقى معرّفاً عارياً.
     */
    public static function chipLabel(string $ref, string $id): string
    {
        return (string) (hub_ref_options_scoped($ref, $id)[$id] ?? $id);
    }

    /**
     * باني الفلاتر المتقدم (v2.116): شروط مركبة fl[i][f|o|v] بمنطق «و».
     * كل شرط يُصادَق ضد تعريف الوحدة: الحقل موجود، ونوعه قابل للترشيح (لا أسرار
     * ولا ملفات — الترشيح على عمود سرّي كاشفٌ للقيم بالاستدلال)، وحقول الصلاحية
     * المخفية عن المستخدم لا تُرشَّح، والعامل من القائمة البيضاء لنوعه. ما فشل
     * بصادقةٍ يُتجاهل بصمت فلا يكسر رابطاً محفوظاً قديماً.
     */
    public static function applyAdvancedFilters(Request $r, array $def, $fields, $q, array &$filters): void
    {
        foreach (array_slice((array) $r->input('fl', []), 0, 10, true) as $i => $cond) {
            if (! is_array($cond)) continue;
            $fk = (string) ($cond['f'] ?? '');
            $op = (string) ($cond['o'] ?? '');
            $fv = $cond['v'] ?? '';
            if (! is_string($fv)) continue;

            $f = $fields->firstWhere('key', $fk);
            if (! $f) continue;
            $t = $f['type'] ?? 'text';
            if (! isset(\App\Support\Platform\Modules\ModuleQuery::FL_OPS[$t]) || ! in_array($op, \App\Support\Platform\Modules\ModuleQuery::FL_OPS[$t], true)) continue;
            if (hub_field_mode(auth()->user(), $def['key'] ?? '', $fk) === 'hide') continue;

            $needsVal = ! in_array($op, ['empty', 'nempty'], true);
            if ($needsVal && $fv === '') continue;
            if (in_array($t, ['sel'], true) && $needsVal && ! in_array($fv, $f['options'] ?? [], true)) continue;
            if (in_array($t, ['num', 'big'], true) && $needsVal && ! is_numeric($fv)) continue;

            $col = $f['col'];
            match ($op) {
                'has' => $q->where($col, 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $fv) . '%'),
                'eq' => $t === 'bool'
                    ? $q->where($col, (bool) ((int) $fv))
                    : $q->where($col, in_array($t, ['num', 'big'], true) ? $fv + 0 : $fv),
                'neq' => $q->where($col, '!=', in_array($t, ['num', 'big'], true) ? $fv + 0 : $fv),
                'gt' => $q->where($col, '>', $fv + 0),
                'lt' => $q->where($col, '<', $fv + 0),
                'before' => $q->whereDate($col, '<', $fv),
                'after' => $q->whereDate($col, '>', $fv),
                // «فارغ» على رقمٍ أو تاريخ = NULL وحده: مقارنةُ '' تُصنّف الصفرَ
                // فارغاً على MySQL (يحوّل '' إلى 0) وتُعيد غيرَه على SQLite —
                // انقسامٌ صامت بين المحرّكين ونتيجةٌ خاطئة في الإنتاج
                'empty' => in_array($t, ['num', 'big', 'date', 'dt'], true)
                    ? $q->whereNull($col)
                    : $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
                'nempty' => in_array($t, ['num', 'big', 'date', 'dt'], true)
                    ? $q->whereNotNull($col)
                    : $q->whereNotNull($col)->where($col, '!=', ''),
            };

            // رقاقة الشرط مع رابط إزالته وحده — بقية الشروط والمعايير تبقى
            $qs = $r->query();
            unset($qs['fl'][$i], $qs['page']);
            $filters[] = [
                'key' => "fl:$i", 'label' => $f['label'],
                'val' => $fv, 'op' => \App\Support\Platform\Modules\ModuleQuery::FL_LABELS[$op],
                'name' => $needsVal ? ($t === 'bool' ? ((int) $fv ? 'نعم' : 'لا') : $fv) : '',
                'rmurl' => $r->url() . ($qs ? '?' . http_build_query($qs) : ''),
            ];
        }
    }
}
