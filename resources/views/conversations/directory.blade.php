@extends('layouts.app')
@section('title', 'دليلُ القنوات')
@section('content')

<div class="hero">
    <div>
        <h2>🧭 دليلُ القنوات</h2>
        <div class="sub">قنواتٌ مفتوحةٌ يمكنك الانضمامُ إليها — الخاصّةُ لا تظهر (بالدعوة فقط)</div>
    </div>
    <a class="btn ghost sm" href="{{ route('conversations.index') }}">← قنواتي</a>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif

<div class="card">
    <h3>قنواتٌ للانضمام <span class="bdg g">{{ $channels->count() }}</span></h3>
    @forelse ($channels as $ch)
        <div class="row" style="display:flex;gap:8px;align-items:center;padding:10px 6px;border-top:1px solid var(--brd)">
            <span class="ava sm">#</span>
            <b style="flex:1;min-width:0">{{ $ch->title ?: 'قناةٌ بلا اسم' }}</b>
            <span class="sub">{{ $ch->members_count }} عضواً</span>
            <span class="bdg" title="مدى الظهور">{{ $ch->visibility === 'public' ? '🌐 عامّة' : '🏢 الشركة' }}</span>
            <form method="POST" action="{{ route('conversations.join', $ch->id) }}" class="inline">
                @csrf<button class="btn sm p" type="submit">انضمام</button>
            </form>
        </div>
    @empty
        <div class="sub" style="padding:10px 0">لا قنواتٍ مفتوحةً للانضمام الآن — القنواتُ الخاصّةُ بالدعوة.</div>
    @endforelse
</div>

@endsection
