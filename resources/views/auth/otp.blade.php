<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>رمز التحقق — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
</head>
<body class="loginbg">
<div class="logincard">
    <h1>🔐 رمز التحقق</h1>
    <p class="sub">أدخل الرمز من تطبيق المصادقة في جوالك</p>
    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.otp.verify') }}">
        @csrf
        <label>الرمز المكوّن من ٦ أرقام</label>
        <input class="inp ltr" style="text-align:center;letter-spacing:8px;font-size:22px" type="text" name="code"
               inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus>
        <button class="btn p full" type="submit">تحقق ودخول</button>
    </form>
    <p class="sub" style="margin-top:12px;text-align:center"><a href="{{ route('login') }}">→ عودة لتسجيل الدخول</a></p>
</div>
</body>
</html>
