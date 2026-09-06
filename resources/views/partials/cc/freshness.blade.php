{{-- سطر الطزاجة (WP-1.5، spec §12.4): «آخر حساب · مخبّأ · تحديث» — يتوقع:
     $at (Carbon|string|null من hub_screen بـstamped)، $ttl ثوانيَ الخبيئة،
     و$fresh_url اختياري (وإلا بُني من الرابط الحالي بـfresh=1 مع حفظ المعاملات). --}}
@php
    $ccAt = ! empty($at) ? (($at instanceof \Carbon\CarbonInterface) ? $at : \Illuminate\Support\Carbon::parse($at)) : null;
    $ccFq = collect(request()->query())->except(['fresh'])->filter(fn ($v) => ! is_array($v))->all();
    $ccFu = $fresh_url ?? request()->url() . '?' . http_build_query($ccFq + ['fresh' => 1]);
@endphp
<div class="sub">
    @if ($ccAt)آخر حساب <span title="{{ $ccAt->format('Y-m-d H:i:s') }}">{{ $ccAt->diffForHumans() }}</span>@else لم يُحسب بعد @endif
    · مخبّأةٌ حتى {{ (int) ($ttl ?? 0) }} ثانية
    · <a href="{{ $ccFu }}">تحديث الآن</a>
</div>
