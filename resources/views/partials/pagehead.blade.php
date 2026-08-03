{{-- ترويسة صفحة موحدة (v2.127) — تشريح واحد لكل الصفحات:
     فتات خبز اختيارية (crumb [+crumbUrl]) ← أيقونة + عنوان + وصف ← أفعال (الـslot) + رابط عودة.
     تُستدعى بـ @component('partials.pagehead', [...]) والأفعال داخل الجسم — أو @include بلا أفعال. --}}
<div class="hero">
    <div style="min-width:0">
        @isset($crumb)
            <nav class="crumbs" aria-label="مسار التنقل">
                @isset($crumbUrl)<a href="{{ $crumbUrl }}">{{ $crumb }}</a>@else<span>{{ $crumb }}</span>@endisset
                <span aria-hidden="true">‹</span>
                <b>{{ $title }}</b>
            </nav>
        @endisset
        <h2>@isset($icon)<span aria-hidden="true">{{ $icon }}</span> @endisset{{ $title }}</h2>
        @isset($sub)<div class="sub">{{ $sub }}</div>@endisset
    </div>
    @php $acts = trim($slot ?? ''); @endphp
    @if ($acts !== '' || isset($back))
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @isset($back)<a class="btn ghost sm" href="{{ $back }}">→ {{ $backLabel ?? 'رجوع' }}</a>@endisset
            {!! $acts !!}
        </div>
    @endif
</div>
