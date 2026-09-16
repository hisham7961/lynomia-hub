{{-- ودجة: رادار الانتهاءات --}}
@if ($data && $data->count())
<div class="card kid">
    {{-- النافذةُ مقولةٌ لا مضمرة (W-3): صفوفُها تصل إلى `hub_radar_window()` يوماً،
         وشارةُ الشريطِ إلى جانبِها تعدّ ٧ أيامٍ وحدَها — فلا تُقرأ الكلمتان سؤالاً واحداً. --}}
    <h3>🔔 ينتهي خلال {{ hub_radar_window() }} يوماً <a class="btn ghost xs msauto" href="{{ route('alerts') }}">الكل ←</a></h3>
    <table class="mini">
        @foreach ($data as $i)
            <tr>
                <td><a href="{{ hub_expiry_url($i) }}">{{ \Illuminate\Support\Str::limit($i['name'], 26) }}</a><div class="sub">{{ $i['mlabel'] }} · {{ $i['flabel'] }}</div></td>
                <td class="acts"><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'wn' : 'g') }}">{{ $i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : $i['days'] . ' يوم') }}</span></td>
            </tr>
        @endforeach
    </table>
</div>
@endif
