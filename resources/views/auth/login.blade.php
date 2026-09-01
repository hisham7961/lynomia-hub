<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>تسجيل الدخول — {{ setting('app.name', config('app.name')) }}</title>
@include('partials.standalone_head')
@if ($brand = hub_brand_css())<style>{!! $brand !!}</style>@endif
</head>
<body class="loginbg">
<div class="logincard">
    @if ($logo = setting('app.logo'))<img src="{{ asset('storage/' . $logo) }}" alt="" style="height:52px;border-radius:10px;margin-bottom:8px">
    @else<div class="loginmark">🏢</div>@endif
    <h1>{{ setting('app.name', 'Lynomia Business Hub') }}</h1>
    <p class="sub">نظام إدارة الأعمال الموحّد</p>
    @if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('login.attempt') }}">
        @csrf
        <label>البريد الإلكتروني</label>
        <input class="inp" type="email" name="email" value="{{ old('email') }}" required autofocus>
        <label>كلمة المرور</label>
        <input class="inp" type="password" name="password" required>
        <button class="btn p full" type="submit">دخول</button>
    </form>
    @if ((string) setting('auth.passkeys_on', '1') === '1')
        <div class="sub" style="margin:12px 0 6px">أو</div>
        <button class="btn full" type="button" id="pk-login">🔑 الدخول بمفتاح المرور</button>
        <div class="flash bad" id="pk-err" style="display:none;margin-top:8px"></div>
    @endif
</div>
@if ((string) setting('auth.passkeys_on', '1') === '1')
@include('partials.passkey_js')
<script>
(function () {
    var btn = document.getElementById('pk-login'); if (!btn) return;
    if (!window.LynPasskey || !LynPasskey.supported) { btn.style.display = 'none'; return; }
    var err = document.getElementById('pk-err');
    btn.addEventListener('click', async function () {
        err.style.display = 'none'; btn.disabled = true; btn.textContent = '… بانتظار جهازك';
        try {
            var res = await LynPasskey.assert('{{ route('passkey.login.options') }}', '{{ route('passkey.login.verify') }}');
            if (res.ok && res.data.ok) { location.href = res.data.redirect || '/'; return; }
            err.textContent = res.data.error || 'تعذّر الدخول بمفتاح المرور'; err.style.display = 'block';
        } catch (e) { err.textContent = 'أُلغيت العملية أو تعذّرت'; err.style.display = 'block'; }
        btn.disabled = false; btn.textContent = '🔑 الدخول بمفتاح المرور';
    });
})();
</script>
@endif
</body>
</html>
