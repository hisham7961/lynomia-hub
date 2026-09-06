{{-- بطاقات KPI لمركز التحكّم (WP-1.5، spec §12 المستوى ٢) — يتوقع:
     $items[] = {label, value, tone?, sub?, url?, hint?}. النبرةُ تلوّن الرقم
     (ok|wn|bad — و«g» المحايدة بلا لون)، والبطاقةُ تصير رابطاً حين يُمرَّر url. --}}
<div class="cards">
    @forelse ($items ?? [] as $ccK)
        @php $ccTone = in_array($ccK['tone'] ?? '', ['ok', 'wn', 'bad'], true) ? $ccK['tone'] : ''; @endphp
        @if (! empty($ccK['url']))<a class="kpi" href="{{ $ccK['url'] }}" style="color:inherit" @if (! empty($ccK['hint'])) title="{{ $ccK['hint'] }}" @endif>@else<div class="kpi" @if (! empty($ccK['hint'])) title="{{ $ccK['hint'] }}" @endif>@endif
            <div class="lbl">{{ $ccK['label'] }}</div>
            <div class="val {{ $ccTone }}">{{ $ccK['value'] }}</div>
            @if (! empty($ccK['sub']))<div class="sub">{{ $ccK['sub'] }}</div>@endif
        @if (! empty($ccK['url']))</a>@else</div>@endif
    @empty
        @include('partials.empty', ['text' => 'لا مؤشّرات بعد', 'icon' => '📊'])
    @endforelse
</div>
