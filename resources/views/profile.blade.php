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

</div>
@endsection
