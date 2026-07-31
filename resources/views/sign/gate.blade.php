<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title }} — توقيع</title>
@include('partials.standalone_head')
</head>
<body class="pagecenter">
<div class="card" style="max-width:380px;width:92%;text-align:center;padding:28px 24px">
    <div style="font-size:38px;margin-bottom:8px">✍️</div>
    <h2 style="margin-bottom:4px">{{ $title }}</h2>
    @if (($signer ?? null))
        {{-- موقّع مستقل (v2.120): بوابته رمز تحقق بريدي يُستهلك مرة — لا كلمة سر مشتركة --}}
        <div class="sub" style="margin-bottom:6px">مرحباً {{ $signer->name }} 👋</div>
        <div class="sub" style="margin-bottom:16px">وثيقة محمية — اطلب رمز التحقق على بريدك ثم أدخله</div>
        @if (session('err'))<div class="sub" style="color:var(--bad);font-weight:700;margin-bottom:10px">{{ session('err') }}</div>@endif
        @if (session('ok'))<div class="sub" style="color:var(--ok);font-weight:700;margin-bottom:10px">{{ session('ok') }}</div>@endif
        <form method="POST" action="{{ route('sign.otp', $signer->token) }}" style="margin-bottom:12px">
            @csrf
            <button class="btn ghost full">📧 أرسل رمز التحقق إلى {{ Str::mask($signer->email ?? '', '•', 2, max(1, mb_strlen((string) $signer->email) - 6)) }}</button>
        </form>
        <form method="POST" action="{{ route('sign.unlock', $signer->token) }}">
            @csrf
            <input class="inp ltr" type="text" inputmode="numeric" name="otp" maxlength="6" placeholder="——————"
                   autocomplete="one-time-code" style="text-align:center;letter-spacing:8px;font-size:20px;margin-bottom:12px">
            <button class="btn p full">فتح الوثيقة</button>
        </form>
    @else
        <div class="sub" style="margin-bottom:16px">وثيقة محمية — أدخل كلمة السر التي وصلتك</div>
        @if (session('err'))<div class="sub" style="color:var(--bad);font-weight:700;margin-bottom:10px">{{ session('err') }}</div>@endif
        <form method="POST" action="{{ route('sign.unlock', $token) }}">
            @csrf
            <input class="inp" type="password" name="pass" placeholder="كلمة السر" autofocus style="text-align:center;margin-bottom:12px">
            <button class="btn p full">فتح الوثيقة</button>
        </form>
    @endif
</div>
</body>
</html>
