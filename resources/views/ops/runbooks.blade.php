@extends('layouts.app')
@section('title', 'كتيّبات التشغيل')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><a href="{{ route('ops.index') }}">مركز التشغيل</a><span aria-hidden="true">‹</span><b>كتيّبات التشغيل</b></nav>
        <h2>📘 كتيّبات التشغيل</h2>
        <div class="sub">إجراءاتٌ مختصرة للأعطال الكبرى — المصدر: <span class="mono ltr">docs/RUNBOOKS.md</span></div>
    </div>
    <a class="btn ghost sm" href="{{ route('ops.index') }}#health">🩺 صحّة المنصة ←</a>
</div>
<div class="card" style="line-height:1.8">
    {!! $html !!}
</div>
@endsection
