{{-- تبويبات مركز التحكّم (WP-1.5) — يتوقع: $tabs[] = {key, label, url?} و$active.
     الروابط تكتب ?tab= وتحفظ باقي المعاملات (المدى/المرشِّحات) وتُسقط الترقيم —
     تغييرُ التبويب صفحةٌ أولى. النشطُ يحمل aria-current لقارئ الشاشة. --}}
@php
    $ccTq = collect(request()->query())->except(['tab', 'page'])->filter(fn ($v) => ! is_array($v))->all();
    $ccTu = request()->url();
@endphp
<nav class="tabs" aria-label="أقسام الشاشة">
    @foreach ($tabs ?? [] as $ccT)
        <a class="tab {{ ($active ?? '') === $ccT['key'] ? 'on' : '' }}"
           @if (($active ?? '') === $ccT['key']) aria-current="page" @endif
           href="{{ $ccT['url'] ?? $ccTu . '?' . http_build_query($ccTq + ['tab' => $ccT['key']]) }}">{{ $ccT['label'] }}</a>
    @endforeach
</nav>
