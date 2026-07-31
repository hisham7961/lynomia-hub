<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Employee;

/**
 * تعيين المرشح بنقرة: كانت «تم التعيين» تنشئ مهمة تجهيزٍ فقط ويُعاد إدخال
 * بيانات الموظف يدوياً — على نمط ترقية الفكرة لمشروع، ينشأ الملف الوظيفي
 * من بيانات المرشح ويُربط الطرفان (idempotent عبر meta).
 */
class HireController extends Controller
{
    public function hire(string $id)
    {
        abort_unless(hub_can(auth()->user(), 'recruit', 'e'), 403, 'التعيين يتطلب صلاحية تعديل التوظيف');
        abort_unless(hub_can(auth()->user(), 'hr', 'a'), 403, 'التعيين يتطلب صلاحية إضافة ملفات وظيفية');
        $c = hub_scope(Candidate::query(), 'recruit')->findOrFail($id);

        $meta = (array) ($c->meta ?? []);
        if (! empty($meta['employee_id']) && Employee::find($meta['employee_id'])) {
            return redirect()->route('m.show', ['hr', $meta['employee_id']])
                ->with('ok', 'عُيّن من قبل — هذا ملفه الوظيفي');
        }

        $emp = Employee::create([
            'name' => $c->name,
            'title' => $c->job,
            'dept' => $c->dept,
            'email' => $c->email,
            'phone' => $c->phone,
            'salary' => is_numeric($c->expect) ? (float) $c->expect : null,
            'hired' => now()->toDateString(),
            'status' => 'نشط',
            'company_id' => $c->company_id,
            'notes' => 'عُيّن من مسار التوظيف — مرشح ' . $c->name,
        ]);

        $c->stage = 'تم التعيين';
        $c->meta = $meta + ['employee_id' => $emp->id];
        $c->save();
        \App\Support\FlowRunner::fire('status', 'recruit', $c, 'تم التعيين');
        hub_audit('تعيين مرشح', 'recruit', $c->id, $c->name);

        return redirect()->route('m.show', ['hr', $emp->id])
            ->with('ok', '🎉 عُيّن المرشح — أكمل ملفه الوظيفي (الراتب والإقامة والعهدة)');
    }
}
