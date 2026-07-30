@php $dests = $dests ?? []; @endphp
@foreach ($dests as $d)
    <a class="gitem" href="{{ $d['u'] }}"><span class="bdg wn">صفحة</span> {{ $d['t'] }}</a>
@endforeach
@if (count($flat))
    @foreach ($flat as $r)
        <a class="gitem" href="{{ route('m.show', [$r['module'], $r['id']]) }}">
            <span class="bdg g">{{ $r['label'] }}</span> {{ \Illuminate\Support\Str::limit($r['name'], 40) }}
        </a>
    @endforeach
    <a class="gitem all" href="{{ route('search', ['q' => $q]) }}">كل النتائج ←</a>
@elseif (! count($dests) && mb_strlen($q) >= 2)
    <div class="gitem sub">لا نتائج لـ«{{ $q }}»</div>
@endif
