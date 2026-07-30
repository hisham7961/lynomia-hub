@extends('layouts.app')
@section('title', 'نشاط الموظفين')
@section('content')
<div class="modhero" style="--mh:#C08A3E">
    <span class="mhico">🕵️</span>
    <div><div class="sub">مركز</div><h2>نشاط الموظفين</h2></div>
</div>
<div class="sub" style="margin-bottom:12px">من نشاط النظام نفسه: أول فتح للحساب اليوم، آخر ظهور، الزيارات والأفعال — بشفافية، داخل النظام فقط.</div>

<div class="card pad0"><div class="tblwrap"><table class="tbl">
    <thead><tr><th>الموظف</th><th>أول فتح اليوم</th><th>آخر ظهور</th><th>زيارات اليوم</th><th>أفعال اليوم</th><th class="acts"></th></tr></thead>
    <tbody>
    @foreach ($rows as $r)
        <tr>
            <td><b>{{ $r->u->name }}</b> @if (hub_is_owner($r->u))<span class="bdg g">مالك</span>@endif</td>
            <td class="mono">{{ $r->first ? substr($r->first, 11, 5) : '—' }}</td>
            <td class="mono">{{ $r->last ? substr($r->last, 11, 5) : '—' }}</td>
            <td class="mono">{{ $r->visits ?: '—' }}</td>
            <td class="mono">{{ $r->actions ?: '—' }}</td>
            <td class="acts"><a class="btn ghost xs" href="{{ route('activity.show', $r->u->id) }}">📊 التفاصيل</a></td>
        </tr>
    @endforeach
    </tbody>
</table></div></div>
@endsection
