<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ChangeOrder;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * أوامرُ التغيير (CPQ ج): تطبيقُ أمرٍ معتمَدٍ على المشروع فيتطوّر خطُّ أساسه —
 * **بلا مسّ العرض المقبول** (الأصلُ في `project.meta.baseline` يبقى). معاملاتيٌّ
 * وقفلُ صفٍّ وidempotent (لا تطبيقٌ مرتين)، وطباعةُ مستندِ التغيير الاحترافيّ.
 */
class ChangeOrderController extends Controller
{
    /** تطبيقُ أمر التغيير على المشروع — يمدّد القيمةَ التعاقدية والتكلفةَ والجدول */
    public function apply(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'changeorders', 'e'), 403, 'تطبيق أمر التغيير يتطلب صلاحية تعديل');
        $co = hub_scope(ChangeOrder::query(), 'changeorders')->findOrFail($id);
        // **منعُ التطبيق المزدوج قبل حارس الحالة** (نمط toProject): المطبَّقُ سلفاً
        // يعود بردٍّ لطيفٍ لا بـ٤٢٢ — فحالتُه صارت «مطبَّق» لا «معتمد».
        if ($co->applied_at) {
            return redirect()->route('m.show', ['projects', $co->project_id])->with('ok', 'طُبِّق من قبل');
        }
        abort_unless($co->status === 'معتمد', 422, 'يُعتمَد أمرُ التغيير أولاً ثم يُطبَّق');
        abort_unless($co->project_id, 422, 'أمرُ التغيير بلا مشروعٍ مرتبط');

        return DB::transaction(function () use ($co) {
            $co = ChangeOrder::whereKey($co->getKey())->lockForUpdate()->firstOrFail();
            if ($co->applied_at) {
                return redirect()->route('m.show', ['projects', $co->project_id])->with('ok', 'طُبِّق من قبل');
            }
            $project = Project::whereKey($co->project_id)->lockForUpdate()->first();
            abort_unless($project, 422, 'المشروع غير موجود');

            // يُلحَق بخطّ الأساس سجلُّ التغيير (الأصلُ يبقى) وتتطوّر أرقامُ المشروع
            $meta = (array) $project->meta;
            $meta['baseline'] = ($meta['baseline'] ?? []);
            $meta['baseline']['change_orders'][] = [
                'co_id' => $co->id, 'co_no' => $co->doc_no,
                'value_delta' => (string) $co->value_delta, 'cost_delta' => (string) ($co->cost_delta ?? 0),
                'timeline_days' => (int) $co->timeline_days, 'applied_at' => now()->toIso8601String(),
            ];
            $project->forceFill([
                'meta' => $meta,
                'rev_exp' => round((float) $project->rev_exp + (float) $co->value_delta, 3),
                'budget' => round((float) $project->budget + (float) ($co->cost_delta ?? 0), 3),
            ])->saveQuietly();

            $co->forceFill(['status' => 'مطبَّق', 'applied_at' => now()])->save();
            \App\Support\FlowRunner::fire('status', 'changeorders', $co, 'مطبَّق');
            hub_audit('تطبيق أمر تغيير', 'changeorders', $co->id, $co->doc_no . ' → ' . $project->name);

            return redirect()->route('m.show', ['projects', $project->id])
                ->with('ok', '📋 طُبِّق أمرُ التغيير — تطوّرت القيمةُ التعاقدية للمشروع');
        });
    }

    /** مستندُ أمر التغيير الاحترافيّ (PDF مع تساقطٍ لـHTML) */
    public function pdf(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'changeorders', 'v'), 403);
        $co = hub_scope(ChangeOrder::query(), 'changeorders')->findOrFail($id);
        $html = \App\Support\ChangeOrderDoc::html($co);
        $bin = \App\Support\DocRenderer::pdf($html, 'أمر تغيير ' . $co->doc_no);
        if ($bin === null) {
            return response($html)->header('Content-Type', 'text/html; charset=utf-8');
        }
        hub_audit('توليد مستند أمر تغيير', 'changeorders', $co->id, $co->doc_no);

        return response($bin)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="change-order-' . $co->doc_no . '.pdf"');
    }
}
