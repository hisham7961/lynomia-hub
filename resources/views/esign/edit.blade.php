@extends('layouts.app')
@section('title', 'تحرير العقد')
@section('content')
<div class="modhero" style="--mh:{{ hub_mod_look('contracts')['color'] }}">
    <span class="mhico">📝</span>
    <div><div class="sub">تحرير نص العقد · رمز التحقق <b class="mono ltr">{{ $req->verify_code }}</b></div>
        <h2>{{ $req->title }}</h2></div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('esign.index') }}">→ المركز</a>
</div>

@if (session('sign_link'))
    <div class="card" style="border-color:var(--p)">
        <h3>🔗 الرابط الخاص</h3>
        <div class="mono ltr" style="user-select:all;background:var(--bg2);padding:10px 14px;border-radius:10px;word-break:break-all">{{ session('sign_link') }}</div>
        <div class="sub" style="margin-top:6px">أرسله بعد اكتمال التحرير — وكلمة السر بقناة أخرى.</div>
    </div>
@endif

@if ($req->status === 'وُقّع')
    <div class="card"><div class="sub">✅ هذه الوثيقة موقعة — لا تُحرَّر. <a href="{{ route('esign.doc', $req->id) }}">افتح الوثيقة النهائية</a></div></div>
@else
    <div class="card">
        <form method="POST" action="{{ route('esign.update', $req->id) }}">
            @csrf @method('PUT')
            <div class="fld fw" style="margin-bottom:10px"><label>العنوان</label>
                <input class="inp" name="title" required maxlength="200" value="{{ $req->title }}"></div>
            <div class="fld fw"><label>نص العقد الكامل <span class="sub">— حرّره بحرية قبل الإرسال؛ كل حفظٍ يجدّد بصمة الوثيقة</span></label>
                <textarea class="inp" name="body" rows="24" required
                          style="font-size:14.5px;line-height:2;font-family:inherit">{{ $req->body }}</textarea></div>
            <div class="formfoot">
                <button class="btn p">💾 حفظ النص</button>
                <a class="btn ghost" href="{{ route('esign.doc', $req->id) }}">👁 معاينة A4</a>
                <button class="btn ghost" type="button"
                        onclick="navigator.clipboard.writeText(@js(route('sign.show', $req->token)));this.textContent='✓ نُسخ الرابط'">📋 نسخ رابط العميل</button>
            </div>
        </form>
    </div>
@endif
@endsection
