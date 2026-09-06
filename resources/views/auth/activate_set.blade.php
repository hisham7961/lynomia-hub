<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>وضع كلمة السرّ — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body class="loginbg">
<div class="logincard">
    @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:52px;border-radius:10px;margin-bottom:8px">
    @else<div class="loginmark">🔐</div>@endif
    <h1>ضع كلمةَ سرّك</h1>
    <p class="sub">اختر كلمةَ سرٍّ قويّةً لحسابك — أنت وحدَك من يعرفها</p>

    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

    <form method="POST" action="{{ route('activate.set', $token) }}">
        @csrf
        <label>كلمة السرّ الجديدة</label>
        <input class="inp" type="password" name="password" autocomplete="new-password" required autofocus>
        <label>تأكيد كلمة السرّ</label>
        <input class="inp" type="password" name="password_confirmation" autocomplete="new-password" required>
        <p class="sub" style="margin:6px 0 10px">لا تقلّ عن {{ (int) setting('auth.pw_min', 10) }} خانات، وتحوي أحرفاً وأرقاماً وحالةً مختلطة.</p>
        <button class="btn p full" type="submit">تفعيل الحساب والدخول</button>
    </form>
</div>
</body>
</html>
