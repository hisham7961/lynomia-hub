<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>استعادة كلمة المرور — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body class="loginbg">
<div class="logincard">
    @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:52px;border-radius:10px;margin-bottom:8px">
    @else<div class="loginmark">🔑</div>@endif
    <h1>نسيت كلمة المرور؟</h1>
    <p class="sub">اكتب بريدك المسجَّل وسنرسل إليه رابطاً لوضع كلمةٍ جديدة</p>

    @if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf
        <label>البريد الإلكتروني</label>
        <input class="inp" type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        <button class="btn p full" type="submit">أرسل رابط الاستعادة</button>
    </form>
    <p class="sub" style="margin-top:12px"><a href="{{ route('login') }}">العودة إلى تسجيل الدخول</a></p>
</div>
</body>
</html>
