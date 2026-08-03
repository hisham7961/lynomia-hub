{{-- حالة فارغة موحدة (v2.126): أيقونة واحدة + نص + CTA اختياري.
     داخل جدول مرر colspan؛ خارجَه تُصاغ div. الأيقونة الافتراضية 🍃 من CSS ::before
     عند غياب .big — مرر icon لأيقونة أدل على السياق. --}}
@if (isset($colspan))<tr><td colspan="{{ $colspan }}" class="empty">@else<div class="empty">@endif
    @isset($icon)<span class="big" aria-hidden="true">{{ $icon }}</span>@endisset
    {{ $text }}
    @isset($cta)
        <div style="margin-top:10px"><a class="btn p sm" href="{{ $cta }}">{{ $ctaLabel ?? '＋ إضافة' }}</a></div>
    @endisset
@if (isset($colspan))</td></tr>@else</div>@endif
