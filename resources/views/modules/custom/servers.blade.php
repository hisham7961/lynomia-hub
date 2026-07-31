@include('partials.livemon', ['module' => 'servers', 'row' => $row])

{{-- الكلفة والمواصفات: السيرفر بندٌ في الميزانية لا سطرٌ في دفتر --}}
@php
    $sCost = (float) ($row->cost_month ?? 0);
    $sPatch = $row->last_patch ? \Illuminate\Support\Carbon::parse($row->last_patch) : null;
    $sPatchDays = $sPatch ? (int) $sPatch->diffInDays(now()) : null;
@endphp
@if ($sCost > 0 || $row->cores || $row->ram_gb || $sPatch || $row->backup_policy)
<div class="card">
    <h3 class="cardtitle">🖥️ الطاقة والكلفة والصيانة</h3>
    <div class="cards">
        @if ($row->cores || $row->ram_gb || $row->disk_gb)
            <div class="stat"><span class="ico">⚙️</span>
                <b>{{ $row->cores ?: '—' }}<small> نواة</small></b>
                <span>{{ $row->ram_gb ? rtrim(rtrim(number_format($row->ram_gb, 1), '0'), '.') . ' GB ذاكرة' : '' }}
                      {{ $row->disk_gb ? ' · ' . rtrim(rtrim(number_format($row->disk_gb, 1), '0'), '.') . ' GB قرص' : '' }}</span></div>
        @endif
        @if ($sCost > 0)
            <div class="stat"><span class="ico">💰</span><b>{{ number_format($sCost, 2) }}</b>
                <span>شهرياً · {{ number_format($sCost * 12, 2) }} سنوياً</span></div>
        @endif
        @if ($sPatch)
            <div class="stat"><span class="ico">🛡️</span>
                <b class="{{ $sPatchDays > 90 ? 'txt-bad' : '' }}">{{ $sPatchDays }}<small> يوماً</small></b>
                <span>منذ آخر ترقيع أمني</span></div>
        @endif
    </div>
    @if ($sPatchDays !== null && $sPatchDays > 90)
        <div class="sub" style="margin-top:8px;color:var(--bad)">
            ⚠️ مضى أكثر من ٩٠ يوماً بلا ترقيع أمني — أكثر الاختراقات تدخل من ثغرةٍ معروفةٍ لها رقعة.
        </div>
    @elseif (! $sPatch)
        <div class="sub" style="margin-top:8px">لم يُسجَّل ترقيعٌ أمني بعد — سجّل «آخر ترقيع أمني» ليُقاس عمر الرقع.</div>
    @endif
    @if ($row->backup_policy)
        <div class="sub" style="margin-top:6px">💾 النسخ الاحتياطي: {{ $row->backup_policy }}</div>
    @endif
</div>
@endif
