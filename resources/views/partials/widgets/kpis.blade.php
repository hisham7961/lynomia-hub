{{-- ودجة: مؤشرات KPI المخصصة. $data = مخرجات hub_kpis() --}}
@if (! empty($data))
    <div class="cards">
        @foreach ($data as $k)
            <a class="stat" href="{{ route('kpis.index') }}" title="مؤشر مخصص — من باني KPI">
                <span class="ico">📈</span>
                <b class="{{ $k['tone'] === 'bad' ? 'txt-bad' : '' }}">
                    {{ $k['value'] === null ? '—' : rtrim(rtrim(number_format($k['value'], 1), '0'), '.') }}{{ $k['unit'] ? ' ' . $k['unit'] : '' }}
                </b>
                <span>{{ $k['name'] }}@if ($k['target'] !== null && $k['tone']) · <span class="bdg {{ $k['tone'] }}" style="font-size:9px">هدف {{ rtrim(rtrim(number_format($k['target'], 1), '0'), '.') }}</span>@endif</span>
            </a>
        @endforeach
    </div>
@endif
