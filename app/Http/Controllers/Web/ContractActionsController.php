<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;

/**
 * سلسلة العقد (CLM م7): ملحق وتجديد بنمط toContract المرسّخ — نسخٌ كمسودة
 * تحمل parent_id وkind، والتجديد يقلب الأصل «قيد التجديد» بحفظ Eloquent
 * فيطلق contract.renewed. مكتبة البنود تُدار هنا أيضاً (تخزين settings).
 */
class ContractActionsController extends Controller
{
    /** الأعمدة المنسوخة للملحق/التجديد — لا توقيعات ولا أرقام (الرقم يتولد) */
    protected const COPY = ['title', 'type', 'client_id', 'company_id', 'project_id', 'party',
        'value', 'currency', 'date_start', 'date_end', 'renewal', 'notice', 'owner_id',
        'alerts', 'obligations', 'notes'];

    protected function find(string $id): Contract
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'a'), 403);

        return hub_scope(Contract::query(), 'contracts')->findOrFail($id);
    }

    protected function copyFrom(Contract $c, string $kind, string $titlePrefix): Contract
    {
        $data = collect(self::COPY)->mapWithKeys(fn ($col) => [$col => $c->{$col}])->all();
        $data['title'] = $titlePrefix . ' — ' . mb_substr((string) $c->title, 0, 240);
        $data['status'] = 'مسودة';
        $data['parent_id'] = $c->id;
        $data['kind'] = $kind;

        return Contract::create($data);
    }

    /** ملحق تعديل: مسودة جديدة مرتبطة بالأصل — تُحرر ثم تمر بدورة التوقيع نفسها */
    public function amend(string $id)
    {
        $c = $this->find($id);
        $new = $this->copyFrom($c, 'ملحق', 'ملحق');
        hub_audit('إنشاء ملحق عقد', 'contracts', $c->id, $c->title . ' → ' . $new->doc_no);

        return redirect()->route('m.show', ['contracts', $new->id])
            ->with('ok', 'أُنشئ الملحق ' . $new->doc_no . ' كمسودة مرتبطة بالأصل — حرّره ثم أرسله للتوقيع');
    }

    /** تجديد: مسودة تجديد + الأصل «قيد التجديد» (بحفظ Eloquent → contract.renewed) */
    public function renew(string $id)
    {
        // التجديد يكتب «قيد التجديد» على العقد الأصل — فهو تعديلٌ يمرّ بالطابور
        if ($why = hub_block_if_queued('contracts')) return back()->with('err', $why);
        $c = $this->find($id);
        // idempotent: مسودة تجديدٍ قائمة لهذا الأصل لا تُكرر بنقرة متعجلة
        if ($ex = Contract::where('parent_id', $c->id)->where('kind', 'تجديد')
                ->where('status', 'مسودة')->first()) {
            return redirect()->route('m.show', ['contracts', $ex->id])
                ->with('ok', 'مسودة التجديد قائمة بالفعل: ' . $ex->doc_no);
        }

        $new = self::spawnRenewal($c);
        hub_audit('بدء تجديد عقد', 'contracts', $c->id, $c->title . ' → ' . $new->doc_no);

        return redirect()->route('m.show', ['contracts', $new->id])
            ->with('ok', 'أُنشئت مسودة التجديد ' . $new->doc_no . ' والأصل صار «قيد التجديد»');
    }

    /**
     * إنشاء مسودة التجديد وقلب الأصل «قيد التجديد» — يشترك فيها زر التجديد
     * وأتمتة hub:automation (مسودة قبل النهاية بمدة الإشعار). null = مسودة قائمة.
     */
    public static function spawnRenewal(Contract $c): ?Contract
    {
        if (Contract::where('parent_id', $c->id)->where('kind', 'تجديد')
                ->where('status', 'مسودة')->exists()) {
            return null;
        }

        $data = collect(self::COPY)->mapWithKeys(fn ($col) => [$col => $c->{$col}])->all();
        $data['title'] = 'تجديد — ' . mb_substr((string) $c->title, 0, 240);
        $data['status'] = 'مسودة';
        $data['parent_id'] = $c->id;
        $data['kind'] = 'تجديد';
        $new = Contract::create($data);

        if ($c->status === 'ساري' || $c->status === 'منتهي') {
            $c->status = 'قيد التجديد';
            $c->save();
            \App\Support\FlowRunner::fire('renewed', 'contracts', $c);
        }

        return $new;
    }

    /* ────────── مكتبة البنود (settings — الإدراج نسخٌ بالقيمة) ────────── */

    public function storeClause(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'e'), 403);
        $d = $r->validate(['name' => 'required|string|max:120', 'body' => 'required|string|max:20000']);

        $clauses = self::clauses();
        $clauses[] = ['name' => $d['name'], 'body' => $d['body']];
        \App\Models\Setting::updateOrCreate(['key' => 'contracts.clauses'],
            ['value' => array_slice($clauses, 0, 100)]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        hub_audit('إضافة بند لمكتبة البنود', 'contracts', null, $d['name']);

        return back()->with('ok', 'أُضيف البند للمكتبة — إدراجه في قالبٍ نسخٌ بقيمته فلا تتأثر الوثائق القديمة بتعديله');
    }

    public function destroyClause(Request $r)
    {
        abort_unless(hub_can(auth()->user(), 'contracts', 'e'), 403);
        $i = (int) $r->input('i', -1);

        $clauses = self::clauses();
        if (! isset($clauses[$i])) return back()->with('err', 'البند غير موجود');
        $name = $clauses[$i]['name'] ?? '';
        array_splice($clauses, $i, 1);
        \App\Models\Setting::updateOrCreate(['key' => 'contracts.clauses'], ['value' => $clauses]);
        \Illuminate\Support\Facades\Cache::forget('settings:all');
        hub_audit('حذف بند من مكتبة البنود', 'contracts', null, $name);

        return back()->with('ok', 'حُذف البند من المكتبة — الوثائق التي أُدرج فيها لا تتأثر');
    }

    /** بنود المكتبة منظفةً — تقبل التخزين مصفوفةً أو نص JSON */
    public static function clauses(): array
    {
        $raw = setting('contracts.clauses');
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (! is_array($raw)) return [];

        return array_values(array_filter(array_map(
            fn ($c) => is_array($c) && trim((string) ($c['name'] ?? '')) !== '' && trim((string) ($c['body'] ?? '')) !== ''
                ? ['name' => mb_substr((string) $c['name'], 0, 120), 'body' => mb_substr((string) $c['body'], 0, 20000)]
                : null,
            $raw)));
    }
}
