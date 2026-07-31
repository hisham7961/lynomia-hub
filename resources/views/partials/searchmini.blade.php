@php $dests = $dests ?? []; $acts = $acts ?? []; $flat = $flat ?? []; @endphp
@if ($q === '' && (count($dests) || count($acts)))
    <div class="gitem sub ghint">اكتب للبحث في كل شيء — ↑↓ للتنقل وEnter للفتح</div>
@endif
@foreach ($dests as $d)
    <a class="gitem" href="{{ $d['u'] }}"><span class="bdg wn">صفحة</span> {{ $d['t'] }}</a>
@endforeach
@foreach ($acts as $a)
    <a class="gitem" href="{{ $a['u'] }}"><span class="bdg">إجراء</span> ＋ {{ $a['t'] }} — سجل جديد</a>
@endforeach
@if (count($flat))
    @foreach ($flat as $r)
        <a class="gitem" href="{{ route('m.show', [$r['module'], $r['id']]) }}">
            <span class="bdg g">{{ $r['label'] }}</span> {{ \Illuminate\Support\Str::limit($r['name'], 40) }}
        </a>
    @endforeach
    <a class="gitem all" href="{{ route('search', ['q' => $q]) }}">كل النتائج ←</a>
@elseif (! count($dests) && ! count($acts) && mb_strlen($q) >= 2)
    <div class="gitem sub">لا نتائج لـ«{{ $q }}»</div>
@endif
