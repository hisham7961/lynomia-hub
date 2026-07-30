{{-- بطاقة SLA على صفحة التذكرة — يتوقع: $row (Ticket) --}}
@php $s = hub_sla($row); @endphp
<div class="card">
    <h3>⏱ اتفاقية مستوى الخدمة (SLA) <span class="bdg g">{{ $s['policy'] }}</span></h3>
    <div class="cards" style="margin-bottom:0">
        <div class="stat">
            <span class="ico">⚡</span>
            @if (! $s['respPending'])
                <b class="{{ $s['respLate'] ? 'txt-bad' : '' }}">{{ $s['respLate'] ? 'متأخر' : 'في الوقت ✓' }}</b>
                <span>أول رد: {{ $s['respAt']->diffForHumans($row->created_at, true) }} من الفتح</span>
            @elseif ($s['respLate'])
                <b class="txt-bad">⏰ متجاوزة</b><span>كان الموعد {{ $s['respDue']->diffForHumans() }} — ردّ الآن بتعليق</span>
            @else
                <b>{{ now()->diffForHumans($s['respDue'], true) }}</b><span>متبقٍ للرد الأول (بتعليق أدناه)</span>
            @endif
        </div>
        <div class="stat">
            <span class="ico">🏁</span>
            @if (! $s['resPending'])
                <b class="{{ $s['resLate'] ? 'txt-bad' : '' }}">{{ $s['resLate'] ? 'حُلّت متأخرة' : 'حُلّت في الوقت ✓' }}</b>
                <span>زمن الحل: {{ $s['resAt']->diffForHumans($row->created_at, true) }}</span>
            @elseif ($s['resLate'])
                <b class="txt-bad">🚨 متجاوزة</b><span>كان موعد الحل {{ $s['resDue']->diffForHumans() }}</span>
            @else
                <b>{{ now()->diffForHumans($s['resDue'], true) }}</b><span>متبقٍ للحل (الإغلاق يوقف العداد)</span>
            @endif
        </div>
    </div>
</div>
