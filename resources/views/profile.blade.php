@extends('layouts.app')
@section('title', 'ملفي الشخصي')
@section('content')

<div class="hero">
    <div>
        <h2>👤 ملفي الشخصي</h2>
        <div class="sub">
            {{ $u->role?->name ?? 'بلا دور' }}
            @if ($u->last_login_at) · آخر دخول {{ $u->last_login_at->format('Y-m-d H:i') }} @endif
        </div>
    </div>
</div>

<div class="kids">

    <div class="card kid">
        <h3>البيانات الشخصية</h3>
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf @method('PUT')
            <div class="fg">
                <div class="fld fw">
                    <label>الاسم <span class="req">*</span></label>
                    <input class="inp @error('name') err @enderror" name="name" value="{{ old('name', $u->name) }}" required>
                    @error('name')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld fw">
                    <label>البريد الإلكتروني</label>
                    <input class="inp ltr" value="{{ $u->email }}" disabled>
                    <span class="sub">تغيير البريد من إدارة المستخدمين فقط</span>
                </div>
                <div class="fld">
                    <label>الهاتف</label>
                    <input class="inp ltr @error('phone') err @enderror" name="phone" value="{{ old('phone', $u->phone) }}">
                    @error('phone')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld">
                    <label>المسمى الوظيفي</label>
                    <input class="inp @error('job_title') err @enderror" name="job_title" value="{{ old('job_title', $u->job_title) }}">
                    @error('job_title')<span class="ferr">{{ $message }}</span>@enderror
                </div>
            </div>
            <div class="formfoot">
                <button class="btn p" type="submit">حفظ البيانات</button>
            </div>
        </form>
    </div>

    <div class="card kid">
        <h3>🔒 تغيير كلمة المرور</h3>
        <form method="POST" action="{{ route('profile.password') }}">
            @csrf @method('PUT')
            <div class="fg">
                <div class="fld fw">
                    <label>كلمة المرور الحالية <span class="req">*</span></label>
                    <input class="inp ltr @error('current') err @enderror" type="password" name="current" autocomplete="current-password" required>
                    @error('current')<span class="ferr">{{ $message }}</span>@enderror
                </div>
                <div class="fld fw">
                    <label>كلمة المرور الجديدة <span class="req">*</span></label>
                    <input class="inp ltr @error('password') err @enderror" type="password" name="password" autocomplete="new-password" required>
                    @error('password')<span class="ferr">{{ $message }}</span>@enderror
                    <span class="sub">{{ (int) setting('auth.pw_min', 10) }} خانات على الأقل، بأحرف كبيرة وصغيرة وأرقام</span>
                </div>
                <div class="fld fw">
                    <label>تأكيد كلمة المرور الجديدة <span class="req">*</span></label>
                    <input class="inp ltr" type="password" name="password_confirmation" autocomplete="new-password" required>
                </div>
            </div>
            <div class="formfoot">
                <button class="btn p" type="submit">تغيير كلمة المرور</button>
            </div>
        </form>
    </div>

    <div class="card kid">
        <h3>🛡️ المصادقة الثنائية (2FA)</h3>
        @if ($u->totp_enabled)
            <p class="sub">✅ مفعّلة — يُطلب رمز من تطبيق المصادقة عند كل دخول.</p>
            <form method="POST" action="{{ route('profile.2fa.disable') }}" style="margin-top:8px">
                @csrf
                <div class="crow">
                    <input class="inp ltr @error('code') err @enderror" name="code" placeholder="رمز التطبيق الحالي" maxlength="6" inputmode="numeric" style="max-width:170px" required>
                    <button class="btn ghost sm" type="submit" onclick="return confirm('تعطيل المصادقة الثنائية؟')">تعطيل</button>
                </div>
                @error('code')<span class="ferr">{{ $message }}</span>@enderror
            </form>
        @elseif ($pending2fa)
            <p class="sub">١) افتح تطبيق المصادقة (Google Authenticator أو مشابه) ← إضافة حساب ← <b>إدخال مفتاح يدوياً</b>:</p>
            <div class="mono ltr" style="background:var(--pss);border-radius:10px;padding:10px;margin:8px 0;font-size:15px;letter-spacing:2px;text-align:center;word-break:break-all">{{ $pending2fa }}</div>
            <details class="sub"><summary style="cursor:pointer">أو انسخ رابط otpauth</summary>
                <div class="mono ltr" style="font-size:11px;word-break:break-all;margin-top:4px">{{ $otpUri }}</div>
            </details>
            <form method="POST" action="{{ route('profile.2fa.confirm') }}" style="margin-top:10px">
                @csrf
                <p class="sub">٢) أدخل الرمز الظاهر في التطبيق للتأكيد:</p>
                <div class="crow">
                    <input class="inp ltr @error('code') err @enderror" name="code" placeholder="——————" maxlength="6" inputmode="numeric" style="max-width:170px;text-align:center;letter-spacing:6px" required autofocus>
                    <button class="btn p sm" type="submit">تأكيد التفعيل</button>
                </div>
                @error('code')<span class="ferr">{{ $message }}</span>@enderror
            </form>
        @else
            <p class="sub">طبقة حماية إضافية: بعد كلمة المرور يُطلب رمز متغير من تطبيق مصادقة في جوالك.</p>
            <form method="POST" action="{{ route('profile.2fa.start') }}" style="margin-top:10px">
                @csrf<button class="btn p sm" type="submit">🛡️ تفعيل المصادقة الثنائية</button>
            </form>
        @endif
    </div>

</div>
@endsection
