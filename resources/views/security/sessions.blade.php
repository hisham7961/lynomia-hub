@extends('layouts.app')
@section('title', 'مركز الجلسات')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><b>الجلسات</b></nav>
        <h2>🖥️ مركز الجلسات</h2>
        <div class="sub">من داخلٌ الآن ومن أيّ جهازٍ وعنوان، ومن أُخرج ولماذا — والوسمُ يلتقط العنوانَ الغريب على صاحبه</div>
    </div>
    <div class="crow" style="margin-top:0">
        <a class="btn ghost sm" href="{{ route('security.devices') }}">📱 ثقة الأجهزة</a>
        <a class="btn ghost sm" href="{{ route('security.ips') }}">🌐 ذكاء العناوين</a>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'نشطة الآن', 'value' => $kpi['live'], 'tone' => 'ok',
     'hint' => 'آخر ظهورٍ خلال ' . \App\Support\Sessions::LIVE_MIN . ' دقيقة'],
    ['label' => 'مُنهاة في المدى', 'value' => $kpi['revoked'], 'tone' => $kpi['revoked'] ? 'wn' : 'ok'],
    ['label' => 'مستخدمون', 'value' => $kpi['users']],
    ['label' => 'عناوين شبكة', 'value' => $kpi['ips']],
]])

<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧾 الجلسات <span class="sub">(الأحدث ظهوراً أولاً — بترتيبٍ ثابتٍ عبر الصفحات)</span></h3>
        @include('partials.timerange', ['range' => $range])
    </div>
    <details class="sub" style="margin:8px 0" @if ($u !== '' || $fip !== '' || $state !== '') open @endif>
        <summary style="cursor:pointer">تصفية</summary>
        <form method="GET" class="filters" style="margin-top:8px">
            @if ($range->preset !== 'custom')<input type="hidden" name="range" value="{{ $range->preset }}">@endif
            <label class="vh" for="sesu">المستخدم</label>
            <select class="inp" id="sesu" name="u">
                <option value="">كل المستخدمين</option>
                @foreach ($users as $sesU)
                    <option value="{{ $sesU->id }}" @selected($u === (string) $sesU->id)>{{ $sesU->name }}</option>
                @endforeach
            </select>
            <label class="vh" for="sesip">عنوان الشبكة</label>
            <input class="inp ltr" id="sesip" name="ip" value="{{ $fip }}" placeholder="عنوان الشبكة">
            <label class="vh" for="sesst">الحالة</label>
            <select class="inp" id="sesst" name="state">
                <option value="">الكل</option>
                <option value="live" @selected($state === 'live')>حيّة الآن</option>
                <option value="revoked" @selected($state === 'revoked')>مُنهاة</option>
            </select>
            <button class="btn sm">تصفية</button>
        </form>
    </details>
    @include('security.parts.sessions_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
