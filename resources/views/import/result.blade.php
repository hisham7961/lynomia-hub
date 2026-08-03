@extends('layouts.app')
@section('title', 'نتيجة الاستيراد — ' . $def['label'])
@section('content')
@include('partials.pagehead', ['icon' => $ok ? '✅' : '⚠️', 'title' => 'اكتمل الاستيراد إلى ' . $def['label'],
    'crumb' => $def['label'], 'crumbUrl' => route('m.index', $module),
    'back' => route('m.index', $module), 'backLabel' => $def['label']])
<div class="card" style="max-width:620px">
    <div class="cards" style="margin:12px 0">
        <div class="stat"><span class="ico">✔️</span><b>{{ number_format($ok) }}</b><span>سجل مستورد</span></div>
        <div class="stat"><span class="ico">⏭</span><b class="{{ count($skipped) ? 'txt-bad' : '' }}">{{ count($skipped) }}</b><span>صف متخطى</span></div>
    </div>
    @if (count($skipped))
        <h3 class="sub" style="margin-bottom:6px">أسباب التخطي (أول ١٥):</h3>
        <ul class="sub" style="padding-inline-start:18px;line-height:2">
            @foreach (array_slice($skipped, 0, 15) as $s)<li>{{ $s }}</li>@endforeach
        </ul>
    @endif
    <div class="formfoot">
        <a class="btn p" href="{{ route('m.index', $module) }}">فتح {{ $def['label'] }} ←</a>
        <a class="btn ghost" href="{{ route('m.import', $module) }}">استيراد ملف آخر</a>
    </div>
</div>
@endsection
