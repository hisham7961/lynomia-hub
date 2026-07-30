{{-- الوثيقة النهائية (A4 للطباعة/حفظ PDF) — النص + التوقيع + شهادة الأثر --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
<style>@media print{.noprint{display:none!important}body{background:#fff!important}}</style>
</head>
<body style="background:var(--bg);padding:24px 12px">
<div style="max-width:800px;margin:0 auto">
    <div class="noprint" style="display:flex;gap:8px;margin-bottom:12px">
        <a class="btn ghost sm" href="{{ route('esign.index') }}">→ رجوع</a>
        <button class="btn sm" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
    </div>
    <div class="card">
        <h2>{{ $req->title }}</h2>
        <div style="white-space:pre-wrap;line-height:2;margin-top:14px;font-size:14.5px">{{ $req->body }}</div>

        @if ($req->status === 'وُقّع')
            <div style="margin-top:26px;border-top:2px solid var(--ln);padding-top:16px">
                <b>توقيع الطرف الثاني:</b><br>
                <img src="{{ $req->signature }}" alt="التوقيع" style="max-width:320px;border:1px solid var(--ln);border-radius:10px;background:#fff;margin:8px 0">
                <table class="mini" style="margin-top:8px">
                    <tr><td>الاسم</td><td><b>{{ $req->signer_name }}</b></td></tr>
                    <tr><td>وقت التوقيع</td><td class="mono">{{ $req->signed_at?->format('Y-m-d H:i:s') }}</td></tr>
                    <tr><td>عنوان الشبكة IP</td><td class="mono ltr">{{ $req->signed_ip }}</td></tr>
                    <tr><td>الجهاز</td><td class="mono ltr" style="font-size:10.5px">{{ $req->signed_agent }}</td></tr>
                    <tr><td>لغة الجهاز</td><td class="mono ltr">{{ $req->signed_locale }}</td></tr>
                    <tr><td>مرات الفتح</td><td class="mono">{{ $req->opens }} — أول فتح {{ $req->opened_at?->format('Y-m-d H:i') }}</td></tr>
                </table>
            </div>
        @else
            <div class="sub" style="margin-top:20px">⏳ لم تُوقَّع بعد — {{ $req->opens }} فتحة للرابط حتى الآن.</div>
        @endif
    </div>
</div>
</body>
</html>
