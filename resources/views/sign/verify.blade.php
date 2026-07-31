<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>التحقق من مستند</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('hub.version') }}"></head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg);padding:16px">
<div class="card" style="max-width:460px;width:94%;padding:28px 24px">
    <div style="text-align:center;margin-bottom:14px">
        <div style="font-size:38px">🔎</div>
        <h2>التحقق من مستند</h2>
        <div class="sub">أدخل رمز التحقق المطبوع على الوثيقة (مثل LYN-XXXX-XXXX)</div>
    </div>
    <form method="POST" action="{{ route('sign.verify') }}" style="display:flex;gap:8px">
        @csrf
        <input class="inp ltr" name="code" value="{{ $code }}" placeholder="LYN-XXXX-XXXX" style="flex:1;text-align:center;letter-spacing:1px">
        <button class="btn p">تحقق</button>
    </form>

    @if ($throttled)
        <div class="sub" style="color:var(--bad);margin-top:12px;text-align:center">محاولات كثيرة — انتظر دقيقة</div>
    @elseif ($code !== '')
        @if ($found)
            <div style="margin-top:16px;padding:14px;border-radius:12px;background:color-mix(in srgb,var(--ok) 8%,transparent);border:1px solid color-mix(in srgb,var(--ok) 30%,transparent)">
                <b style="color:var(--ok)">✅ مستند أصلي مسجّل لدينا</b>
                <table class="mini" style="margin-top:8px">
                    <tr><td>العنوان</td><td><b>{{ $found->title }}</b></td></tr>
                    <tr><td>الحالة</td><td><span class="bdg {{ $found->status === 'وُقّع' ? 'ok' : ($found->status === 'رُفض' ? 'bad' : 'wn') }}">{{ $found->status }}</span></td></tr>
                    @if ($found->signed_at)
                        <tr><td>الموقّع</td><td>{{ $found->signer_name }}</td></tr>
                        <tr><td>وقت التوقيع</td><td class="mono">{{ $found->signed_at->format('Y-m-d H:i') }}</td></tr>
                    @endif
                    <tr><td>بصمة الوثيقة</td><td class="mono ltr" style="font-size:10px;word-break:break-all">{{ $found->doc_hash }}</td></tr>
                    @if ($found->evidence_hash)
                        <tr><td>رأس سلسلة الأدلة</td><td class="mono ltr" style="font-size:10px;word-break:break-all">{{ $found->evidence_hash }}</td></tr>
                    @endif
                </table>
                @if (($signers ?? collect())->count() > 1 || (($signers ?? collect())->count() === 1 && ! $found->signer_name))
                    <table class="mini" style="margin-top:8px">
                        @foreach ($signers as $s)
                            <tr><td>وقّع</td><td><b>{{ $s->name }}</b> <span class="mono sub">{{ $s->signed_at?->format('Y-m-d H:i') }}</span></td></tr>
                        @endforeach
                    </table>
                @endif
                @if ($qr ?? null)
                    <div style="text-align:center;margin-top:10px">
                        <div style="background:#fff;padding:6px;border-radius:10px;border:1px solid var(--ln);display:inline-block">{!! $qr !!}</div>
                        <div class="sub" style="font-size:10px;margin-top:2px">رمز تحققٍ سريع — اطبعه مع نسختك</div>
                    </div>
                @endif
                <div class="sub" style="margin-top:6px">قارن البصمة مع المطبوعة على نسختك — تطابقها يثبت أن النص لم يُمسّ.</div>
            </div>
        @else
            <div class="sub" style="color:var(--bad);margin-top:12px;text-align:center">❌ لا مستند بهذا الرمز — تحقق من كتابته، أو فالوثيقة ليست صادرة عنا</div>
        @endif
    @endif
</div>
</body>
</html>
