<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\QuoteMilestone;
use Illuminate\Http\Request;

/**
 * بنّاء العرض المهنيّ: بنودٌ مهيكلة ومراحلُ دفع — تُدار خارج المتحكم العام
 * لأنها كياناتٌ ابنة، والإجماليُّ يُعاد حسابه خادمياً بعد كل تغيير (Quote::recalc).
 *
 * كلُّ فعلٍ يمرّ بتنطيق العرض الأمّ (hub_scope) وصلاحية تعديله — فلا يُعدَّل
 * بندٌ على عرضٍ خارج نطاق المستخدم أو بلا صلاحية.
 */
class QuoteBuilderController extends Controller
{
    protected function quote(string $id): Quote
    {
        abort_unless(hub_can(auth()->user(), 'quotes', 'e'), 403, 'تعديل بنود العرض يتطلب صلاحية تعديل العروض');
        $q = hub_scope(Quote::query(), 'quotes')->findOrFail($id);
        // عرضٌ مقبولٌ أو محوّلٌ لا تُعدَّل بنوده — تاريخُه التجاريّ محفوظ
        abort_if(in_array($q->status, ['مقبول', 'محوّل'], true), 422,
            'العرضُ مقبولٌ/محوّل — بنودُه مجمَّدةٌ حفظاً للتاريخ التجاريّ. أنشئ نسخةً جديدة للتعديل.');

        return $q;
    }

    public function storeLine(Request $r, string $id)
    {
        $q = $this->quote($id);
        // العنوانُ مطلوبٌ إلا حين يُنتقى من الكتالوج (يُملأ منه اسمُ الخدمة)
        $d = $r->validate([
            'title' => [$r->filled('service_id') ? 'nullable' : 'required', 'string', 'max:300'],
            'kind' => ['nullable', 'string', 'max:60'],
            'phase' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'qty' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'unit' => ['nullable', 'string', 'max:60'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'service_id' => ['nullable', 'string', 'max:36'],
            'product_id' => ['nullable', 'string', 'max:36'],
            // تصنيفُ الإيراد (CPQ): لمرّة/دوري/استخدام/تكلفة ممرَّرة + دوريّته
            'rev_type' => ['nullable', 'in:one_time,recurring,usage,pass_through'],
            'rev_period' => ['nullable', 'string', 'max:20'],
            // نمطُ البند (CPQ ب): أساسيّ/اختياريّ/بديل/إضافة + مجموعةُ البدائل
            'line_mode' => ['nullable', 'in:required,optional,alternative,addon'],
            'opt_group' => ['nullable', 'string', 'max:120'],
        ]);

        // **منتقي الكتالوج**: خدمةٌ مختارةٌ تملأ الفراغَ (سعر/تكلفة/وحدة/اسم) من
        // سجل الخدمات المنطَّق — لا إدخالٌ مكرّر. القيمُ المُدخَلةُ يدوياً تفوز.
        if (! empty($d['service_id'])) {
            $svc = hub_scope(\App\Models\Service::query(), 'services')->find($d['service_id']);
            if ($svc) {
                $d['unit_price'] = ($d['unit_price'] ?? null) ?? $svc->price;
                $d['unit_cost'] = ($d['unit_cost'] ?? null) ?? $svc->cost;
                $d['unit'] = ($d['unit'] ?? '') ?: $svc->unit;
                if (($d['title'] ?? '') === '') $d['title'] = (string) $svc->name;
            }
        }

        // الاختياريُّ/البديل لا يدخل الخطَّ المُلتزَم افتراضياً؛ الأساسيُّ يدخل
        $d['included'] = ($d['line_mode'] ?? 'required') === 'required';
        $d['quote_id'] = $q->id;
        $d['sort'] = (int) QuoteLine::where('quote_id', $q->id)->max('sort') + 1;
        QuoteLine::create($d);   // line_total يُحسب في saving()
        $q->recalc();
        hub_audit('إضافة بند عرض', 'quotes', $q->id, $q->doc_no . ' — ' . $d['title']);

        return back()->with('ok', 'أُضيف البند وأُعيد حساب الإجمالي');
    }

    public function destroyLine(string $id, string $line)
    {
        $q = $this->quote($id);
        QuoteLine::where('quote_id', $q->id)->where('id', $line)->delete();
        $q->recalc();

        return back()->with('ok', 'حُذف البند وأُعيد حساب الإجمالي');
    }

    /**
     * إدراجُ/إخراجُ بندٍ اختياريّ في الخطّ المُلتزَم (CPQ ب) — يُعيد الحساب.
     * البديلُ المُدرَج يُخرِج بقيةَ مجموعته (بديلٌ واحدٌ يُختار).
     */
    public function toggleLine(string $id, string $line)
    {
        $q = $this->quote($id);
        $l = QuoteLine::where('quote_id', $q->id)->findOrFail($line);
        abort_if(($l->line_mode ?: 'required') === 'required', 422, 'البندُ الأساسيُّ لا يُخرَج');

        $now = ! $l->included;
        if ($now && $l->line_mode === 'alternative' && $l->opt_group) {
            // بديلٌ واحدٌ لكل مجموعة: يُطفأ الباقي عند إدراج واحد
            QuoteLine::where('quote_id', $q->id)->where('opt_group', $l->opt_group)
                ->where('id', '!=', $l->id)->update(['included' => false]);
        }
        $l->forceFill(['included' => $now])->save();
        $q->recalc();

        return back()->with('ok', $now ? 'أُدرِج البندُ في الخطّ المُلتزَم' : 'أُخرِج البندُ (فرصةٌ عُلويّة)');
    }

    public function storeMilestone(Request $r, string $id)
    {
        $q = $this->quote($id);
        $d = $r->validate([
            'title' => ['required', 'string', 'max:300'],
            'pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'trigger' => ['nullable', 'string', 'max:200'],
            'phase' => ['nullable', 'string', 'max:200'],
            'due_note' => ['nullable', 'string', 'max:200'],
        ]);
        $d['quote_id'] = $q->id;
        $d['sort'] = (int) QuoteMilestone::where('quote_id', $q->id)->max('sort') + 1;
        QuoteMilestone::create($d);

        return back()->with('ok', 'أُضيفت دفعة');
    }

    public function destroyMilestone(string $id, string $ms)
    {
        $q = $this->quote($id);
        QuoteMilestone::where('quote_id', $q->id)->where('id', $ms)->delete();

        return back()->with('ok', 'حُذفت الدفعة');
    }
}
