@php
    $t = $f['type']; $v = $row->{$f['col']} ?? null; $key = $f['key'];
@endphp
@if ($v === null || $v === '' || $v === [])
    <span class="sub">—</span>
@elseif ($t === 'sec')
    @php $canSec = auth()->user()?->role?->is_owner || hub_flag(auth()->user(), 'secrets') || hub_flag(auth()->user(), 'copySec'); @endphp
    @if ($canSec && ($ctx ?? 'table') === 'show')
        <span class="mono" data-sec hidden>{{ $v }}</span><span class="mono" data-secmask>••••••</span>
        <button class="btn ghost xs" type="button"
                onclick="var w=this.parentNode,s=w.querySelector('[data-sec]'),m=w.querySelector('[data-secmask]');s.hidden=!s.hidden;m.hidden=!m.hidden;this.textContent=s.hidden?'إظهار':'إخفاء'">إظهار</button>
    @else
        <span class="mono">••••••</span>
    @endif
@elseif ($t === 'bool')
    <span class="bdg {{ $v ? 'ok' : '' }}">{{ $v ? 'نعم' : 'لا' }}</span>
@elseif ($t === 'sel')
    <span class="bdg {{ hub_tone($v) }}">{{ $v }}</span>
@elseif ($t === 'ref' && ! empty($f['multi']))
    @php $ids = is_array($v) ? $v : (json_decode($v, true) ?: []); @endphp
    {{ collect($ids)->map(fn ($id) => $labels[$key][$id] ?? $id)->implode(' · ') }}
@elseif ($t === 'ref')
    {{ $labels[$key][$v] ?? $v }}
@elseif ($t === 'tags')
    @php $ids = is_array($v) ? $v : (json_decode($v, true) ?: []); @endphp
    @foreach ($ids as $tg)<span class="bdg g">{{ $tg }}</span> @endforeach
@elseif ($t === 'file' || $t === 'img')
    @if ($t === 'img')<img class="thumb" src="{{ route('file.show', $v) }}" alt="">
    @else<a href="{{ route('file.show', $v) }}" target="_blank">ملف ↗</a>@endif
@elseif ($t === 'url')
    <a class="mono ltr" href="{{ hub_safe_url($v) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($v, 34) }}</a>
@elseif ($t === 'date')
    <span class="mono">{{ substr($v, 0, 10) }}</span>
@elseif ($t === 'dt')
    <span class="mono">{{ str_replace('T', ' ', substr($v, 0, 16)) }}</span>
@elseif ($t === 'num' || $t === 'big')
    <span class="mono">{{ is_numeric($v) ? number_format((float) $v, 2) : $v }}</span>
@elseif ($t === 'ta')
    @if (($ctx ?? 'table') === 'show'){!! nl2br(e($v)) !!}@else{{ \Illuminate\Support\Str::limit($v, 60) }}@endif
@else
    {{ ($ctx ?? 'table') === 'table' ? \Illuminate\Support\Str::limit($v, 48) : $v }}
@endif
