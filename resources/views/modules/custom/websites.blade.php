@include('partials.livemon', ['module' => 'websites', 'row' => $row])

{{-- حركة الموقع: خانة GA كانت تُملأ فلا يصل زائرٌ واحد --}}
@php
    $wMetrics = ['visitors' => 'الزوّار', 'sessions' => 'الجلسات', 'pageviews' => 'مشاهدات الصفحات',
                 'conversions' => 'التحويلات', 'bounce' => 'الارتداد ٪', 'signups' => 'التسجيلات'];
    $wHas = [];
    foreach ($wMetrics as $mk => $ml) {
        $s = hub_metric_series('websites', $row->id, $mk, 90);
        if ($s) $wHas[$mk] = ['label' => $ml, 'series' => $s, 'spark' => hub_metric_spark($s, 40),
                              'now' => (float) end($s)['value'],
                              'growth' => hub_metric_growth('websites', $row->id, $mk, 30)];
    }
    $wNum = fn ($v) => number_format((float) $v, ((float) $v == (int) $v) ? 0 : 1);
@endphp
<div class="card">
    <h3 class="cardtitle">📊 حركة الموقع
        @if ($row->ga4_id)<span class="bdg g mono ltr">{{ $row->ga4_id }}</span>@endif
    </h3>

    @if ($wHas)
        <div class="cards" style="margin-bottom:10px">
            @foreach ($wHas as $mk => $w)
                <div class="stat"><span class="ico">{{ ['visitors' => '👥', 'sessions' => '🔁', 'pageviews' => '📄',
                                                       'conversions' => '🎯', 'bounce' => '↩️', 'signups' => '✍️'][$mk] ?? '📈' }}</span>
                    <b>{{ $wNum($w['now']) }}</b>
                    <span>{{ $w['label'] }}@if ($w['growth']['delta'] !== null) ·
                        <span class="{{ $w['growth']['delta'] < 0 ? 'txt-bad' : '' }}">{{ $w['growth']['delta'] >= 0 ? '+' : '' }}{{ $wNum($w['growth']['delta']) }}</span>
                        خلال ٣٠ يوماً @endif</span></div>
            @endforeach
        </div>

        @foreach ($wHas as $w)
            @if (count($w['spark']) > 1)
                <div class="sub" style="margin:8px 0 4px">{{ $w['label'] }}:</div>
                <div style="display:flex;align-items:flex-end;gap:3px;height:52px">
                    @foreach ($w['spark'] as $s)
                        <div title="{{ $s['at']->format('Y-m-d') }} — {{ $wNum($s['value']) }}"
                             style="flex:1;min-width:3px;height:100%;display:flex;align-items:flex-end">
                            <span style="display:block;width:100%;border-radius:2px 2px 0 0;background:var(--p);height:{{ max(6, $s['pct']) }}%"></span>
                        </div>
                    @endforeach
                </div>
            @endif
            @break
        @endforeach
    @else
        <div class="sub" style="line-height:2">
            لا أرقام حركةٍ بعد. حقل «Google Analytics» وحده لا يجلب زائراً — يحتاج من **يقرأ** GA4
            ويدفع الأرقام إلينا. الوصفة في سطرين:
            <br>① في n8n: عقدة <b>Google Analytics</b> (تقرير GA4 يومي) بالمعرّف
            <span class="mono ltr">{{ $row->ga4_id ?: 'G-XXXXXXX' }}</span>.
            <br>② ثم عقدة <b>HTTP Request</b> إلى
            <span class="mono ltr">POST /api/v1/metrics</span> بمقاييس
            <span class="mono ltr">visitors, sessions, pageviews, conversions</span>
            و<span class="mono ltr">record_id = {{ $row->id }}</span>.
            <br>التفصيل الكامل في <a href="{{ route('integrations.guide') }}">دليل الربط</a>.
        </div>
    @endif

    @php
        $wLinks = array_filter([
            'admin_url' => '🛠 لوحة الإدارة', 'staging' => '🧪 نسخة الاختبار', 'git' => '🌿 المستودع',
            'sitemap' => '🗺️ خريطة الموقع', 'gsc' => '🔎 Search Console',
        ], fn ($l, $c) => ! empty($row->{$c}), ARRAY_FILTER_USE_BOTH);
    @endphp
    @if ($wLinks || $row->lighthouse)
        <div class="crow" style="margin-top:10px">
            @if ($row->lighthouse)
                <span class="chip">⚡ أداء Lighthouse:
                    <b class="{{ $row->lighthouse < 50 ? 'txt-bad' : '' }}">{{ $row->lighthouse }}/100</b></span>
            @endif
            @foreach ($wLinks as $col => $label)
                @if (str_starts_with((string) $row->{$col}, 'http'))
                    <a class="btn ghost xs" href="{{ hub_safe_url($row->{$col}) }}" target="_blank" rel="noopener">{{ $label }}</a>
                @else
                    <span class="chip">{{ $label }}: {{ \Illuminate\Support\Str::limit($row->{$col}, 30) }}</span>
                @endif
            @endforeach
        </div>
    @endif
</div>
