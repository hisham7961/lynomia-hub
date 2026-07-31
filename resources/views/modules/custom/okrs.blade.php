{{-- النتائج الرئيسية للهدف — يتوقع $row (الهدف). التقدّم محسوب لا مكتوب --}}
@php $okr = hub_okr_progress($row->id, true); @endphp
<div class="card">
    <h3 class="cardtitle">🎯 النتائج الرئيسية (KR)</h3>
    @if (! $okr)
        <div class="sub">
            لا نتائج رئيسية بعد — هدفٌ بلا نتائج قابلة للقياس ليس OKR.
            @if (hub_can(auth()->user(), 'krs', 'a'))
                <a class="btn ghost sm" style="margin-inline-start:6px"
                   href="{{ route('m.create', ['krs', 'objectiveId' => $row->id]) }}">＋ نتيجة رئيسية</a>
            @endif
        </div>
    @else
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px">
            <div><div class="sub">التقدّم المحسوب (متوسط موزون)</div>
                <b class="mono" style="font-size:20px">{{ $okr['pct'] === null ? '—' : $okr['pct'] . '٪' }}</b></div>
            <div><div class="sub">النتائج</div><b class="mono">{{ $okr['measured'] }} مقيسة من {{ $okr['count'] }}</b></div>
            @if ($okr['pct'] !== null && (int) $row->progress !== $okr['pct'])
                <div><div class="sub">الرقم اليدوي في السجل</div>
                    <span class="bdg wn">{{ (int) $row->progress }}٪ — يخالف المحسوب</span></div>
            @endif
        </div>
        @if ($okr['pct'] !== null)
            <div style="background:var(--brd);border-radius:8px;height:10px;overflow:hidden;margin-bottom:10px">
                <div style="height:100%;width:{{ $okr['pct'] }}%;border-radius:8px;background:{{ $okr['pct'] >= 70 ? 'var(--ok, #27ae60)' : ($okr['pct'] >= 40 ? 'var(--pr)' : 'var(--bad, #c0392b)') }}"></div>
            </div>
        @endif
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>النتيجة</th><th>الحالية</th><th>المستهدفة</th><th>الإنجاز</th><th>الحالة</th></tr></thead>
            <tbody>
            @foreach ($okr['krs'] as $kr)
                <tr>
                    <td><a href="{{ route('m.show', ['krs', $kr['id']]) }}">{{ $kr['title'] }}</a>
                        @if ($kr['auto'])<span class="bdg ok" title="القيمة تُحسب من مؤشر مرتبط">⚙️ آلي</span>@endif</td>
                    <td class="mono">{{ $kr['current'] !== null ? rtrim(rtrim(number_format((float) $kr['current'], 2), '0'), '.') : '—' }} {{ $kr['unit'] }}</td>
                    <td class="mono">{{ $kr['target'] !== null ? rtrim(rtrim(number_format((float) $kr['target'], 2), '0'), '.') : '—' }}</td>
                    <td class="mono">{{ $kr['pct'] === null ? '—' : $kr['pct'] . '٪' }}</td>
                    <td>@if ($kr['status'])<span class="bdg {{ hub_tone($kr['status']) }}">{{ $kr['status'] }}</span>@else<span class="sub">—</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        @if (hub_can(auth()->user(), 'krs', 'a'))
            <a class="btn ghost sm" style="margin-top:8px"
               href="{{ route('m.create', ['krs', 'objectiveId' => $row->id]) }}">＋ نتيجة رئيسية</a>
        @endif
    @endif
</div>
