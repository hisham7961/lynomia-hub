<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تم التوقيع</title>
@include('partials.standalone_head')
</head>
<body class="pagecenter">
<div class="card" style="max-width:440px;width:92%;text-align:center;padding:32px 24px">
    <div style="font-size:46px;margin-bottom:10px">✅</div>
    <h2>تم التوقيع بنجاح</h2>
    <p class="sub" style="margin-top:8px">وُقّعت «{{ $req->title }}» بواسطة <b>{{ $req->signer_name }}</b><br>
        في {{ $req->signed_at?->format('Y-m-d H:i') }} — وأُبلغ الطرف الأول تلقائياً.</p>
    <div style="margin:12px 0;padding:10px;border-radius:12px;background:var(--bg2)">
        <div class="sub">رمز التحقق من الوثيقة</div>
        <b class="mono ltr" style="font-size:17px;letter-spacing:1px">{{ $req->verify_code }}</b>
        <div class="sub" style="font-size:11px">احتفظ به — تتحقق به من أصالة نسختك في أي وقت عبر {{ route('sign.verify') }}</div>
    </div>
    <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <a class="btn p" href="{{ route('sign.doc', $token ?? $req->token) }}">📄 نسختك النهائية (حفظ PDF)</a>
    </div>
</div>
</body>
</html>
