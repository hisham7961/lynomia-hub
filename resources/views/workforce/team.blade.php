@extends('layouts.app')
@section('title', 'فريقي اليوم')
@section('content')
<div class="hero">
    <div>
        <h2>🕗 فريقي اليوم <span class="sub mono">{{ $date ?? now()->toDateString() }}</span></h2>
        <div class="sub">من حضر، ومن أين، وماذا عمل، وما الذي تعثّر — من مصادره لا من سؤالٍ في الممر.
            غيابُ التقرير حالةُ مراجعةٍ لا غياب.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('m.index', 'attend') }}">📋 سجل الحضور</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'updates') }}">📝 تحديثات العمل</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'leaves') }}">🏝️ الإجازات</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">👥</span><b>{{ number_format($n['emps'] ?? 0) }}</b><span>موظفاً نشطاً</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ number_format($n['in'] ?? 0) }}</b><span>سجّل حضوراً</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ number_format($n['noreport'] ?? 0) }}</b><span>بلا تقرير بعد</span></div>
    <div class="stat"><span class="ico">🏝️</span><b>{{ number_format($n['leave'] ?? 0) }}</b><span>في إجازة</span></div>
    <div class="stat"><span class="ico">🚗</span><b>{{ number_format($n['field'] ?? 0) }}</b><span>ميداني/عن بعد</span></div>
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($n['hours'] ?? 0, 1) }}</b><span>ساعة مسجَّلة</span></div>
    @if (($n['blockers'] ?? 0) > 0)
        <div class="stat"><span class="ico">🚧</span><b>{{ number_format($n['blockers']) }}</b><span>عائقاً مُبلَّغاً</span></div>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">اليوم موظفاً موظفاً</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>الحالة</th><th>حضور</th><th>انصراف</th><th>الوضع</th>
            <th>بنود اليوم</th><th>ساعات البنود</th><th>المشاريع</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($rows as $r)
            @php $a = $r['att']; @endphp
            <tr>
                <td><b>{{ $r['emp']->name }}</b>
                    @if ($r['emp']->dept)<div class="sub">{{ $r['emp']->dept }}</div>@endif</td>
                <td>
                    @if ($a)
                        <span class="bdg {{ hub_tone($a->status) }}">{{ $a->status ?: '—' }}</span>
                    @else
                        <span class="bdg wn">لم يسجّل بعد</span>
                    @endif
                    @if ($r['blockers'])<span class="bdg bad" title="بنود فيها مشكلات مُبلَّغة">🚧 {{ $r['blockers'] }}</span>@endif
                </td>
                <td class="mono">{{ $a?->time_in ?: '—' }}</td>
                <td class="mono">{{ $a?->time_out ?: '—' }}</td>
                <td>{{ $a?->mode ?: '—' }}</td>
                <td>{{ $r['entries'] ?: '—' }}</td>
                <td class="mono">{{ $r['hours'] ? number_format($r['hours'], 1) : '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit(implode(' · ', $r['projects']), 40) ?: '—' }}</td>
                <td style="white-space:nowrap">
                    @if ($a)
                        <a class="btn ghost xs" href="{{ route('m.show', ['attend', $a->id]) }}" title="سجل اليوم — والتصحيح من التعديل بأثرٍ مدقَّق">↗</a>
                    @elseif (hub_can(auth()->user(), 'attend', 'a'))
                        <a class="btn ghost xs" href="{{ route('m.create', 'attend') }}" title="تسجيل يدوي (نسي الموظف؟)">＋</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="sub">لا موظفين نشطين في نطاقك.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        التصحيحاتُ (نسي الحضور، تعطّل الإنترنت…) من تعديل سجل الحضور نفسه — كلُّ تعديلٍ
        باسم صاحبه وقيمتِه القديمة والجديدة في سجل التدقيق، ولا تعديلَ صامتاً.
    </div>
</div>
@endsection
