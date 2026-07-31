{{-- ودجة: رادار الانتهاءات --}}
@if ($data && $data->count())
<div class="card kid">
    <h3>🔔 ينتهي قريباً <a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('alerts') }}">الكل ←</a></h3>
    <table class="mini">
        @foreach ($data as $i)
            <tr>
                <td><a href="{{ route('m.show', [$i['module'], $i['id']]) }}">{{ \Illuminate\Support\Str::limit($i['name'], 26) }}</a><div class="sub">{{ $i['mlabel'] }} · {{ $i['flabel'] }}</div></td>
                <td class="acts"><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'wn' : 'g') }}">{{ $i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : $i['days'] . ' يوم') }}</span></td>
            </tr>
        @endforeach
    </table>
</div>
@endif
