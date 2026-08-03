{{-- ودجة: مؤشرات KPI المخصصة. $data = مخرجات hub_kpis() --}}
@if (! empty($data))
    @php $canEdit = hub_monitor(); @endphp
    <div class="cards">
        @foreach ($data as $k)
            {{-- من يملك الباني يصل من البطاقة إلى تعديلها مباشرةً، وغيره لا يُرسَل إلى ٤٠٣ --}}
            <{{ $canEdit ? 'a' : 'div' }} class="stat"
                @if ($canEdit) href="{{ route('kpis.index') }}?edit={{ $k['id'] }}#kpiform" @endif
                title="مؤشر مخصص — {{ $k['explain'] ?? 'من باني KPI' }}{{ $canEdit ? ' — اضغط للتعديل' : '' }}">
                <span class="ico">📈</span>
                <b class="{{ $k['tone'] === 'bad' ? 'txt-bad' : '' }}">
                    {{ $k['value'] === null ? '—' : rtrim(rtrim(number_format($k['value'], 1), '0'), '.') }}{{ $k['unit'] ? ' ' . $k['unit'] : '' }}
                </b>
                <span>{{ $k['name'] }}@if ($k['target'] !== null && $k['tone']) · <span class="bdg {{ $k['tone'] }}" style="font-size:9px">هدف {{ rtrim(rtrim(number_format($k['target'], 1), '0'), '.') }}</span>@endif</span>
            </{{ $canEdit ? 'a' : 'div' }}>
        @endforeach
    </div>
@endif
