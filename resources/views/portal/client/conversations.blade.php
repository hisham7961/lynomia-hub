@extends('layouts.portal')
@section('title', 'المحادثات')
@section('content')

<div class="hero"><div><h2>💬 محادثاتك</h2>
    <div class="sub">القنواتُ والرسائلُ المشترَكة معك — لا محادثةً داخليّةً تظهر هنا.</div></div></div>

@if ($conversations->isEmpty())
    <div class="cportal-empty"><h3>لا بيانات بعد</h3>
        <p class="sub">لا محادثاتٍ مُشارَكةً معك حتى الآن.</p></div>
@else
    <div class="card">
        @foreach ($conversations as $c)
            <div class="inbrow" style="display:flex;justify-content:space-between;gap:8px;padding:9px 0;border-top:1px solid var(--bd,#e5e7eb)">
                <a href="{{ route('portal.conversation', $c->id) }}"><b>{{ $c->title ?: 'محادثة' }}</b></a>
                <span class="sub">{{ $c->kind === 'dm' ? 'رسالة مباشرة' : 'قناة' }}</span>
            </div>
        @endforeach
    </div>
@endif

@endsection
