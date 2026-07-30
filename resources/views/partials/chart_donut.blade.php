{{-- دونات SVG بلا مكتبات — يتوقع: $slices = [['label' =>, 'value' =>], …] --}}
@php
    $total = max(1, collect($slices)->sum('value'));
    $palette = ['#0E7C66', '#E0A82E', '#C0504D', '#4472A8', '#7E6BB5', '#6A9A57'];
    $r = 42; $circ = 2 * pi() * $r; $off = 0;
@endphp
@if (collect($slices)->sum('value') > 0)
<div class="donutwrap">
    <svg viewBox="0 0 120 120" class="donut" role="img" aria-label="مخطط دائري">
        @foreach ($slices as $i => $s)
            @php $len = $s['value'] / $total * $circ; @endphp
            <circle cx="60" cy="60" r="{{ $r }}" fill="none" stroke="{{ $palette[$i % 6] }}" stroke-width="17"
                    stroke-dasharray="{{ $len }} {{ $circ - $len }}" stroke-dashoffset="{{ -$off }}"
                    transform="rotate(-90 60 60)"><title>{{ $s['label'] }}: {{ $s['value'] }}</title></circle>
            @php $off += $len; @endphp
        @endforeach
        <text x="60" y="60" text-anchor="middle" dominant-baseline="central" class="dtotal">{{ number_format($total) }}</text>
    </svg>
    <div class="dlegend">
        @foreach ($slices as $i => $s)
            <span><i style="background:{{ $palette[$i % 6] }}"></i>{{ \Illuminate\Support\Str::limit($s['label'], 16) }} <b>{{ $s['value'] }}</b></span>
        @endforeach
    </div>
</div>
@else
<div class="sub" style="padding:14px;text-align:center">لا بيانات بعد</div>
@endif
