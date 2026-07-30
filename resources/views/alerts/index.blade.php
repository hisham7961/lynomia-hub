@extends('layouts.app')
@section('title', 'ينتهي قريباً')
@section('content')
<div class="toolbar">
    <div class="sub">رادار تلقائي يمسح كل حقول الانتهاء والتجديد والاستحقاق في الوحدات الـ51 — يتحدث كل 10 دقائق</div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('alerts', ['fresh' => 1]) }}">↻ تحديث الآن</a>
</div>
@foreach ([['🔴 متأخر — انتهى فعلاً', $late, 'bad'], ['🟠 خلال ٧ أيام', $week, 'wn'], ['🟡 خلال ٣٠ يوماً', $month, 'g']] as [$title, $set, $tone])
    @if ($set->count())
    <div class="card pad0">
        <h3 style="padding:12px 14px 0">{{ $title }} <span class="bdg {{ $tone }}">{{ $set->count() }}</span></h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>السجل</th><th>الوحدة</th><th>ماذا ينتهي</th><th>التاريخ</th><th>الأيام</th></tr></thead>
            <tbody>
            @foreach ($set as $i)
                <tr>
                    <td><a href="{{ route('m.show', [$i['module'], $i['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($i['name'], 40) }}</b></a></td>
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
    <div class="card"><div class="empty"><span class="big">😌</span>لا شيء ينتهي خلال ٣٠ يوماً — كل أصولك بأمان</div></div>
@endif
@endsection
