@extends('layouts.app')
@section('title', 'صحّة الأسرار')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><b>صحّة الأسرار</b></nav>
        <h2>🗝️ صحّة الأسرار</h2>
        <div class="sub">عمرُ كل سرٍّ من آخر تدويرٍ فعليّ (لا من آخر تعديلِ ملاحظة) ومن كشَفه كم مرة — بلا قيمةٍ ولا بصمةٍ على هذه الصفحة إطلاقاً</div>
    </div>
    <div class="crow" style="margin-top:0">
        <a class="btn ghost sm" href="{{ route('security.tokens') }}">🔑 رموز API</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'vault') }}">الخزنة ←</a>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'كل الأسرار', 'value' => $rows->total()],
    ['label' => 'بائتة (تجاوزت ' . $staleDays . ' يوماً)', 'value' => $staleCount,
     'tone' => $staleCount ? 'wn' : 'ok', 'hint' => 'العمرُ من rotated_at — ومن لم يُدوَّر قطُّ فمن إنشائه'],
]])

<div class="card">
    <h3 style="margin:0 0 8px">🧾 الأسرار <span class="sub">(الأقدمُ تدويراً أولاً — بترتيبٍ ثابتٍ عبر الصفحات)</span></h3>
    @include('security.parts.secrets_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
