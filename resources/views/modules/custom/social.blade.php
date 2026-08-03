@php
    $sDays = 90;
    $sSeries = hub_metric_series('social', $row->id, 'followers', $sDays);
    $sSpark = hub_metric_spark($sSeries, 40);
    $sGrow = hub_metric_growth('social', $row->id, 'followers', $sDays);
    $sFeed = hub_social_feed_state('social', $row->id);
    $sNow = hub_metric_latest('social', $row->id, 'followers') ?? (float) ($row->followers ?? 0);
    $sNum = fn ($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 1);
@endphp
<div class="card">
    <h3 class="cardtitle">📈 منحنى المتابعين <span class="sub">· آخر {{ $sDays }} يوماً</span></h3>

    <div class="cards" style="margin-bottom:10px">
        <div class="stat"><span class="ico">👥</span><b>{{ $sNum($sNow) }}</b><span>المتابعون الآن</span></div>
        <div class="stat"><span class="ico">{{ ($sGrow['delta'] ?? 0) >= 0 ? '📈' : '📉' }}</span>
            <b class="{{ ($sGrow['delta'] ?? 0) < 0 ? 'txt-bad' : '' }}">
                {{ $sGrow['delta'] === null ? '—' : (($sGrow['delta'] >= 0 ? '+' : '') . $sNum($sGrow['delta'])) }}</b>
            <span>النمو{{ $sGrow['pct'] !== null ? ' · ' . ($sGrow['pct'] >= 0 ? '+' : '') . $sGrow['pct'] . '٪' : '' }}</span></div>
        <div class="stat"><span class="ico">🔌</span><b class="{{ $sFeed['tone'] === 'bad' ? 'txt-bad' : '' }}">{{ $sFeed['label'] }}</b>
            <span>{{ $sFeed['at'] ? 'آخر قياس ' . $sFeed['at']->diffForHumans() : 'لا قياس مسجّل' }}</span></div>
    </div>

    @if (count($sSpark) > 1)
        <div style="display:flex;align-items:flex-end;gap:3px;height:86px">
            @foreach ($sSpark as $s)
                <div title="{{ $s['at']->format('Y-m-d H:i') }} — {{ $sNum($s['value']) }}"
                     style="flex:1;min-width:4px;height:100%;display:flex;align-items:flex-end">
                    <span style="display:block;width:100%;border-radius:3px 3px 0 0;background:var(--p);
                                 height:{{ max(6, $s['pct']) }}%"></span>
                </div>
            @endforeach
        </div>
        <div class="sub" style="display:flex;justify-content:space-between;margin-top:4px">
            <span>{{ $sSpark[0]['at']->format('Y-m-d') }} · {{ $sNum($sSpark[0]['value']) }}</span>
            <span>{{ end($sSpark)['at']->format('Y-m-d') }} · {{ $sNum(end($sSpark)['value']) }}</span>
        </div>
    @else
        <div class="sub" style="line-height:2">
            نقطةٌ واحدة أو لا شيء — المنحنى يحتاج قياسين على الأقل. كل حفظٍ للحساب يسجّل نقطة،
            والأمر المجدول يلتقط لقطةً يومية، والربط الآلي يدفعها لحظياً عبر
            <span class="mono ltr">POST /api/v1/metrics</span>
            (<a href="{{ route('integrations.guide') }}">دليل الربط</a>).
        </div>
    @endif

    @if (($row->goal ?? 0) > 0)
        @php $gp = min(100, (int) round($sNow * 100 / (float) $row->goal)); @endphp
        <div style="margin-top:12px">
            <div class="sub" style="margin-bottom:4px">نحو الهدف — {{ $sNum($sNow) }} من {{ $sNum($row->goal) }}</div>
            <div class="pbar"><span style="width:{{ $gp }}%"></span></div>
            <div class="sub" style="margin-top:4px">
                {{ $gp }}٪
                @if ($sGrow['delta'] > 0 && $sNow < (float) $row->goal)
                    @php
                        $perDay = $sGrow['delta'] / max(1, $sDays);
                        $eta = $perDay > 0 ? (int) ceil(((float) $row->goal - $sNow) / $perDay) : null;
                    @endphp
                    @if ($eta && $eta < 3650)· بهذه الوتيرة يُبلغ بعد نحو {{ number_format($eta) }} يوماً@endif
                @endif
            </div>
        </div>
    @endif

    <div class="sub" style="margin-top:10px"><a href="{{ route('social.index') }}">مركز السوشال ميديا الكامل ←</a></div>
</div>
