{{-- الـAPI والقدرات (§26–31): معلوماتُ العقد + مُستكشِفُ المسارات + سجلُّ القدرات
     + المزامنة/التغطية + OpenAPI — كلُّه من MobileOpenApi الحيّ (لا سجلَّ ثانٍ، لا سرّ). --}}
@php
    $areas = $caps['areas'] ?? [];
    $sync = $caps['sync'] ?? [];
    $total = collect($areas)->sum(fn ($a) => $a['count'] ?? 0);
    $base = fn (array $q = []) => route('mobileplatform.index', array_merge(['tab' => 'api'], $q));
    $shown = $areaFilter !== '' ? array_intersect_key($areas, [$areaFilter => true]) : $areas;
@endphp

@include('partials.cc.kpis', ['items' => [
    ['label' => 'مسارات الـMobile API', 'value' => number_format($api['route_count']), 'tone' => 'ok',
        'sub' => $total . ' نقطةٌ في ' . count($areas) . ' مجال'],
    ['label' => 'نسخةُ العقد', 'value' => 'v' . $api['version'], 'sub' => $api['namespace']],
    ['label' => 'أصنافُ المزامنة', 'value' => count($sync['classes'] ?? []),
        'sub' => 'افتراضيّاً ' . ($sync['default_class'] ?? '—')],
    ['label' => 'OpenAPI', 'value' => 'حيّة', 'tone' => 'ok', 'sub' => 'مُولَّدة لا مُحرَّرة', 'url' => $api['openapi_url']],
]])

{{-- عقدُ الـAPI --}}
<div class="card kid wide">
    <h3>📡 عقدُ الـMobile API</h3>
    <table class="mini">
        <tr><td>الفضاء</td><td class="mono">{{ $api['namespace'] }}</td></tr>
        <tr><td>النسخة</td><td class="mono">v{{ $api['version'] }}</td></tr>
        <tr><td>المصادقة</td><td>{{ $api['auth'] }}</td></tr>
        <tr><td>مُعرّفُ الطلب</td><td class="sub">{{ $api['request_id'] }}</td></tr>
        <tr><td>مواصفةُ OpenAPI</td><td class="acts">
            <a class="btn ghost xs" href="{{ $api['openapi_url'] }}" target="_blank" rel="noopener">عرض ↗</a>
            <span class="sub">حيّةٌ مُولَّدةٌ من المسارات ({{ $caps['openapi']['generator'] ?? '' }})</span>
        </td></tr>
    </table>
</div>

{{-- مُستكشِفُ المسارات — للقراءة فقط، مجموعٌ بالمجال، مُرشَّحٌ بالمجال/المصادقة --}}
<div class="card kid wide">
    <h3>🧭 مُستكشِفُ المسارات <span class="sub">({{ $total }} نقطة)</span></h3>
    <div class="toolbar" style="gap:4px;flex-wrap:wrap;margin-bottom:8px">
        <a class="btn {{ $areaFilter === '' ? 'p' : 'ghost' }} xs" href="{{ $base(['auth' => $authFilter]) }}">كلُّ المجالات</a>
        @foreach ($areas as $key => $a)
            <a class="btn {{ $areaFilter === $key ? 'p' : 'ghost' }} xs" href="{{ $base(['area' => $key, 'auth' => $authFilter]) }}">{{ $key }} <span class="sub">{{ $a['count'] }}</span></a>
        @endforeach
    </div>
    <div class="toolbar" style="gap:4px;margin-bottom:10px">
        <span class="sub">المصادقة:</span>
        <a class="btn {{ $authFilter === '' ? 'p' : 'ghost' }} xs" href="{{ $base(['area' => $areaFilter]) }}">الكل</a>
        <a class="btn {{ $authFilter === 'mobile.session' ? 'p' : 'ghost' }} xs" href="{{ $base(['area' => $areaFilter, 'auth' => 'mobile.session']) }}">جلسةٌ مطلوبة</a>
        <a class="btn {{ $authFilter === 'public' ? 'p' : 'ghost' }} xs" href="{{ $base(['area' => $areaFilter, 'auth' => 'public']) }}">عامّة</a>
    </div>

    @foreach ($shown as $key => $a)
        @php $eps = $authFilter !== '' ? array_filter($a['endpoints'], fn ($e) => $e['auth'] === $authFilter) : $a['endpoints']; @endphp
        @if (! empty($eps))
            <div class="card kid">
                <h3>{{ $key }} <span class="sub">({{ count($eps) }})</span></h3>
                <table class="mini">
                    <tr><th>الطريقة</th><th>المسار</th><th>الاسم</th><th>المصادقة</th></tr>
                    @foreach ($eps as $e)
                        <tr>
                            <td><span class="bdg i">{{ $e['method'] }}</span></td>
                            <td class="mono sub">{{ $e['path'] }}</td>
                            <td class="mono sub">{{ $e['name'] }}</td>
                            <td>@if ($e['auth'] === 'mobile.session')<span class="bdg ok">جلسة</span>@else<span class="bdg g">عامّة</span>@endif</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    @endforeach
</div>

{{-- المزامنة دون اتصال + تغطيةُ التصنيف (§30/§31) --}}
<div class="card kid wide">
    <h3>🔄 المزامنة دون اتّصال — التغطية</h3>
    <p class="sub">النقطة <span class="mono">{{ $sync['endpoint'] ?? '—' }}</span> — التصنيفُ الافتراضيّ <span class="mono">{{ $sync['default_class'] ?? '—' }}</span>.
        كلُّ وحدةٍ تُصنَّف من <span class="mono">hub_sync_class</span> فتُحسَم قابليّتُها للتخزين دون اتّصال.</p>
    <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(200px,100%),1fr))">
        @foreach (($sync['coverage'] ?? []) as $cls => $n)
            <div class="stat"><span class="mono">{{ $cls }}</span><b>{{ number_format($n) }}</b><span>وحدة</span></div>
        @endforeach
    </div>
    <p class="sub" style="margin-top:8px">صفحةُ المزامنة: افتراضيّاً {{ $sync['page']['default_limit'] ?? '—' }} وأقصاها {{ $sync['page']['max_limit'] ?? '—' }} صفّاً (keyset cursor + tombstones).</p>
</div>

{{-- سجلُّ القدرات: المصادقة/الأكواد/التزامن/عدمُ التكرار --}}
<div class="card kid wide">
    <h3>🧩 سجلُّ القدرات</h3>
    <table class="mini">
        <tr><td>عقدُ المصادقة</td><td>{{ $caps['auth']['type'] ?? '—' }} · MFA: {{ implode('، ', (array) ($caps['auth']['mfa']['methods'] ?? [])) }} · Step-Up: {{ implode('، ', (array) ($caps['auth']['step_up']['methods'] ?? [])) }}</td></tr>
        <tr><td>أكوادُ الخطأ</td><td><span class="bdg i">{{ count($caps['error_codes']['reused'] ?? []) }} مُعادٌ استعمالُه</span> <span class="bdg i">{{ count($caps['error_codes']['mobile_added'] ?? []) }} خاصٌّ بالجوال</span> <span class="sub">موحَّدةٌ مع /api/v1</span></td></tr>
        <tr><td>التزامن (Optimistic Lock)</td><td class="sub">{{ $caps['concurrency']['optimistic_lock'] ?? '—' }}</td></tr>
        <tr><td>عدمُ التكرار</td><td class="sub">ترويسة {{ $caps['idempotency']['header'] ?? '—' }} — مالكُها: {{ $caps['idempotency']['owner'] ?? '—' }}</td></tr>
        <tr><td>الرابطُ العميق</td><td class="sub">{{ $caps['deep_link']['source_of_truth'] ?? '—' }}</td></tr>
    </table>
</div>

{{-- حالاتُ «غير مُهيّأ» الصادقة (§57) — حضورٌ لا قيمة --}}
<div class="card kid wide">
    <h3>🚦 قدراتٌ تعتمد ضبطاً خارجيّاً</h3>
    <table class="mini">
        <tr><th>القدرة</th><th>الحالة</th><th>السلوكُ عند الغياب</th></tr>
        <tr>
            <td>الدفع (FCM)</td>
            <td><span class="bdg {{ ($caps['not_configured']['push_fcm']['status'] ?? '') === 'CONFIGURED' ? 'ok' : 'g' }}">{{ $caps['not_configured']['push_fcm']['status'] ?? '—' }}</span></td>
            <td class="sub">{{ $caps['not_configured']['push_fcm']['behavior_when_absent'] ?? '—' }}</td>
        </tr>
        <tr>
            <td>APNs المباشر</td>
            <td><span class="bdg g">{{ $caps['not_configured']['push_apns']['status'] ?? '—' }}</span></td>
            <td class="sub">{{ $caps['not_configured']['push_apns']['note'] ?? '—' }}</td>
        </tr>
    </table>
    <p class="sub" style="margin-top:8px">لا سرَّ يُعرَض هنا — حضورٌ وحالةٌ فقط (spec §Security).</p>
</div>
