<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تم تسجيل الرفض</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}"></head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
<div class="card" style="max-width:400px;width:92%;text-align:center;padding:30px 24px">
    <div style="font-size:40px;margin-bottom:8px">🚫</div>
    <h2>تم تسجيل رفضك</h2>
    <p class="sub" style="margin-top:8px">سُجّل رفض توقيع «{{ $req->title }}» وأُبلغت الجهة المُرسلة بالسبب.<br>يمكنك إغلاق الصفحة.</p>
</div>
</body>
</html>
