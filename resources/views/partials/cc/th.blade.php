{{-- رأسُ جدولٍ قابلٌ للفرز (WP-1.5، critic #13) — يتوقع: $col مفتاح العمود، $label،
     و$default العمود المفروز حين لا ?sort= في الرابط. يكتب scope="col" دائماً
     وaria-sort صادقاً (ascending|descending|none — الاكتشاف قاس ٥٠١ رأساً بلا scope
     وصفرَ aria-sort، والجداولُ الجديدة لا تُكرّر الدَّين)، ورابطُه يعكس الاتجاه على
     العمود النشط ويحفظ باقي المعاملات ويُسقط الترقيم — الفرزُ صفحةٌ أولى. --}}
@php
    $ccCur = (string) request()->query('sort', $default ?? '');
    $ccDir = strtolower((string) request()->query('dir')) === 'asc' ? 'asc' : 'desc';
    $ccOn = $ccCur === $col;
    $ccQ = collect(request()->query())->except(['sort', 'dir', 'page'])->filter(fn ($v) => ! is_array($v))->all();
    $ccHref = request()->url() . '?' . http_build_query($ccQ + ['sort' => $col, 'dir' => ($ccOn && $ccDir === 'desc') ? 'asc' : 'desc']);
@endphp
<th scope="col" aria-sort="{{ $ccOn ? ($ccDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
    <a href="{{ $ccHref }}">{{ $label }}@if ($ccOn) <span aria-hidden="true">{{ $ccDir === 'asc' ? '▲' : '▼' }}</span>@endif</a>
</th>
