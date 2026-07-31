@if ($paginator->hasPages())
@php
    $cur = $paginator->currentPage(); $last = $paginator->lastPage();
    $from = max(1, $cur - 2); $to = min($last, $cur + 2);
@endphp
<nav class="pager" aria-label="ترقيم الصفحات">
    <a class="pg {{ $paginator->onFirstPage() ? 'dis' : '' }}" @if ($paginator->onFirstPage()) aria-disabled="true" @endif href="{{ $paginator->previousPageUrl() }}">السابق</a>
    @if ($from > 1)<a class="pg" href="{{ $paginator->url(1) }}">1</a>@if ($from > 2)<span class="pg dis">…</span>@endif @endif
    @for ($i = $from; $i <= $to; $i++)
        <a class="pg {{ $i === $cur ? 'cur' : '' }}" @if ($i === $cur) aria-current="page" @endif href="{{ $paginator->url($i) }}">{{ $i }}</a>
    @endfor
    @if ($to < $last)@if ($to < $last - 1)<span class="pg dis">…</span>@endif<a class="pg" href="{{ $paginator->url($last) }}">{{ $last }}</a>@endif
    <a class="pg {{ $paginator->hasMorePages() ? '' : 'dis' }}" href="{{ $paginator->nextPageUrl() }}">التالي</a>
    <span class="pg info">{{ number_format($paginator->total()) }} سجلاً</span>
</nav>
@endif
