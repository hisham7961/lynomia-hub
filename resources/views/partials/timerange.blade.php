{{-- كبسولات المدى الزمنيّ الموحّد (WP-1.1) — يتوقع: $range (App\Support\TimeRange).
     الروابط تحفظ باقي معاملات الرابط (عدسة/مرشِّحات) وتُسقط الترقيم — تغييرُ المدى صفحةٌ أولى. --}}
@php
    $range = $range ?? hub_range();
    $trQs = collect(request()->query())->except(['range', 'from', 'to', 'page'])
        ->filter(fn ($v) => ! is_array($v))->all();
    $trUrl = request()->url();
    // اليومُ الأخير يُعرض شاملاً كما كتبه المستخدم (to الداخلية حصريّة)
    $trDays = $range->from->format('H:i:s') === '00:00:00' && $range->to->format('H:i:s') === '00:00:00';
    $trFrom = $range->preset === 'custom' ? $range->from->format('Y-m-d') : '';
    $trTo = $range->preset === 'custom' ? ($trDays ? $range->to->copy()->subDay() : $range->to)->format('Y-m-d') : '';
@endphp
<div class="toolbar" style="gap:6px">
    @foreach (\App\Support\TimeRange::PRESETS as $trKey => $trDef)
        <a class="btn sm {{ $range->preset === $trKey ? 'p' : 'ghost' }}"
           href="{{ $trUrl . '?' . http_build_query($trQs + ['range' => $trKey]) }}">{{ $trDef[1] }}</a>
    @endforeach
    <form method="GET" class="filters" style="margin:0">
        @foreach ($trQs as $trK => $trV)
            <input type="hidden" name="{{ $trK }}" value="{{ $trV }}">
        @endforeach
        <input type="hidden" name="range" value="custom">
        <input class="inp ltr" type="date" name="from" value="{{ $trFrom }}" title="من تاريخ">
        <input class="inp ltr" type="date" name="to" value="{{ $trTo }}" title="إلى تاريخ">
        <button class="btn sm {{ $range->preset === 'custom' ? 'p' : '' }}">تطبيق</button>
    </form>
</div>
