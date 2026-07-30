<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تم التوقيع</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
<div class="card" style="max-width:420px;width:92%;text-align:center;padding:32px 24px">
    <div style="font-size:46px;margin-bottom:10px">✅</div>
    <h2>تم التوقيع بنجاح</h2>
    <p class="sub" style="margin-top:8px">وُقّعت «{{ $req->title }}» بواسطة <b>{{ $req->signer_name }}</b><br>
        في {{ $req->signed_at?->format('Y-m-d H:i') }} — وأُبلغ الطرف الأول تلقائياً.<br>
        يمكنك إغلاق هذه الصفحة، أو طباعة نسختك الآن.</p>
    <button class="btn" onclick="window.print()" style="margin-top:12px">🖨️ طباعة / حفظ PDF</button>
</div>
</body>
</html>
