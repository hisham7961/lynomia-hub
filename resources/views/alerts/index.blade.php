@extends('layouts.app')
@section('title', 'ينتهي قريباً')
@section('content')
@component('partials.pagehead', ['icon' => '⏳', 'title' => 'ينتهي قريباً', 'crumb' => 'الأدوات',
    'sub' => 'رادار تلقائي يمسح حقول الانتهاء والتجديد والاستحقاق في الوحدات، ووثائقَ السجلات معها — نافذةٌ واحدة: ' . hub_radar_window() . ' يوماً إلى الأمام و' . hub_radar_lookback() . ' يوماً للمنتهي، ويتحدث كل 10 دقائق'])
    <a class="btn ghost sm" href="{{ route('alerts', ['fresh' => 1]) }}">↻ تحديث الآن</a>
@endcomponent
@foreach ([['🔴 متأخر — انتهى فعلاً', $late, 'bad'], ['🟠 خلال ٧ أيام', $week, 'wn'], ['🟡 لاحقاً', $month, 'g']] as [$title, $set, $tone])
    @if ($set->count())
    <div class="card pad0">
        <h3 style="padding:12px 14px 0">{{ $title }} <span class="bdg {{ $tone }}">{{ $set->count() }}</span></h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>السجل</th><th>الوحدة</th><th>ماذا ينتهي</th><th>التاريخ</th><th>الأيام</th></tr></thead>
            <tbody>
            @foreach ($set as $i)
                <tr>
                    <td><a href="{{ hub_expiry_url($i) }}"><b>{{ \Illuminate\Support\Str::limit($i['name'], 40) }}</b></a></td>
                    <td>{{ $i['mlabel'] }}</td>
                    <td class="sub">{{ $i['flabel'] }}</td>
                    <td class="mono">{{ $i['date'] }}</td>
                    <td><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'wn' : 'g') }}">{{ $i['days'] < 0 ? 'متأخر ' . abs($i['days']) . ' يوم' : ($i['days'] === 0 ? 'اليوم!' : 'بعد ' . $i['days'] . ' يوم') }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
    @endif
@endforeach
@if (! $late->count() && ! $week->count() && ! $month->count())
    {{-- صارت النافذةُ رقماً واحداً (N-6 · `hub_radar_window`) تقرؤه الحقولُ
         والوثائقُ ومسحُ صاحبِ الشأنِ معاً — فالجملةُ تُصرّح به بدل أن تقول
         «قريباً» مبهمةً خشيةَ أن تكذبَ نصفَ الصفحة. --}}
    <div class="card"><div class="empty"><span class="big">😌</span>
        لا شيء ينتهي خلال {{ hub_radar_window() }} يوماً فيما تراه صلاحيّتُك
    </div></div>
@endif
@endsection
