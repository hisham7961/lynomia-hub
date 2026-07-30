<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — توقيع</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
<div class="card" style="max-width:380px;width:92%;text-align:center;padding:28px 24px">
    <div style="font-size:38px;margin-bottom:8px">✍️</div>
    <h2 style="margin-bottom:4px">{{ $title }}</h2>
    <div class="sub" style="margin-bottom:16px">وثيقة محمية — أدخل كلمة السر التي وصلتك</div>
    @if (session('err'))<div class="sub" style="color:var(--bad);font-weight:700;margin-bottom:10px">{{ session('err') }}</div>@endif
    <form method="POST" action="{{ route('sign.unlock', $token) }}">
        @csrf
        <input class="inp" type="password" name="pass" placeholder="كلمة السر" autofocus style="text-align:center;margin-bottom:12px">
        <button class="btn" style="width:100%">فتح الوثيقة</button>
    </form>
</div>
</body>
</html>
