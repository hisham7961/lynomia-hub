@extends('layouts.app')
@section('title', 'ذكاء العناوين')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span>@include('security.parts.root_crumb')<span aria-hidden="true">‹</span><b>ذكاء العناوين</b></nav>
        <h2>🌐 ذكاء العناوين</h2>
        <div class="sub">كلُّ عنوانِ شبكةٍ بما فعل: دخولٌ ناجحٌ وفاشل، رفضٌ، وأفعالٌ تستحق النظر — من سجلّ التدقيق نفسِه، بلا خدمةِ geo خارجية</div>
    </div>
    <div class="crow" style="gap:8px">
        @if ($isOwner)
            {{-- (WP-I.3) قواعدُ الدفاع للمالك وحدَه — لغيره لا رابطَ أصلاً (الشاشةُ ٤٠٤ له) --}}
            <a class="btn ghost sm" href="{{ route('security.blocks') }}">⛔ قواعد الحظر والسماح</a>
        @endif
        <a class="btn ghost sm" href="{{ route('security.sessions') }}">🖥️ مركز الجلسات</a>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'عناوين في المدى', 'value' => $kpi['total']],
    ['label' => 'موسومة بخطر', 'value' => $kpi['flagged'], 'tone' => $kpi['flagged'] ? 'bad' : 'ok',
     'hint' => 'تعدّدُ حسابات أو نشاطٌ يستحق النظر'],
    ['label' => 'دخول فاشل', 'value' => $kpi['fails'], 'tone' => $kpi['fails'] ? 'wn' : 'ok'],
    ['label' => 'محاولات مرفوضة', 'value' => $kpi['denials'], 'tone' => $kpi['denials'] ? 'wn' : 'ok'],
]])

<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧾 العناوين <span class="sub">(أوّلُ الظهور تقريبيٌّ من سجلّ التدقيق)</span></h3>
        @include('partials.timerange', ['range' => $range])
    </div>
    @include('security.parts.ips_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
