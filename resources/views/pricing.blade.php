@extends('layouts.app')
@section('title', 'الباقات والتسعير')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>اللوحات</span><span aria-hidden="true">‹</span><b>الباقات والتسعير</b></nav>
        <h2>💳 الباقات والتسعير</h2>
        <div class="sub">
            بطاقاتٌ لا صفوف: كل خدمةٍ بوجهها وباقاتها و<b>هامش كل باقة</b> وموقعها من أسعار المنافسين —
            ثم <b>ما يستحق قراراً اليوم</b>.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('m.index', 'plans') }}">جدول الباقات ↗</a>
</div>
@include('pricing_body')
@endsection
