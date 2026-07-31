<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }} — بانتظار الدور</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg)">
<div class="card" style="max-width:420px;width:92%;text-align:center;padding:28px 24px">
    <div style="font-size:38px;margin-bottom:8px">⏳</div>
    <h2 style="margin-bottom:6px">{{ $req->title }}</h2>
    <div class="sub" style="margin-bottom:10px">مرحباً {{ $signer->name }} — التوقيع على هذه الوثيقة <b>متسلسل</b>،
        ودورك يأتي بعد توقيع «{{ $before->name }}».</div>
    <div class="sub">سنراسلك على بريدك فور حلول دورك — أو عد لهذا الرابط لاحقاً.</div>
</div>
</body>
</html>
