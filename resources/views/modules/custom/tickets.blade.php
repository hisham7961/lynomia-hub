{{-- بطاقةُ SLA على التذكرة (الجولة 1 · F33): الخرقُ كان محسوباً في hub_sla ولا يظهر لأحد --}}
@php $tkSla = hub_sla($row); @endphp
<div class="card" style="margin-bottom:12px{{ ($tkSla['resLate'] && $tkSla['resPending']) ? ';border-inline-start:3px solid var(--bad,#b91c1c)' : '' }}">
    <h3>⏱️ اتفاقية مستوى الخدمة <span class="sub" style="font-weight:400">({{ $tkSla['policy'] }})</span></h3>
    <div style="display:flex;gap:18px;flex-wrap:wrap">
        <div>
            <div class="sub">الاستجابة الأولى</div>
            @if ($tkSla['respPending'])
                <span class="bdg {{ $tkSla['respLate'] ? 'bad' : 'wn' }}">{{ $tkSla['respLate'] ? 'متجاوزة — لا ردّ بعد' : 'بانتظار أول ردّ' }}</span>
                <div class="sub">الموعد: {{ $tkSla['respDue']->format('Y-m-d H:i') }}</div>
            @else
                <span class="bdg {{ $tkSla['respLate'] ? 'wn' : 'ok' }}">{{ $tkSla['respLate'] ? 'رُدّ متأخراً' : 'ضمن المهلة' }}</span>
                <div class="sub">{{ $tkSla['respAt']->format('Y-m-d H:i') }}</div>
            @endif
        </div>
        <div>
            <div class="sub">الحلّ</div>
            @if ($tkSla['resPending'])
                @if ($tkSla['resLate'])
                    <span class="bdg bad">متجاوزة الحلّ منذ {{ $tkSla['resDue']->diffInDays(now()) }} يوماً</span>
                @else
                    <span class="bdg g">الموعد {{ $tkSla['resDue']->format('Y-m-d H:i') }}</span>
                @endif
            @else
                <span class="bdg {{ $tkSla['resLate'] ? 'wn' : 'ok' }}">{{ $tkSla['resLate'] ? 'حُلّت متأخرة' : 'حُلّت ضمن المهلة' }}</span>
            @endif
        </div>
        @if (is_array($row->meta ?? null) && ! empty($row->meta['sla_escalated_at']))
            <div><div class="sub">التصعيد</div><span class="bdg wn">📣 صُعّدت للمسنَد إليه ومديره — {{ substr((string) $row->meta['sla_escalated_at'], 0, 16) }}</span></div>
        @endif
    </div>
</div>
