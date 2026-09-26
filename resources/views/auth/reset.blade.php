<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>كلمة مرور جديدة — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body class="loginbg">
<div class="logincard">
    @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:52px;border-radius:10px;margin-bottom:8px">
    @else<div class="loginmark">🔐</div>@endif
    <h1>ضع كلمة مرورٍ جديدة</h1>
    <p class="sub">بعد الحفظ تُنهى جلساتُك القائمة على كل الأجهزة، وتدخل بكلمتك الجديدة</p>

    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label>البريد الإلكتروني</label>
        <input class="inp" type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" required>
        <label>كلمة المرور الجديدة</label>
        <input class="inp" type="password" name="password" autocomplete="new-password" required autofocus>
        <label>تأكيد كلمة المرور</label>
        <input class="inp" type="password" name="password_confirmation" autocomplete="new-password" required>
        <p class="sub" style="margin:6px 0 10px">لا تقلّ عن {{ (int) setting('auth.pw_min', 10) }} خانات، وتحوي أحرفاً وأرقاماً وحالةً مختلطة، ولا تكون إحدى كلماتك السابقة.</p>
        <button class="btn p full" type="submit">حفظ كلمة المرور</button>
    </form>
    <p class="sub" style="margin-top:12px"><a href="{{ route('login') }}">العودة إلى تسجيل الدخول</a></p>
</div>
</body>
</html>
