@extends('layouts.app')
@section('title', 'تأكيد الهوية')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الأمان</span><span aria-hidden="true">‹</span><b>تأكيد الهوية</b></nav>
        <h2>🔐 أكّد هويتك للمتابعة</h2>
        <div class="sub">هذا الفعلُ حسّاسٌ — أعد التحقّق مرةً، ثم يعمل التأكيدُ {{ $minutes }} دقائق</div>
    </div>
</div>

<div class="card" style="max-width:460px">
    <form method="POST" action="{{ route('stepup.verify') }}">
        @csrf
        <input type="hidden" name="next" value="{{ $next }}">
        <div class="fld">
            <label for="answer">{{ $method === 'totp' ? 'رمز التطبيق (٦ أرقام)' : 'كلمة المرور الحالية' }}</label>
            @if ($method === 'totp')
                <input class="inp mono ltr" id="answer" name="answer" inputmode="numeric" autocomplete="one-time-code"
                       maxlength="6" placeholder="123456" autofocus>
                <span class="sub fhint">من تطبيق المصادقة على هاتفك</span>
            @else
                <input class="inp" id="answer" name="answer" type="password" autocomplete="current-password" autofocus>
                <span class="sub fhint">كلمة مرور دخولك نفسها</span>
            @endif
        </div>
        <button class="btn p" style="margin-top:10px">تأكيد ومتابعة</button>
        <a class="btn ghost" href="{{ $next }}" style="margin-top:10px">إلغاء</a>
    </form>
</div>
@endsection
