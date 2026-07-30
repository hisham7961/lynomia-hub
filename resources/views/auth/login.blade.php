<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var t=localStorage.getItem('lyn_theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark'})()</script>
<title>تسجيل الدخول — {{ setting('app.name', config('app.name')) }}</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="loginbg">
<div class="logincard">
    <h1>{{ setting('app.name', 'Lynomia Business Hub') }}</h1>
    <p class="sub">نظام إدارة الأعمال الموحّد</p>
    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <label>البريد الإلكتروني</label>
        <input class="inp" type="email" name="email" value="{{ old('email') }}" required autofocus>
        <label>كلمة المرور</label>
        <input class="inp" type="password" name="password" required>
        <button class="btn p full" type="submit">دخول</button>
    </form>
</div>
</body>
</html>
