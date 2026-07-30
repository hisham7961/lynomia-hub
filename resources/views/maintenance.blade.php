<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>صيانة — {{ setting('app.name', config('app.name')) }}</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="loginbg">
<div class="logincard" style="text-align:center">
    <div style="font-size:52px">🚧</div>
    <h1>النظام تحت الصيانة</h1>
    <p class="sub">{{ $msg ?: 'نعود إليكم قريباً — شكراً لصبركم' }}</p>
</div>
</body>
</html>
