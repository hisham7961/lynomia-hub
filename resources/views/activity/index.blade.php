@extends('layouts.app')
@section('title', 'نشاط الموظفين')
@section('content')
<div class="modhero" style="--mh:#C08A3E">
    <span class="mhico">🕵️</span>
    <div><div class="sub">مركز</div><h2>نشاط الموظفين</h2></div>
</div>
<div class="sub" style="margin-bottom:12px">من نشاط النظام نفسه: من متصلٌ الآن، آخر ظهورٍ حقيقيّ (نبضة الجلسة)، وزيارات/أفعال اليوم — بشفافية، داخل النظام فقط.</div>

@php $onlineNow = collect($rows)->where('online', true)->count(); @endphp
<div class="sub" style="margin-bottom:10px">
    <span class="bdg g">🟢 متصل الآن: {{ $onlineNow }}</span>
    <span class="bdg">👥 المستخدمون: {{ count($rows) }}</span>
</div>

<div class="card pad0"><div class="tblwrap"><table class="tbl">
    <thead><tr><th>الموظف</th><th>الحالة</th><th>آخر ظهور</th><th>أول فتح اليوم</th><th>زيارات اليوم</th><th>أفعال اليوم</th><th class="acts"></th></tr></thead>
    <tbody>
    @foreach ($rows as $r)
        <tr>
            <td><b>{{ $r->u->name }}</b> @if (hub_is_owner($r->u))<span class="bdg g">مالك</span>@endif</td>
            <td>
                @if ($r->online)<span class="bdg g">🟢 متصل</span>
                @elseif ($r->last)<span class="bdg" title="{{ $r->last }}">منذ {{ \Illuminate\Support\Carbon::parse($r->last)->diffForHumans(null, true) }}</span>
                @else<span class="sub">لم يدخل بعد</span>@endif
            </td>
            <td class="mono">{{ $r->last ? \Illuminate\Support\Carbon::parse($r->last)->format('Y-m-d H:i') : '—' }}</td>
            <td class="mono">{{ $r->first ? substr($r->first, 11, 5) : '—' }}</td>
            <td class="mono">{{ $r->visits ?: '—' }}</td>
            <td class="mono">{{ $r->actions ?: '—' }}</td>
            <td class="acts"><a class="btn ghost xs" href="{{ route('activity.show', $r->u->id) }}">📊 التفاصيل</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
