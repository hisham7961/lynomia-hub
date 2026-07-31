@php
    $pe = hub_social_engagement($row);
    $pNum = fn ($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 1);
    $pMetrics = ['likes' => 'إعجابات', 'comments' => 'تعليقات', 'shares' => 'مشاركات',
                 'saves' => 'حفظ', 'views' => 'مشاهدات', 'reach' => 'وصول', 'clicks' => 'نقرات'];
    $pSeries = [];
    foreach ($pMetrics as $mk => $ml) {
        $s = hub_metric_series('posts', $row->id, $mk, 90);
        if (count($s) > 1) $pSeries[$ml] = hub_metric_spark($s, 30);
    }
    $pFeed = hub_social_feed_state('posts', $row->id, 'likes');
@endphp
<div class="card">
    <h3 class="cardtitle">💥 أداء المنشور <span class="sub">· محسوباً لا مُدخَلاً</span></h3>

    <div class="cards" style="margin-bottom:10px">
        <div class="stat"><span class="ico">💥</span><b>{{ $pe['rate'] !== null ? $pe['rate'] . '٪' : '—' }}</b>
            <span>معدّل التفاعل{{ $pe['baseLabel'] ? ' · من ' . $pe['baseLabel'] : '' }}</span></div>
        <div class="stat"><span class="ico">🤝</span><b>{{ $pNum($pe['interactions']) }}</b>
            <span>تفاعلاً (إعجاب + تعليق + مشاركة + حفظ)</span></div>
        @if ($pe['base'])
            <div class="stat"><span class="ico">📡</span><b>{{ $pNum($pe['base']) }}</b>
                <span>قاعدة الحساب: {{ $pe['baseLabel'] }}</span></div>
        @endif
        @if ($pe['cpe'] !== null)
            <div class="stat"><span class="ico">💸</span><b>{{ $pNum($pe['cpe']) }}</b>
                <span>كلفة التفاعل الواحد</span></div>
        @endif
        <div class="stat"><span class="ico">🔌</span><b class="{{ $pFeed['tone'] === 'bad' ? 'txt-bad' : '' }}">{{ $pFeed['label'] }}</b>
            <span>{{ $pFeed['at'] ? 'آخر قياس ' . $pFeed['at']->diffForHumans() : 'لا قياس مسجّل' }}</span></div>
    </div>

    @if ($pe['rate'] === null)
        <div class="sub" style="line-height:2">
            لا قاعدةَ محسوبةً بعد: املأ الوصول أو الظهور أو المشاهدات — أو اربط الحساب
            بمتابعين — ليُحسب المعدّل. والأرقام تصل آلياً عبر
            <span class="mono ltr">POST /api/v1/metrics</span>
            (<a href="{{ route('integrations.guide') }}">دليل الربط</a>).
        </div>
    @endif

    @if ($pSeries)
        <div class="sub" style="margin:8px 0 6px">تطوّر الأرقام منذ النشر:</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>المقياس</th><th>المنحنى</th><th>الآن</th></tr></thead>
            <tbody>
            @foreach ($pSeries as $label => $sp)
                <tr>
                    <td>{{ $label }}</td>
                    <td><span style="display:inline-flex;align-items:flex-end;gap:2px;height:24px">
                        @foreach ($sp as $s)
                            <span title="{{ $s['at']->format('Y-m-d') }} — {{ $pNum($s['value']) }}"
                                  style="display:block;width:4px;border-radius:2px;background:var(--p);height:{{ max(8, $s['pct']) }}%"></span>
                        @endforeach
                    </span></td>
                    <td class="mono"><b>{{ $pNum(end($sp)['value']) }}</b></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif

    <div class="sub" style="margin-top:10px"><a href="{{ route('social.index') }}">مركز السوشال ميديا ←</a></div>
</div>
