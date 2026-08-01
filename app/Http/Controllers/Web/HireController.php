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
    public function hire(\Illuminate\Http\Request $r, string $id)
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

        /*
         * **حسابُ النظام مع التعيين لا بعده بأسبوع**: كان التعيين يُنشئ الملف
         * الوظيفي وحده، فيبقى الموظف الجديد بلا حساب حتى يتذكّر أحدهم — ولا
         * أحد يراجع صلاحياته لأن لا شيء يُنبّه. الآن: دورٌ يُختار لحظة التعيين،
         * وكلمةُ مرورٍ مؤقتة تُعرض مرةً واحدة، و**إشعارٌ لكل من يدير المستخدمين
         * لمراجعة الصلاحيات**. وحارس التصعيد نفسه يسري: الملكية لا يمنحها إلا مالك.
         */
        $temp = null;
        $roleId = (string) $r->input('role_id', '');
        if ($roleId !== '' && hub_can(auth()->user(), 'users', 'v') || ($roleId !== '' && hub_flag(auth()->user(), 'users'))) {
            $role = \App\Models\Role::find($roleId);
            abort_if($role && $role->is_owner && ! hub_is_owner(), 403, 'منح دور المالك لا يكون إلا من مالك');

            $email = trim((string) $c->email);
            if ($role && $email !== '' && ! \App\Models\User::where('email', $email)->exists()) {
                $temp = \Illuminate\Support\Str::password(14);
                $user = \App\Models\User::create([
                    'name' => $c->name, 'email' => $email, 'phone' => $c->phone,
                    'job_title' => $c->job, 'role_id' => $role->id, 'status' => 'نشط',
                    'password' => $temp,
                    // بلا ختمِ تجديد: الحساب يبدأ بكلمةٍ مؤقتة يجب تبديلها
                    'password_changed_at' => null,
                ]);
                $emp->update(['user_id' => $user->id]);
                hub_audit('إنشاء حساب لموظف جديد', 'users', $user->id, $user->name . ' — دور ' . $role->name);

                foreach (hub_user_admins() as $adminId) {
                    hub_notify($adminId, 'مستخدمون',
                        '👤 أُنشئ حساب لـ«' . $c->name . '» بدور «' . $role->name . '» عند التعيين — راجع صلاحياته',
                        'users', $user->id);
                }
            }
        }

        $c->stage = 'تم التعيين';
        $c->meta = $meta + ['employee_id' => $emp->id];
        $c->save();
        \App\Support\FlowRunner::fire('status', 'recruit', $c, 'تم التعيين');
        hub_audit('تعيين مرشح', 'recruit', $c->id, $c->name);

        $msg = '🎉 عُيّن المرشح — أكمل ملفه الوظيفي (الراتب والإقامة والعهدة)';
        if ($temp) {
            // تُعرض مرةً واحدة: لا تُخزَّن ولا تُرسَل بريداً — سلّمها بيدك
            $msg .= ' · أُنشئ حسابه، وكلمة المرور المؤقتة: ' . $temp . ' (تُعرض مرةً واحدة — يبدّلها عند أول دخول)';
        }

        return redirect()->route('m.show', ['hr', $emp->id])->with('ok', $msg);
    }
}
