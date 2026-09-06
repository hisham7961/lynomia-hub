{{-- شريط الاتجاه (WP-1.5، spec §12 المستوى ٣) — يتوقع: $series[] = {at, value} من
     hub_metric_series، و$unit اختيارية، و$empty نصُّ الفراغ. بلا نقاطٍ لا يُرسم خطُّ
     صفرٍ كاذب — قياسٌ لم يقع ليس قياساً قيمتُه صفر، فالحالةُ الفارغة تصارح. --}}
@php $ccSpark = hub_metric_spark($series ?? [], 40); @endphp
@if (! $ccSpark)
    @include('partials.empty', ['text' => $empty ?? 'سيبدأ القياس من الآن', 'icon' => '📈'])
@else
    <div style="display:flex;align-items:flex-end;gap:3px;height:52px" role="img"
         aria-label="اتجاه {{ count($ccSpark) }} نقطة قياس{{ ! empty($unit) ? ' بوحدة ' . $unit : '' }}">
        @foreach ($ccSpark as $ccP)
            @php $ccAt = $ccP['at'] instanceof \Carbon\CarbonInterface ? $ccP['at']->format('Y-m-d H:i') : (string) $ccP['at']; @endphp
            <div title="{{ $ccAt }} — {{ rtrim(rtrim(number_format($ccP['value'], 2), '0'), '.') }}{{ ! empty($unit) ? ' ' . $unit : '' }}"
                 style="flex:1;min-width:3px;height:100%;display:flex;align-items:flex-end">
                <span style="display:block;width:100%;border-radius:2px 2px 0 0;background:var(--p);height:{{ max(6, $ccP['pct']) }}%"></span>
            </div>
        @endforeach
    </div>
@endif
