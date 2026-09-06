@extends('layouts.app')
@section('title', 'خطر الهويّة')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><b>خطر الهويّة</b></nav>
        <h2>🪪 خطر الهويّة</h2>
        <div class="sub">كلُّ حسابٍ بدرجته وعواملِه المفسَّرة — امتيازٌ وخمولٌ وفشلُ دخولٍ وأجهزةٌ وعناوين. إشارةُ مراجعةٍ بشرية: لا امتيازَ يُسحب آلياً</div>
    </div>
    <a class="btn ghost sm" href="{{ route('security.privileged') }}">🗂️ مراجعة الامتيازات</a>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'حسابات نشطة', 'value' => $kpi['total']],
    ['label' => 'خطرٌ عالٍ فأشدّ', 'value' => $kpi['high'], 'tone' => $kpi['high'] ? 'bad' : 'ok',
     'hint' => 'درجةٌ بلغت عتبة «عالٍ» من إعدادات risk.band_*'],
    ['label' => 'بلا تحقّقٍ بخطوتين', 'value' => $kpi['no2fa'], 'tone' => $kpi['no2fa'] ? 'wn' : 'ok'],
    ['label' => 'أصحاب امتياز', 'value' => $kpi['priv'],
     'url' => route('security.privileged'), 'hint' => 'مالكون أو حاملو رايةٍ حسّاسة — افتح المراجعة'],
]])

<div class="card">
    <h3>🧮 الحسابات بالدرجة <span class="sub">(الأعلى خطراً أولاً — والعواملُ تفسّر كلَّ درجة)</span></h3>
    @include('security.parts.identity_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
