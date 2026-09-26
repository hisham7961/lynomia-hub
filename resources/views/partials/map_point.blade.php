{{--
  موقعُ نقطةٍ على الخريطة بحسب `maps.tiles` (بند الدَّين #13 · FE-02 · App\Support\Platform\Maps):
    osm  → رابطُ «افتح على الخريطة» الخارجيّ كما كان.
    self → خريطةٌ مصغّرةٌ مضمَّنة من خادم البلاطات الخاصّ — لا تغادر الإحداثياتُ نطاقنا.
    off  → رسالةٌ صادقة، والإحداثياتُ تبقى نصّاً حيث تُعرض.
  المُدخَلات: $lat · $lng · $label (نصُّ الرابط)
--}}
@php
    $mpLat = (float) $lat; $mpLng = (float) $lng;
    $mpMode = \App\Support\Platform\Maps::mode();
@endphp
@if ($mpMode === 'osm')
    <a class="chip" target="_blank" rel="noopener" href="{{ \App\Support\Platform\Maps::pointUrl($mpLat, $mpLng) }}">{{ $label }}</a>
@elseif ($mpMode === 'self')
    @php $mpId = 'mp' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)); @endphp
    <div id="{{ $mpId }}" class="lyn-map-point" data-map-mode="self" style="height:220px;width:100%;border-radius:var(--r);overflow:hidden;margin-top:6px"></div>
    <link rel="stylesheet" href="{{ asset('vendor/leaflet/1.9.4/leaflet.min.css') }}">
    <script src="{{ asset('vendor/leaflet/1.9.4/leaflet.min.js') }}"></script>
    <script nonce="{{ \App\Support\Security\ContentSecurity::nonce() }}">
    (function () {
        if (!window.L) return;
        var p = [@json($mpLat), @json($mpLng)];
        var m = L.map(@json($mpId)).setView(p, 16);
        L.tileLayer(@json(\App\Support\Platform\Maps::tilesUrl()), { maxZoom: 19, attribution: @json(\App\Support\Platform\Maps::ATTRIBUTION) }).addTo(m);
        L.circleMarker(p, { radius: 7, color: '#0E7C66' }).addTo(m);
    })();
    </script>
@else
    <span class="sub" data-map-mode="off">{{ \App\Support\Platform\Maps::offReason() }}</span>
@endif
