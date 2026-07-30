{{-- ودجة: توزيع المهام بالحالة --}}
@if (! empty($data))
<div class="card kid">
    <h3>✅ المهام بالحالة</h3>
    @include('partials.chart_donut', ['slices' => $data])
</div>
@endif
