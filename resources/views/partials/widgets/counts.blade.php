{{-- ودجة: بطاقات العدّ العلوية --}}
@php $icons = ['projects' => '🚀', 'clients' => '🤝', 'tasks' => '✅', 'tickets' => '🎫', 'fin' => '💵', 'contracts' => '📜']; @endphp
@if (! empty($data))
    <div class="cards">
        @foreach ($data as $c)
            <a class="stat" href="{{ route('m.index', $c['key']) }}">
                <span class="ico">{{ $icons[$c['key']] ?? '📁' }}</span>
                <b>{{ number_format($c['count']) }}</b><span>{{ $c['label'] }}</span>
            </a>
        @endforeach
    </div>
@endif
