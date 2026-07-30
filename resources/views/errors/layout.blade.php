<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<script>(function(){var t=localStorage.getItem('lyn_theme');if(t==='dark'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.dataset.theme='dark'})()</script>
<title>@yield('code') — {{ rescue(fn () => setting('app.name', config('app.name')), config('app.name'), false) }}</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="loginbg">
<div class="logincard" style="text-align:center">
    <div style="font-size:56px">@yield('icon')</div>
    <div class="mono ltr sub" style="font-size:13px;letter-spacing:3px">@yield('code')</div>
    <h1 style="margin:4px 0 8px">@yield('title')</h1>
    <p class="sub" style="margin-bottom:18px">@hasSection('msg')@yield('msg')@else{{ $exception?->getMessage() ?: '' }}@endif</p>
    <div style="display:flex;gap:8px;justify-content:center">
        <a class="btn p" href="{{ url('/') }}">→ لوحة التحكم</a>
        <a class="btn ghost" href="javascript:history.back()">رجوع</a>
    </div>
</div>
</body>
</html>
