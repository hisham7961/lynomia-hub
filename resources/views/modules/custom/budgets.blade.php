{{-- بطاقة «الميزانية مقابل الفعلي» — تتوقع $row (الميزانية). الأبعاد الفارغة لا تقيّد --}}
@php
    $ba = hub_budget_actual($row);
    $pct = $ba['pct'];
    $alert = (int) ($row->alert_pct ?: 0);
    $tone = $pct === null ? '' : ($pct >= 100 ? 'bad' : (($alert && $pct >= $alert) ? 'wn' : 'ok'));
    $cur = $row->currency ?: setting('app.currency', 'د.ك');
@endphp
<div class="card">
    <h3 class="cardtitle">📊 الميزانية مقابل الفعلي</h3>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px">
        <div><div class="sub">المخصص</div><b class="mono">{{ number_format($ba['amount'], 2) }} {{ $cur }}</b></div>
        <div><div class="sub">المستهلك فعلاً</div><b class="mono">{{ number_format($ba['spent'], 2) }} {{ $cur }}</b></div>
        <div><div class="sub">المتبقي</div>
            <b class="mono" style="{{ $ba['remain'] < 0 ? 'color:var(--bad, #c0392b)' : '' }}">{{ number_format($ba['remain'], 2) }} {{ $cur }}</b></div>
        @if ($pct !== null)
            <div><div class="sub">نسبة الاستهلاك</div><span class="bdg {{ $tone }}">{{ $pct }}٪</span></div>
        @endif
    </div>
    @if ($pct !== null)
        <div style="background:var(--brd);border-radius:8px;height:10px;overflow:hidden">
            <div style="height:100%;width:{{ min(100, max(0, $pct)) }}%;border-radius:8px;background:{{ $pct >= 100 ? 'var(--bad, #c0392b)' : (($alert && $pct >= $alert) ? 'var(--wn, #e67e22)' : 'var(--pr)') }}"></div>
        </div>
        @if ($alert)
            <div class="sub" style="margin-top:6px">حد التنبيه: {{ $alert }}٪ — {{ $pct >= $alert ? 'بلغته الميزانية ⚠️' : 'لم يُبلغ بعد' }}</div>
        @endif
    @else
        <div class="sub">لا مبلغ مخصص لهذه الميزانية — أدخل «المبلغ» لتعمل المقارنة.</div>
    @endif
</div>
