<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** الملف الشخصي: بيانات المستخدم نفسه + تغيير كلمة مروره بنفسه */
class ProfileController extends Controller
{
    public function edit()
    {
        return view('profile', ['u' => auth()->user()]);
    }

    public function update(Request $r)
    {
        $d = $r->validate([
            'name'      => ['required', 'string', 'max:160'],
            'phone'     => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:160'],
        ]);

        auth()->user()->fill($d)->save();

        return redirect()->route('profile.edit')->with('ok', 'حُفظت بياناتك');
    }

    public function password(Request $r)
    {
        $r->validate([
            'current'  => ['required', 'current_password'],
            'password' => ['required', 'confirmed', password_rules()],
        ], [
            'current.current_password' => 'كلمة المرور الحالية غير صحيحة',
            'password.confirmed'       => 'تأكيد كلمة المرور غير مطابق',
        ], [
            'current'  => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $u = auth()->user();
        $u->password = $r->input('password');            // cast hashed يتكفّل بالتجزئة
        $u->password_changed_at = now();
        $u->save();

        // تدوير معرّف الجلسة بعد تغيير كلمة المرور
        $r->session()->regenerate();

        return redirect()->route('profile.edit')->with('ok', 'غُيّرت كلمة المرور بنجاح');
    }
}
