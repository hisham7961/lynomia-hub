{{-- درجة المخاطرة على السجل — يتوقع $row. تظهر للخطر المُقيَّم باحتمالٍ وأثر --}}
@php $rs = hub_risk_score($row->likelihood ?? null, $row->severity ?? null); @endphp
@if ($rs || (string) ($row->kind ?? '') === 'خطر')
    <div class="card">
        <h3 class="cardtitle">⚠️ تقييم المخاطرة</h3>
        @if ($rs)
            <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
                <div><div class="sub">الاحتمال</div><b>{{ $row->likelihood }}</b> <span class="sub">({{ $rs['l'] }}/٤)</span></div>
                <div><div class="sub">الأثر</div><b>{{ $row->severity }}</b> <span class="sub">({{ $rs['s'] }}/٤)</span></div>
                <div><div class="sub">الدرجة (احتمال × أثر)</div>
                    <span class="bdg {{ $rs['tone'] }}" style="font-size:15px">{{ $rs['score'] }}/١٦ — {{ $rs['band'] }}</span></div>
            </div>
        @else
            <div class="sub">سجّل «احتمال الوقوع» و«الخطورة» لتُحتسب درجة المخاطرة — الأثر وحده لا يصنع تقييماً.</div>
        @endif
    </div>
@endif
