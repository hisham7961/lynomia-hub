{{-- عدسات المشروع: كل لوحةٍ تحليلية مفتوحةً على هذا المشروع وحده --}}
@php
    $plLenses = array_values(array_filter([
        hub_monitor() ? ['capacity', '📊', 'القدرات والموارد', 'حصّته من طاقة الفريق'] : null,
        hub_monitor() ? ['recs', '💡', 'التوصيات', 'ما يستحق التحرك فيه'] : null,
        hub_monitor() ? ['impact', '🕸️', 'خريطة الأثر', 'ما يسقط إن سقط عنصر'] : null,
        hub_monitor() ? ['appquality', '🧪', 'جودة البرمجيات', 'أخطاؤه وأعطاله ونشره'] : null,
        hub_can(auth()->user(), 'contracts', 'v') ? ['legal', '⚖️', 'القانوني', 'عقوده والتزاماته'] : null,
        hub_can(auth()->user(), 'ideas', 'v') ? ['innovation', '💡', 'الابتكار', 'أفكارٌ تطوّره'] : null,
    ]));
@endphp
@if ($plLenses)
<div class="card">
    <h3 class="cardtitle">🔭 عدسات هذا المشروع</h3>
    <div class="sub" style="margin-bottom:8px">
        اللوحات التحليلية كانت تعرض المنشأة كلها مجموعةً — هذه كلٌّ منها محصورةً بهذا المشروع.
    </div>
    <div class="crow">
        @foreach ($plLenses as [$r, $ico, $label, $why])
            <a class="chip" href="{{ route($r, ['p' => $row->id]) }}" title="{{ $why }}">{{ $ico }} {{ $label }}</a>
        @endforeach
    </div>
</div>
@endif
