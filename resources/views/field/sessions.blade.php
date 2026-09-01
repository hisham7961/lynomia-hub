@extends('layouts.app')
@section('title', 'الجلسات الميدانية')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>العمليات الميدانية</span><span aria-hidden="true">‹</span><b>جلسات التتبّع</b></nav>
        <h2>🧭 جلسات التتبّع الميدانيّ</h2>
        <div class="sub">مساراتُ اليوم الميدانيّ — تُسجَّل ضمن جلسةٍ مصرَّحٍ بها فقط، لا تتبّعَ خارجها</div>
    </div>
</div>

<div class="card">
    <div class="tblwrap"><table>
        <thead><tr><th>المندوب</th><th>اليوم</th><th>الحالة</th><th>النقاط</th><th>المسافة</th><th>البداية</th><th></th></tr></thead>
        <tbody>
        @forelse ($sessions as $s)
            <tr>
                <td>{{ $names[$s->emp_id] ?? '—' }}</td>
                <td class="mono">{{ substr((string) $s->field_day, 0, 10) }}</td>
                <td>
                    @if ($s->status === 'نشطة')<span class="bdg wn">● نشطة</span>
                    @elseif ($s->status === 'منتهية')<span class="bdg g">منتهية</span>
                    @else<span class="bdg">{{ $s->status }}</span>@endif
                </td>
                <td class="mono">{{ number_format((int) $s->point_count) }}</td>
                <td class="mono">{{ $s->distance_m ? number_format($s->distance_m / 1000, 2) . ' كم' : '—' }}</td>
                <td class="sub">{{ $s->started_at ? \Illuminate\Support\Carbon::parse($s->started_at)->format('Y-m-d H:i') : '—' }}</td>
                <td><a class="btn ghost xs" href="{{ route('field.route', $s->id) }}">🗺️ المسار</a></td>
            </tr>
        @empty
            <tr><td colspan="7" class="empty">لا جلسات تتبّع بعد</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
