{{-- ترقيم بلا عدّ إجمالي — للجداول سريعة النمو حيث COUNT(*) نفسه هو العبء --}}
@if ($paginator->hasPages())
    <nav class="pager" aria-label="التنقل بين الصفحات">
        <a class="pg {{ $paginator->onFirstPage() ? 'dis' : '' }}" href="{{ $paginator->previousPageUrl() }}">السابق</a>
        <span class="pg info">صفحة {{ $paginator->currentPage() }}</span>
        <a class="pg {{ $paginator->hasMorePages() ? '' : 'dis' }}" href="{{ $paginator->nextPageUrl() }}">التالي</a>
    </nav>
@endif
