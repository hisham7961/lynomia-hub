{{-- ودجة: بطاقات العدّ العلوية — لكل وحدة لون هوية واتجاه أسبوعي --}}
@php
    $meta = [
        'projects'  => ['🚀', '#2FB79A'], 'clients' => ['🤝', '#4C6FA5'],
        'tasks'     => ['✅', '#0E7C66'], 'tickets' => ['🎫', '#C08A3E'],
        'fin'       => ['💵', '#B0568E'], 'contracts' => ['📜', '#7C6FB0'],
    ];
@endphp
@if (! empty($data))
    <div class="cards">
        @foreach ($data as $c)
            @php [$ico, $clr] = $meta[$c['key']] ?? ['📁', 'var(--p)']; @endphp
            <a class="stat" style="--st:{{ $clr }}" href="{{ route('m.index', $c['key']) }}">
                <span class="stico">{{ $ico }}</span>
                <b>{{ number_format($c['count']) }}</b><span>{{ $c['label'] }}</span>
                @if (($c['trend'] ?? null) !== null)
                    <span class="trend {{ $c['trend'] >= 0 ? 'up' : 'dn' }}">
                        {{ $c['trend'] >= 0 ? '↑' : '↓' }} {{ abs($c['trend']) }}٪ عن الأسبوع الماضي
                    </span>
                @endif
            </a>
        @endforeach
    </div>
@endif
