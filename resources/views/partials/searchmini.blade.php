@if (count($flat))
    @foreach ($flat as $r)
        <a class="gitem" href="{{ route('m.show', [$r['module'], $r['id']]) }}">
            <span class="bdg g">{{ $r['label'] }}</span> {{ \Illuminate\Support\Str::limit($r['name'], 40) }}
        </a>
    @endforeach
    <a class="gitem all" href="{{ route('search', ['q' => $q]) }}">كل النتائج ←</a>
@elseif (mb_strlen($q) >= 2)
    <div class="gitem sub">لا نتائج لـ«{{ $q }}»</div>
@endif
