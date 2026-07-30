@extends('layouts.app')
@section('title', $board->name ?? 'لوحة التحكم')
@section('content')
<div class="hero">
    <div>
        <h2>أهلاً {{ auth()->user()->name }} 👋</h2>
        <div class="sub">{{ now()->translatedFormat('l · j F Y') }}</div>
    </div>
    @if (count($boards) || $board)
        <div class="boardbar">
            <a class="btn ghost xs {{ $board ? '' : 'on' }}" href="{{ route('dashboard') }}">الافتراضية</a>
            @foreach ($boards as $b)
                <a class="btn ghost xs {{ $board && $board->id === $b->id ? 'on' : '' }}"
                   href="{{ route('dashboard', ['d' => $b->id]) }}">{{ $b->name }}</a>
            @endforeach
            <a class="btn ghost xs" href="{{ route('boards.index') }}">🧩 لوحاتي</a>
        </div>
    @endif
</div>

@if ($board)
    {{-- لوحة مبنيّة: ودجاتها بترتيبها المحفوظ، وكل ودجة مرّت ببوابتها قبل الوصول هنا --}}
    @foreach ($layout as $w)
        @if (in_array($w['key'], ['counts', 'kpis'], true))
            @include('partials.widgets.' . $w['key'], ['data' => $w['data']])
        @endif
    @endforeach
    <div class="kids">
        @foreach ($layout as $w)
            @unless (in_array($w['key'], ['counts', 'kpis'], true))
                @include('partials.widgets.' . $w['key'], ['data' => $w['data']])
            @endunless
        @endforeach
    </div>
    @if (! count($layout))
        <div class="card"><div class="sub">هذه اللوحة بلا ودجات بعد —
            <a href="{{ route('boards.edit', $board->id) }}">أضف ودجاتها</a>.</div></div>
    @endif
@else
    {{-- اللوحة الافتراضية: نفس البطاقات وبنفس الترتيب كما كانت --}}
    @include('partials.widgets.kpis',   ['data' => $kpis])
    @include('partials.widgets.counts', ['data' => $cards])
    <div class="kids">
        @include('partials.widgets.expiry', ['data' => $expiry])
        @include('partials.widgets.apps',   ['data' => $apps])
        @include('partials.widgets.donut',  ['data' => $taskSlices])
        @unless (in_array('recent', $hid ?? [], true))
            @include('partials.widgets.recent')
        @endunless
        @include('partials.widgets.due', ['data' => ['rows' => $due, 'dueCol' => $dueCol,
                                                     'stCol' => $stCol, 'disp' => $disp]])
        @unless (in_array('audits', $hid ?? [], true))
            @include('partials.widgets.audits', ['data' => $audits])
        @endunless
    </div>
@endif
@endsection
