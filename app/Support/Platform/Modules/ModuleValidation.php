<?php

namespace App\Support\Platform\Modules;

/**
 * طرائقُ نُقلت من `ModuleController` بلا تغيير (docs/REORG_PLAN.md §R6) — والمتحكّمُ يفوّض
 * إليها بالتوقيعِ والظهورِ نفسَيهما، فالورثةُ (`V1Controller` · `ApprovalDecisionController` · `MobileWorkController`) لا يتغيّرون.
 */
final class ModuleValidation
{
    /** قواعد التحقق من تعريف الوحدة — الحقول السرية مطلوبة عند الإنشاء فقط (التعديل الفارغ يُبقي القديم) */
    public static function rules(array $def, bool $creating = true): array
    {
        $rules = [];
        foreach ($def['fields'] as $f) {
            // حقل ممنوع على الدور لا يُتحقق منه (وإلا استحال الحفظ بحقل إلزامي مخفي)
            if (hub_field_mode(auth()->user(), (string) ($def['key'] ?? ''), $f['key']) !== '') continue;

            // **الإلزامُ يُسأل عنه لا يُقرأ خاماً** (M-F2): حقلُ مرجعٍ قائمتُه خاويةٌ
            // لهذا القارئِ طريقٌ مسدود، و`hub_field_required` هي الحكمُ الواحدُ
            // الذي يقرؤه القالبُ أيضاً — فلا نجمةٌ ترسمها شاشةٌ ويكذّبها متحقّق.
            $required = hub_field_required((string) ($def['key'] ?? ''), $f)
                && ($creating || ($f['type'] ?? '') !== 'sec');
            $r = [$required ? 'required' : 'nullable'];
            $r[] = match ($f['type']) {
                'num', 'big' => 'numeric',
                'date', 'dt' => 'date',
                'file', 'img' => 'file',
                // كانت تسقط للنص: صندوق المتصفح يمر بـ«1» صدفةً، لكن الـ API
                // بقيمة منطقية true يُرفض برسالة «يجب أن يكون نصاً».
                'bool' => 'boolean',
                default => 'string',
            };
            if ($f['type'] === 'ref' && ($t = hub_ref_table($f['ref']))) {
                $r = empty($f['multi'])
                    ? [$r[0], "exists:$t,id"]
                    : [$r[0], 'array'];
            }
            if (in_array($f['type'], ['file', 'img'], true)) {
                // امتداداتُ التنفيذ والترميز محظورة: SVG/HTML تحمل سكربتاً يعمل
                // بأصل التطبيق إن فُتحت، وPHP قنبلةٌ إن لمسها الخادم يوماً.
                // البوابة تخدم الغريب تنزيلاً قسرياً — وهذا حزامُ الأمان الثاني.
                $r = [$r[0], 'file', 'max:' . hub_upload_cap()['kb'],
                    function ($attr, $file, $fail) {
                        $ext = strtolower((string) $file->getClientOriginalExtension());
                        if (in_array($ext, ['php', 'phtml', 'phar', 'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'mjs'], true)) {
                            $fail('هذا النوع من الملفات لا يُرفع — قد يحمل شيفرةً تنفيذية. حوّله إلى PDF أو صورة.');
                        }
                    }];
            }

            // سقفُ الطول من **عرض العمود نفسه**: كان الحقل النصّي يُتحقّق منه
            // كـ`string` بلا حدّ، وSQLite لا يفرض طول varchar فتمرّ الحزمة،
            // ثم يرفض MySQL القيمة في الإنتاج بـ22001. الرفضُ برسالةٍ للمستخدم
            // خيرٌ من خمسمئةٍ بعد أن يكون السجل قد كُتب.
            if (in_array($f['type'], ['text', 'sel', 'url', 'sec'], true)
                && ($w = hub_col_max($def['table'] ?? '', $f['col'] ?? $f['key']))) {
                $r[] = 'max:' . $w;
            }
            // **مدى العدد من العمود نفسه**: كان num/big يُتحقّق كـ`numeric`
            // بلا حدّ، فقيمةٌ تفوق decimal(M,D) تمرّ على SQLite ثم يرفضها MySQL بـ22003
            // (٥٠٠ ورسالةٌ تُسرّب القيمة). الحدُّ يرفضها للمستخدم قبل القاعدة.
            // ومدىً لا سقفاً متناظراً (v2.550): العمودُ الصحيحُ لم يكن يُقرأ أصلاً،
            // و٨٣ من ٨٧ تصريحاً منه `unsigned` — أرضيّتُه صفرٌ، فـ`-5` في
            // `unsignedTinyInteger` يرفضه MySQL بالخطأ نفسِه الذي يرفض به ٩٩٩٩.
            if (in_array($f['type'], ['num', 'big'], true)
                && ($rg = hub_col_num_range($def['table'] ?? '', $f['col'] ?? $f['key'])) !== null) {
                $r[] = 'between:' . $rg[0] . ',' . $rg[1];   // نصٌّ دقيقٌ من تصريحِ العمود (لا float)
            }
            // وقائمةُ الخيارات تُلزِم: شاشةُ الحالة تفرضها منذ v2.x والنموذج لا
            if (($f['type'] ?? '') === 'sel' && ! empty($f['options'])) {
                $r[] = \Illuminate\Validation\Rule::in($f['options']);
            }
            // **قيودٌ يعلنها السجلّ** (v2.399) — لا شيفرةَ لكل وحدة:
            //  · `ta` بسقف عمود TEXT (كان بلا حدّ فيمرّ على SQLite ويرفضه MySQL بعد الكتابة)
            //  · `min` للأعداد (كمّيةُ المخزون لا تكون سالبة من نموذج التعديل العامّ)
            //  · `format: time` لحقول الوقت النصّية (كان «صباحاً 9» يُحسب ٤٩٦٧٦١ ساعة)
            //  · `unique` لعمودٍ متفرّد (رقمُ العرض) — بالرسالة لا بفهرسٍ يسقط على بياناتٍ قائمة
            if ($f['type'] === 'ta') $r[] = 'max:' . (hub_col_max($def['table'] ?? '', $f['col'] ?? $f['key']) ?: 65535);
            if (isset($f['min']) && in_array($f['type'], ['num', 'big'], true)) $r[] = 'min:' . $f['min'];
            if (($f['format'] ?? '') === 'time') $r[] = 'regex:/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/';
            if (! empty($f['unique']) && ! empty($def['table'])) {
                $uq = \Illuminate\Validation\Rule::unique($def['table'], $f['col'] ?? $f['key'])->ignore(request()->route('id'));
                if (hub_has_col($def['table'], 'deleted_at')) $uq->whereNull('deleted_at');
                $r[] = $uq;
            }

            $rules[$f['key']] = $r;
        }

        // **تفرّدٌ مركّب** (v2.399): `unique_together` في تعريف الوحدة — موظفٌ ويومٌ في الحضور
        // مثلاً: صفّان بالمفاتيح نفسِها كانا يُقبلان ولا ترى الخدمةُ الذاتية إلا الأول.
        foreach ((array) ($def['unique_together'] ?? []) as $combo) {
            $combo = array_values((array) $combo);
            $first = $combo[0] ?? null;
            if (! $first || ! isset($rules[$first]) || empty($def['table'])) continue;
            $rules[$first][] = function ($attr, $value, $fail) use ($def, $combo) {
                $q = \Illuminate\Support\Facades\DB::table($def['table']);
                foreach ($combo as $k) {
                    $f = collect($def['fields'])->firstWhere('key', $k);
                    $v = request()->input($k);
                    if (! $f || $v === null || $v === '' || is_array($v)) return;
                    ($f['type'] ?? '') === 'date'
                        ? $q->whereDate($f['col'] ?? $k, substr((string) $v, 0, 10))
                        : $q->where($f['col'] ?? $k, $v);
                }
                if ($id = request()->route('id')) $q->where('id', '!=', $id);
                if (hub_has_col($def['table'], 'deleted_at')) $q->whereNull('deleted_at');
                if ($q->exists()) $fail('يوجد سجلٌّ بهذه القيم نفسِها — لا يُكرَّر');
            };
        }

        // الحقول المخصصة (باني الحقول)
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) {
            $r = [! empty($cf['required']) ? 'required' : 'nullable'];
            $r[] = match ($cf['type'] ?? 'text') {
                'num'  => 'numeric',
                'date' => 'date',
                default => 'string',
            };
            if (($cf['type'] ?? '') === 'ref' && ($t = hub_ref_table($cf['ref'] ?? ''))) $r[] = "exists:$t,id";
            $rules['custom.' . $cf['key']] = $r;
        }

        return $rules;
    }

    /** تسميات الحقول العربية لرسائل التحقق (:attribute) — تشمل الحقول المخصصة */
    public static function attrs(array $def): array
    {
        $out = [];
        foreach ($def['fields'] as $f) $out[$f['key']] = $f['label'];
        foreach (hub_custom_fields($def['key'] ?? null) as $cf) $out['custom.' . $cf['key']] = $cf['label'];

        return $out;
    }
}
