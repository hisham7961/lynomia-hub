@extends('layouts.app')
@section('title', 'مسار الجلسة الميدانية')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الميدان</span><span aria-hidden="true">‹</span><b>المسار</b></nav>
        <h2>🗺️ مسار {{ $empName }}</h2>
        <div class="sub">{{ substr((string) $session->field_day, 0, 10) }} —
            {{ (int) $session->point_count }} نقطة،
            {{ $session->distance_m ? number_format($session->distance_m / 1000, 2) . ' كم' : 'المسافة تُحسب عند الإنهاء' }}
            @if ($rawCount !== null)· نقاطٌ خام: {{ number_format($rawCount) }}@endif
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('field.sessions') }}">← الجلسات</a>
</div>

<div class="card">
    @if (count($line) >= 2)
        <div id="map" style="height:460px;border-radius:var(--r);overflow:hidden"></div>
        <div class="sub" style="margin-top:8px">مسارٌ مبسَّط (Ramer–Douglas–Peucker) — يُبقي شكلَ الطريق ويُسقط التراصّ. النقاطُ الخام تُقلَّم بسياسة الاحتفاظ.</div>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
        <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
        <script>
        (function () {
            var line = @json($line);
            if (!window.L || line.length < 2) return;
            var map = L.map('map');
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '© OpenStreetMap'
            }).addTo(map);
            var poly = L.polyline(line, { color: '#3E8FB0', weight: 4 }).addTo(map);
            L.circleMarker(line[0], { radius: 6, color: '#0E7C66' }).addTo(map).bindTooltip('البداية');
            L.circleMarker(line[line.length - 1], { radius: 6, color: '#B0568E' }).addTo(map).bindTooltip('النهاية');
            map.fitBounds(poly.getBounds(), { padding: [24, 24] });
        })();
        </script>
    @else
        <div class="empty">لا نقاطَ كافيةٌ لرسم المسار بعد — تظهر الخريطةُ حين تُجمَع نقطتان فأكثر.</div>
    @endif
</div>
@endsection
