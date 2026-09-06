{{-- (WP-2.4 · critic #27) أداء المسارات: التجميعُ في القاعدة (GROUP BY route, method
     داخل المدى) وقراءةُ hist لصفوف الصفحة المعروضة وحدها — يتوقع $rp من
     OpsController::routesPerf: range · rows (paginator) · sort/dir · reg. --}}
@php $rpRows = $rp['rows']; $reg = $rp['reg']; @endphp
<h3 class="secttl">🛣️ أداء المسارات <span class="sub">· مجمَّعةً في القاعدة — النسبُ المئوية (p50/p95/p99) تقريبية بحدّ خطأ ±7.5٪</span></h3>

@include('partials.timerange', ['range' => $rp['range']])

@if ($reg)
    <div class="kids">
        <div class="card kid">
            <h3>📉 انحدار الأداء <span class="sub">· نافذة {{ number_format($reg['hours']) }} ساعة مقابل مثلها قبلها</span></h3>
            @if (! $reg['enough'])
                <div class="sub">لا توجد بيانات تاريخية كافية — العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }} طلباً والحدّ الأدنى {{ number_format($reg['min_n']) }}.</div>
            @elseif ($reg['perf_flag'])
                <div><span class="bdg bad">⚠️ تراجع الأداء</span>
                    المتوسط الحالي <b>{{ number_format($reg['cur']['avg']) }}ms</b> مقابل <b>{{ number_format($reg['prev']['avg']) }}ms</b>
                    ({{ $reg['perf']['pct'] > 0 ? '+' : '' }}{{ number_format($reg['perf']['pct']) }}٪ · العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }})</div>
            @else
                <div><span class="bdg ok">✓ ضمن المعتاد</span>
                    المتوسط الحالي <b>{{ number_format($reg['cur']['avg']) }}ms</b> مقابل <b>{{ number_format($reg['prev']['avg']) }}ms</b>
                    (العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }})</div>
            @endif
            <div class="sub" style="margin-top:6px">عتبة الانحدار {{ number_format($reg['thr']) }}٪ — تُضبط من الإعدادات.</div>
        </div>

        <div class="card kid">
            <h3>🚨 انحدار الأخطاء <span class="sub">· نسبة 4xx/5xx من الحاويات + أنواع مركز الأخطاء دليلاً</span></h3>
            @php $rpPct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '٪'; @endphp
            @if (! $reg['enough'])
                <div class="sub">لا توجد بيانات تاريخية كافية — العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }} طلباً والحدّ الأدنى {{ number_format($reg['min_n']) }}.</div>
            @elseif ($reg['err_flag'])
                <div><span class="bdg bad">⚠️ ارتفعت الأخطاء</span>
                    النسبة الحالية <b>{{ $rpPct($reg['cur']['rate']) }}</b> مقابل <b>{{ $rpPct($reg['prev']['rate']) }}</b>
                    (العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }})</div>
            @else
                <div><span class="bdg ok">✓ ضمن المعتاد</span>
                    النسبة الحالية <b>{{ $rpPct($reg['cur']['rate']) }}</b> مقابل <b>{{ $rpPct($reg['prev']['rate']) }}</b>
                    (العيّنة {{ number_format($reg['cur']['n']) }} / {{ number_format($reg['prev']['n']) }})</div>
            @endif
            @if ($reg['kinds'])
                {{-- (WP-2.7 · critic #14) .tblwrap لكل جداول مركز التشغيل --}}
                <div class="tblwrap"><table class="mini" style="margin-top:6px">
                    <thead><tr><th scope="col">النوع</th><th scope="col">النافذة الحالية</th><th scope="col">السابقة</th></tr></thead>
                    <tbody>
                    @foreach ($reg['kinds'] as $rpKind => $rpC)
                        <tr><td><bdi class="mono ltr">{{ $rpKind }}</bdi></td>
                            <td class="mono"><b>{{ number_format($rpC['cur'] ?? 0) }}</b></td>
                            <td class="mono sub">{{ number_format($rpC['prev'] ?? 0) }}</td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">🧭 المسارات داخل المدى <span class="sub">· صفٌّ لكل (مسار × فعل) — المعرّفات مطبَّعة {id}/{n}</span></h3>
    @if (! $rpRows || ! $rpRows->count())
        @include('partials.empty', ['text' => 'لا توجد بيانات تاريخية كافية', 'icon' => '🛣️'])
    @else
        <div class="tblwrap">
            <table class="mini">
                <thead><tr>
                    @include('partials.cc.th', ['col' => 'route', 'label' => 'المسار', 'default' => 'hits'])
                    @include('partials.cc.th', ['col' => 'hits', 'label' => 'الطلبات', 'default' => 'hits'])
                    @include('partials.cc.th', ['col' => 'avg', 'label' => 'المتوسط', 'default' => 'hits'])
                    <th scope="col">p50 <span class="sub">تقريبية</span></th>
                    <th scope="col">p95 <span class="sub">تقريبية</span></th>
                    <th scope="col">p99 <span class="sub">تقريبية</span></th>
                    @include('partials.cc.th', ['col' => 'max', 'label' => 'الأقصى', 'default' => 'hits'])
                    @include('partials.cc.th', ['col' => 'err4', 'label' => '4xx', 'default' => 'hits'])
                    @include('partials.cc.th', ['col' => 'err5', 'label' => '5xx', 'default' => 'hits'])
                    @include('partials.cc.th', ['col' => 'slow', 'label' => 'بطيئة', 'default' => 'hits'])
                </tr></thead>
                <tbody>
                @foreach ($rpRows as $rpRow)
                    @php $rpMs = fn ($v) => $v === null ? '—' : number_format((float) $v) . 'ms'; @endphp
                    <tr>
                        <td><bdi class="mono ltr" style="word-break:break-all">{{ $rpRow->route }}</bdi>
                            <span class="sub mono ltr">{{ $rpRow->method }}</span></td>
                        <td class="mono"><b>{{ number_format($rpRow->hits) }}</b></td>
                        <td class="mono">{{ $rpMs($rpRow->avg) }}</td>
                        <td class="mono sub">{{ $rpMs($rpRow->p50) }}</td>
                        <td class="mono">{{ $rpMs($rpRow->p95) }}</td>
                        <td class="mono sub">{{ $rpMs($rpRow->p99) }}</td>
                        <td class="mono">{{ $rpMs($rpRow->max_ms) }}</td>
                        <td>@if ((int) $rpRow->err4 > 0)<span class="bdg wn">{{ number_format($rpRow->err4) }}</span>@else <span class="sub">٠</span>@endif</td>
                        <td>@if ((int) $rpRow->err5 > 0)<span class="bdg bad">{{ number_format($rpRow->err5) }}</span>@else <span class="sub">٠</span>@endif</td>
                        <td>@if ((int) $rpRow->slow > 0)<span class="bdg wn">{{ number_format($rpRow->slow) }}</span>@else <span class="sub">٠</span>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $rpRows->links('partials.pagination') }}
    @endif
</div>
