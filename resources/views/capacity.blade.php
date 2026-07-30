@extends('layouts.app')
@section('title', 'القدرات والاستغلال')
@section('content')
@php $t = $c['totals']; @endphp
<div class="hero">
    <div>
        <h2>📊 القدرات والموارد</h2>
        <div class="sub">
            هل يستطيع فريقك تنفيذ ما التزمتَ به؟ المتاح = (أيام العمل − الإجازات المعتمدة) × ساعات اليوم،
            والمحجوز = الساعات المقدَّرة لمهامه المفتوحة المستحقة في الفترة.
        </div>
    </div>
    <form class="filters" method="GET">
        <label class="vh" for="cf">من تاريخ</label>
        <input class="inp" id="cf" type="date" name="from" value="{{ $c['from'] }}">
        <label class="vh" for="ct">إلى تاريخ</label>
        <input class="inp" id="ct" type="date" name="to" value="{{ $c['to'] }}">
        <button class="btn sm">عرض</button>
    </form>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🗓️</span><b>{{ $c['workDays'] }}</b><span>يوم عمل × {{ $c['hoursDay'] }} ساعات</span></div>
    <div class="stat"><span class="ico">🧮</span><b>{{ number_format($t['available'] ?? 0) }}</b><span>ساعة متاحة للفريق</span></div>
    <div class="stat"><span class="ico">📌</span><b>{{ number_format($t['booked'] ?? 0, 1) }}</b><span>ساعة محجوزة بمهام مفتوحة</span></div>
    <div class="stat"><span class="ico">⏱</span><b>{{ number_format($t['logged'] ?? 0, 1) }}</b><span>ساعة مسجَّلة فعلياً</span></div>
    <div class="stat"><span class="ico">🔥</span><b class="{{ ($t['over'] ?? 0) ? 'txt-bad' : '' }}">{{ $t['over'] ?? 0 }}</b><span>فوق طاقتهم (اختناق)</span></div>
    <div class="stat"><span class="ico">🌤️</span><b>{{ $t['idle'] ?? 0 }}</b><span>طاقة متاحة دون النصف</span></div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>القسم</th><th>إجازات</th><th>متاح</th>
            <th>محجوز</th><th>الحمل</th><th>مسجَّل</th><th>الاستغلال</th><th>مهام</th><th>مشاريع</th>
        </tr></thead>
        <tbody>
        @forelse ($c['rows'] as $r)
            <tr>
                <td><a href="{{ route('m.show', ['hr', $r['id']]) }}"><b>{{ $r['name'] }}</b></a>
                    @if (! $r['linked'])<div class="sub">⚠️ بلا حساب مستخدم مربوط — لا تُحتسب مهامه</div>@endif</td>
                <td class="sub">{{ $r['dept'] ?: '—' }}</td>
                <td class="sub">{{ $r['leaveDays'] ?: '—' }}</td>
                <td>{{ $r['available'] }}</td>
                <td>{{ $r['booked'] }}</td>
                <td style="min-width:120px">
                    @if ($r['load'] === null)<span class="sub">—</span>@else
                        <div style="background:var(--pss);border-radius:99px;height:8px;overflow:hidden">
                            <div style="height:100%;width:{{ min(100, $r['load']) }}%;background:{{ $r['load'] > 100 ? 'var(--bad)' : ($r['load'] > 85 ? 'var(--wn)' : 'var(--p)') }}"></div>
                        </div>
                        <span class="sub {{ $r['load'] > 100 ? 'txt-bad' : '' }}">{{ $r['load'] }}٪</span>
                    @endif
                </td>
                <td class="sub">{{ $r['logged'] }}</td>
                <td><span class="bdg {{ $r['util'] === null ? '' : ($r['util'] >= 70 ? 'ok' : ($r['util'] >= 40 ? 'wn' : '')) }}">
                    {{ $r['util'] !== null ? $r['util'] . '٪' : '—' }}</span></td>
                <td class="sub">{{ $r['tasks'] }}</td>
                <td class="sub">{{ $r['projects'] }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="empty"><span class="big">👥</span>لا موظفين على رأس العمل</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card" style="margin-top:12px">
    <h3>كيف تقرأ هذه الأرقام؟</h3>
    <div class="sub" style="line-height:2">
        <b>الحمل</b> يخبرك بالالتزام القادم: فوق ١٠٠٪ يعني وعدتَ بأكثر مما يملك الموظف من ساعات — إما تؤجّل أو توزّع أو توظّف.<br>
        <b>الاستغلال</b> يخبرك بالماضي: كم من ساعاته المتاحة سُجّلت فعلاً. الفجوة بين الاثنين غالباً تعني تقديرات غير واقعية أو ساعات لا تُسجَّل.<br>
        ⚠️ موظف بلا حساب مستخدم مربوط لا تُحتسب مهامه — اربطه من ملفه الوظيفي ليظهر حمله الحقيقي.<br>
        عطلة الأسبوع الجمعة والسبت (تُضبط بالإعداد <span class="mono ltr">cost.weekend</span>)، وساعات اليوم بـ<span class="mono ltr">cost.work_hours</span>.
    </div>
</div>
@endsection
