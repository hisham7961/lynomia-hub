@extends('layouts.app')
@section('title', 'ثقة الأجهزة')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><b>ثقة الأجهزة</b></nav>
        <h2>📱 ثقة الأجهزة</h2>
        <div class="sub">كلُّ جهازٍ عُرف لمستخدم: معلّقٌ حتى يُراجَع، موثوقٌ، أو مُبطَل — والمُبطَلُ يبقى ظاهراً هنا. بلا أيّ بصمةٍ غازية</div>
    </div>
    <a class="btn ghost sm" href="{{ route('security.sessions') }}">🖥️ مركز الجلسات</a>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'موثوقة', 'value' => $kpi['known'], 'tone' => 'ok'],
    ['label' => 'معلّقة (جديدة)', 'value' => $kpi['new'], 'tone' => $kpi['new'] ? 'wn' : 'ok'],
    ['label' => 'مشبوهة', 'value' => $kpi['suspicious'], 'tone' => $kpi['suspicious'] ? 'bad' : 'ok',
     'hint' => 'معلّقةٌ آخرُ عنوانها غيرُ مألوفٍ لصاحبها في ذاكرة العناوين'],
    ['label' => 'مُبطَلة', 'value' => $kpi['revoked'], 'tone' => 'g'],
]])

<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🗂️ الأجهزة</h3>
        <div class="toolbar" style="gap:6px">
            @foreach (['' => 'الكل', 'known' => 'موثوقة', 'new' => 'معلّقة', 'suspicious' => 'مشبوهة', 'revoked' => 'مُبطَلة'] as $devK => $devL)
                <a class="btn sm {{ $t === $devK ? 'p' : 'ghost' }}"
                   href="{{ route('security.devices', $devK === '' ? [] : ['t' => $devK]) }}">{{ $devL }}</a>
            @endforeach
        </div>
    </div>
    <div class="sub" style="margin:4px 0 10px">التوثيقُ والإبطالُ بيد المستخدم نفسِه من «أمني» — هذه مراجعةُ المالك والمراقب، عرضٌ لا فعل</div>
    @include('security.parts.devices_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
