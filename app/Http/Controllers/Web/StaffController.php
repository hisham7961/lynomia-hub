<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\Staff;
use Illuminate\Http\Request;

/**
 * **الموظفون وحساباتهم** — شاشةُ الفجوات بين الطرفين، وإغلاقُها بنقرة.
 *
 * الربطُ صار تلقائياً بالبريد، لكن ما تراكم قبل ذلك يحتاج بابَ إصلاح: ملفٌّ
 * بلا حساب، وحسابٌ بلا ملف، واسمٌ اختلف بين الطرفين، وخدمةٌ انتهت وحسابٌ حيّ.
 * الشاشة تُري الأربعة وتُغلقها — لا تقريرٌ يُقرأ ويُنسى.
 */
class StaffController extends Controller
{
    protected function gate(string $op = 'v'): void
    {
        abort_unless(hub_can(auth()->user(), 'hr', $op), 403, 'هذه الشاشة تحتاج صلاحية ملفات الموظفين');
    }

    public function index()
    {
        $this->gate('v');

        return view('staff', Staff::gaps() + [
            'roles' => Role::when(! hub_is_owner(), fn ($q) => $q->where('is_owner', false))
                ->orderByDesc('is_owner')->orderBy('name')->get(),
            'mayUsers' => hub_flag(auth()->user(), 'users'),
        ]);
    }

    /** ربطُ ملفٍّ بحسابٍ قائم — لا حساب يُقتسَم */
    public function link(Request $r, string $id)
    {
        $this->gate('e');
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);
        $uid = hub_str($r->input('user_id'));

        $u = User::whereNull('deleted_at')->find($uid);
        if (! $u) return back()->withErrors(['user_id' => 'حسابٌ غير معروف']);
        // حارس التصعيد: ربطُ الملف يجعل حالتَه تتحكم بالحساب (الإغلاق الآلي) —
        // فحسابُ مالكٍ أو إداريٍّ لا يُربط إلا بمن يملك المساسَ به
        abort_unless(Staff::mayTouch($u), 403,
            'هذا الحساب ذو امتياز (مالك أو إدارة مستخدمين) — ربطُه بملفٍّ يتطلب صلاحيةً تعلوه');
        if (Staff::accountTaken($u->id, $emp->id)) {
            return back()->withErrors(['user_id' => 'هذا الحساب مربوطٌ بملفٍّ آخر — الحساب لصاحبه']);
        }

        $emp->update(['user_id' => $u->id]);
        hub_audit('ربط ملف وظيفي بحساب', 'hr', $emp->id, $emp->name . ' ← ' . $u->name);

        return back()->with('ok', 'رُبط «' . $emp->name . '» بحساب «' . $u->name . '»');
    }

    /** فكُّ الربط — الخطأ يُصحَّح، ولا يُترك ملفٌّ معلّقاً بحسابٍ ليس صاحبَه */
    public function unlink(string $id)
    {
        $this->gate('e');
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);
        abort_unless($emp->user_id, 422, 'هذا الملف غير مربوط أصلاً');

        hub_audit('فكّ ربط ملف وظيفي', 'hr', $emp->id, $emp->name);
        $emp->update(['user_id' => null]);

        return back()->with('ok', 'فُكّ الربط — الملفُّ بلا حساب الآن');
    }

    /** فتحُ حسابٍ لموظفٍ بلا حساب — بدورٍ مختار وكلمةِ مرورٍ مؤقتة تُعرض مرة */
    public function account(Request $r, string $id)
    {
        $this->gate('e');
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);
        abort_if($emp->user_id, 422, 'لهذا الملف حسابٌ مربوط');

        if (blank($emp->email)) {
            return back()->withErrors(['email' => 'لا بريد في الملف — البريد هو هوية الحساب']);
        }

        // **المآلُ يُقرأ لا يُخمَّن**: كان `null` يُترجَم دائماً «وُجد حسابٌ فرُبط»
        // — وهي كذبةٌ حين يكون الحساب مربوطاً بملفٍّ آخر، فلا رَبطَ وقع والملفُّ
        // باقٍ بلا حساب، وصاحبُ النظام مطمئنٌّ إلى ربطٍ لم يحدث.
        $res = Staff::makeAccountResult($emp, hub_str($r->input('role_id')));

        return match ($res['outcome']) {
            'created' => back()->with('ok', 'أُنشئ حساب «' . $emp->name . '»')
                ->with('temp_password', $res['temp'])->with('temp_password_for', (string) $emp->email),
            'linked'  => back()->with('ok', 'وُجد حسابٌ بهذا البريد فرُبط بالملف'),
            'taken'   => back()->withErrors(['email' => 'لهذا البريد حسابٌ مرتبطٌ بملفٍّ وظيفيٍّ آخر،'
                . ' ولا يُقتسَم حساب — استعمل بريداً آخر أو فُكّ ارتباط الملف الأول']),
            default   => back()->withErrors(['email' => 'لا بريد في الملف — البريد هو هوية الحساب']),
        };
    }

    /** ملفٌّ وظيفيّ لحسابٍ بلا ملف */
    public function file(string $userId)
    {
        $this->gate('a');
        $u = User::whereNull('deleted_at')->findOrFail($userId);
        $emp = Staff::makeFile($u);

        return $emp
            ? back()->with('ok', 'أُنشئ ملفُّ «' . $u->name . '» الوظيفي')
            : back()->with('ok', 'لهذا الحساب ملفٌّ مربوط أصلاً');
    }

    /** توحيدُ الاسم والبريد: الملفُّ هو المرجع — بياناتُ الموظف تُدار من ملفّه */
    public function align(string $id)
    {
        $this->gate('e');
        $emp = hub_scope(Employee::query(), 'hr')->findOrFail($id);
        $u = $emp->user_id ? User::find($emp->user_id) : null;
        abort_unless($u, 422, 'لا حساب مربوط بهذا الملف');
        // حارس التصعيد نفسُه الذي في link(): التوحيد يكتب اسم/بريد الملف على الحساب —
        // فحسابُ مالكٍ أو إداريٍّ لا تُدهس هويتُه إلا بمن يملك المساسَ به
        abort_unless(Staff::mayTouch($u), 403,
            'هذا الحساب ذو امتياز (مالك أو إدارة مستخدمين) — توحيدُ بياناته يتطلب صلاحيةً تعلوه');

        // **قصٌّ لعرض عمود الحساب** (v2.325): أعمدةُ الملف الوظيفيّ أوسعُ من
        // أعمدة الحساب (`users.name` = 160)، فالتوحيدُ كان يدهسها بقيمةٍ أطول —
        // 1406 على MySQL الصارمة، وقصٌّ صامت على SQLite.
        $patch = ['name' => hub_fit((string) $emp->name, hub_col_max('users', 'name') ?? 160)];
        // البريدُ لا يُنقل إن كان مأخوذاً بحسابٍ آخر — والتوحيد لا يكسر الدخول
        if (filled($emp->email) && ! User::whereNull('deleted_at')
            ->where('email', $emp->email)->where('id', '!=', $u->id)->exists()) {
            $patch['email'] = hub_fit((string) $emp->email, hub_col_max('users', 'email') ?? 190);
        }
        $u->update($patch);
        hub_audit('توحيد بيانات الحساب مع الملف', 'users', $u->id, $emp->name);

        return back()->with('ok', 'وُحّدت بيانات الحساب مع الملف الوظيفي');
    }
}
