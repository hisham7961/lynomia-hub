<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>مستند محمي — {{ setting('app.name', config('app.name')) }}</title>
<link href="{{ asset('css/fonts.css') }}?v={{ config('hub.version') }}" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
</head>
<body class="loginbg">
<div class="logincard">
    <div style="font-size:44px;text-align:center">🔑</div>
    <h1>{{ $title }}</h1>
    <p class="sub">هذا المستند محمي بكلمة مرور زوّدك بها المرسل</p>
    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('share.unlock', $token) }}">
        @csrf
        <label>كلمة المرور</label>
        <input class="inp ltr" type="password" name="password" required autofocus>
        <button class="btn p full" type="submit">فتح المستند</button>
    </form>
</div>
</body>
</html>
