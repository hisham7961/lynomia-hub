@extends('layouts.app')
@section('title', $board->name ?? 'لوحة التحكم')
@section('content')
@php $h = (int) now()->format('H'); $greet = $h < 12 ? 'صباح الخير' : ($h < 17 ? 'طاب يومك' : 'مساء الخير'); @endphp
<div class="welcome">
    <div class="wtext">
        <h2>{{ $greet }}، {{ auth()->user()->name }} 👋</h2>
        <div class="wsub">{{ $pending ?? '' }}</div>
        <div class="wdate">{{ now()->translatedFormat('l · j F Y') }}</div>
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
    {{-- لوحة مبنيّة: ودجاتها بترتيبها وعرضها المحفوظين.
         بطاقات العدّ ومؤشرات KPI صفوفٌ كاملة بطبعها فتُثبَّت على العرض الكامل. --}}
    <div class="bgrid">
        @foreach ($layout as $w)
            @php $span = in_array($w['key'], ['counts', 'kpis'], true) ? 12 : (int) $w['w']; @endphp
            <div class="bcell" style="--w:{{ max(3, min(12, $span)) }}">
                @include('partials.widgets.' . $w['key'], ['data' => $w['data']])
            </div>
        @endforeach
    </div>
    @if (! count($layout))
        <div class="card"><div class="sub">هذه اللوحة بلا ودجات بعد —
            <a href="{{ route('boards.edit', $board->id) }}">أضف ودجاتها</a>.</div></div>
    @endif
@else
    {{-- اللوحة الافتراضية بترتيب «قرارات أولاً»: ما يستحق تدخلك الآن (ينتهي قريباً،
         مواعيد تقترب) يتصدر — والمرجعيات (تقدم، حالات، سجل) بعده --}}
    {{-- يومُ الموظف أولُ ما يلقاه: حضورٌ بضغطةٍ وبنودُ تقريره — لمن له ملفٌّ مربوط --}}
    @php $wdMine = \App\Support\WidgetRegistry::resolve('checkin', auth()->user()); @endphp
    @if ($wdMine)@include('partials.widgets.checkin', ['data' => $wdMine])@endif
    @include('partials.widgets.kpis',   ['data' => $kpis])
    @include('partials.widgets.counts', ['data' => $cards])
    <div class="kids">
        @include('partials.widgets.expiry', ['data' => $expiry])
        @include('partials.widgets.due', ['data' => ['rows' => $due, 'dueCol' => $dueCol,
                                                     'stCol' => $stCol, 'disp' => $disp]])
        @unless (in_array('links', $hid ?? [], true))
            @include('partials.widgets.links', ['data' => $links])
        @endunless
        @include('partials.widgets.apps',   ['data' => $apps])
        @include('partials.widgets.donut',  ['data' => $taskSlices])
        @unless (in_array('recent', $hid ?? [], true))
            @include('partials.widgets.recent')
        @endunless
        @unless (in_array('audits', $hid ?? [], true))
            @include('partials.widgets.audits', ['data' => $audits])
        @endunless
    </div>
@endif
@endsection
