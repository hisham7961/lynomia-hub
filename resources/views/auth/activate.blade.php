<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تفعيل الحساب — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body class="loginbg">
<div class="logincard">
    @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:52px;border-radius:10px;margin-bottom:8px">
    @else<div class="loginmark">🔑</div>@endif
    <h1>تفعيل الحساب</h1>
    <p class="sub">أدخِل رمزَ التحقّق الذي وصلك في البريد</p>

    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

    @if ($expired)
        <div class="flash bad">انتهت صلاحيةُ رابط التفعيل. اطلب من مسؤول حسابك إرسالَ رابطٍ جديد.</div>
    @else
        <form method="POST" action="{{ route('activate.otp', $token) }}">
            @csrf
            <label>رمز التحقّق (٦ أرقام)</label>
            <input class="inp" type="text" name="otp" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9]*" maxlength="6" required autofocus>
            <button class="btn p full" type="submit">متابعة</button>
        </form>
        <p class="sub" style="margin-top:12px">لم يصلك الرمز؟ راجع مسؤولَ حسابك لإعادة الإرسال.</p>
    @endif
</div>
</body>
</html>
