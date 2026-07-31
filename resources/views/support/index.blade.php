@extends('layouts.app')
@section('title', 'لوحة الدعم')
@section('content')
<div class="hero">
    <div>
        <h2>🎫 لوحة الدعم</h2>
        <div class="sub">طابور التذاكر بمؤقتات SLA — قواعد الاستجابة والحل تُضبط من الإعدادات (sla.rules)</div>
    </div>
    @if (hub_can(auth()->user(), 'tickets', 'a'))
        <a class="btn p sm" href="{{ route('m.create', 'tickets') }}">＋ تذكرة</a>
    @endif
</div>

<div class="cards">
    <div class="stat"><span class="ico">📥</span><b>{{ $kpi['open'] }}</b><span>تذاكر مفتوحة</span></div>
    <div class="stat"><span class="ico">⏰</span><b class="{{ $kpi['respLate'] ? 'txt-bad' : '' }}">{{ $kpi['respLate'] }}</b><span>تجاوزت مهلة الاستجابة</span></div>
    <div class="stat"><span class="ico">🚨</span><b class="{{ $kpi['resLate'] ? 'txt-bad' : '' }}">{{ $kpi['resLate'] }}</b><span>تجاوزت مهلة الحل</span></div>
    <div class="stat"><span class="ico">⚡</span><b>{{ $kpi['avgResp'] !== null ? $kpi['avgResp'] . ' س' : '—' }}</b><span>متوسط أول رد (٣٠ يوماً)</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ $kpi['avgRes'] !== null ? $kpi['avgRes'] . ' س' : '—' }}</b><span>متوسط الحل (٣٠ يوماً)</span></div>
    <div class="stat"><span class="ico">🎯</span><b class="{{ ($kpi['slaPct'] ?? 100) < 80 ? 'txt-bad' : '' }}">{{ $kpi['slaPct'] !== null ? $kpi['slaPct'] . '٪' : '—' }}</b><span>التزام SLA ({{ $kpi['done30'] }} مغلقة)</span></div>
</div>

<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>التذكرة</th><th>العميل / القناة</th><th>الاستجابة</th><th>الحل</th><th>الحالة</th></tr></thead>
        <tbody>
        @forelse ($queue as $t)
            @php $s = $t->sla; @endphp
            <tr>
                <td><a href="{{ route('m.show', ['tickets', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->subject, 42) }}</a>
                    <div class="sub">{{ $t->priority ? $t->priority . ' · ' : '' }}SLA: {{ $s['policy'] }} · فُتحت {{ $t->created_at->diffForHumans() }}</div></td>
                <td>{{ \Illuminate\Support\Str::limit($t->customer, 20) ?: '—' }}<div class="sub">{{ $t->channel }}{{ $t->ext_id ? ' · #' . $t->ext_id : '' }}</div></td>
                <td class="acts">
                    @if (! $s['respPending'])<span class="bdg {{ $s['respLate'] ? 'bad' : 'ok' }}">{{ $s['respLate'] ? 'رُدّ متأخراً' : '✓ رُدّ في الوقت' }}</span>
                    @elseif ($s['respLate'])<span class="bdg bad">⏰ متأخرة {{ $s['respDue']->diffForHumans(null, true) }}</span>
                    @else<span class="bdg wn">تبقّى {{ now()->diffForHumans($s['respDue'], true) }}</span>@endif
                </td>
                <td class="acts">
                    @if ($s['resLate'])<span class="bdg bad">🚨 متأخرة {{ $s['resDue']->diffForHumans(null, true) }}</span>
                    @else<span class="bdg g">تبقّى {{ now()->diffForHumans($s['resDue'], true) }}</span>@endif
                </td>
                <td class="acts">@if ($t->status)<span class="bdg {{ hub_tone($t->status) }}">{{ $t->status }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty"><span class="big">🎉</span>لا تذاكر مفتوحة — الطابور نظيف</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
<div class="sub" style="margin-top:8px">💡 تذاكر المتاجر والتطبيقات: أنشئها عبر <span class="mono ltr">POST /api/v1/tickets</span> بحقل <span class="mono ltr">extId</span> لمعرّفها الخارجي و<span class="mono ltr">appId</span> لربطها بالتطبيق — تدخل الطابور بمؤقتاتها فوراً.</div>
@endsection
