{{-- الوثيقة النهائية (A4 للطباعة/حفظ PDF) — النص + التوقيع + شهادة الأثر الكاملة.
     تُعرض داخلياً (esign.doc) وللعميل عبر رابطه المفتوح ($client) --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $req->title }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}">
<style>@media print{.noprint{display:none!important}body{background:#fff!important}.card{box-shadow:none!important;border:0!important}}</style>
</head>
<body style="background:var(--bg);padding:24px 12px">
<div style="max-width:800px;margin:0 auto">
    <div class="noprint" style="display:flex;gap:8px;margin-bottom:12px">
        @unless ($client ?? false)<a class="btn ghost sm" href="{{ route('esign.index') }}">→ رجوع</a>@endunless
        <button class="btn sm" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
    </div>
    <div class="card">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;border-bottom:2px solid var(--ln);padding-bottom:10px;margin-bottom:14px">
            <h2>{{ $req->title }}</h2>
            <div style="text-align:left">
                <div class="sub">رمز التحقق</div>
                <b class="mono ltr">{{ $req->verify_code }}</b>
            </div>
        </div>
        <div style="white-space:pre-wrap;line-height:2;font-size:14.5px">{{ $req->body }}</div>

        @if ($req->status === 'وُقّع')
            <div style="margin-top:26px;border-top:2px solid var(--ln);padding-top:16px">
                <b>توقيع الطرف الثاني:</b><br>
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start;margin:8px 0">
                    <img src="{{ $req->signature }}" alt="التوقيع" style="max-width:300px;border:1px solid var(--ln);border-radius:10px;background:#fff">
                    @if ($req->selfie)
                        <img src="{{ $req->selfie }}" alt="صورة التحقق" style="max-width:140px;border:1px solid var(--ln);border-radius:10px">
                    @endif
                </div>
                <table class="mini">
                    <tr><td>الاسم</td><td><b>{{ $req->signer_name }}</b></td></tr>
                    @if ($req->signer_id_no)<tr><td>رقم الهوية/السجل</td><td class="mono ltr">{{ $req->signer_id_no }}</td></tr>@endif
                    <tr><td>وقت التوقيع</td><td class="mono">{{ $req->signed_at?->format('Y-m-d H:i:s') }}</td></tr>
                    <tr><td>عنوان الشبكة IP</td><td class="mono ltr">{{ $req->signed_ip }}</td></tr>
                    <tr><td>الجهاز</td><td class="mono ltr" style="font-size:10.5px">{{ $req->signed_agent }}</td></tr>
                    <tr><td>لغة الجهاز</td><td class="mono ltr">{{ $req->signed_locale }}</td></tr>
                    <tr><td>مرات الفتح</td><td class="mono">{{ $req->opens }} — أول فتح {{ $req->opened_at?->format('Y-m-d H:i') }}</td></tr>
                    <tr><td>بصمة الوثيقة SHA-256</td><td class="mono ltr" style="font-size:9.5px;word-break:break-all">{{ $req->doc_hash }}</td></tr>
                </table>
                <div class="sub" style="margin-top:8px">للتحقق من أصالة هذه الوثيقة: {{ route('sign.verify') }} — أدخل الرمز {{ $req->verify_code }}</div>
            </div>
        @elseif ($req->status === 'رُفض')
            <div class="sub" style="margin-top:20px;color:var(--bad)">🚫 رُفض التوقيع — السبب: {{ $req->declined_reason }}</div>
        @else
            <div class="sub" style="margin-top:20px">⏳ لم تُوقَّع بعد — {{ $req->opens }} فتحة للرابط حتى الآن.</div>
        @endif
    </div>
</div>
</body>
</html>
