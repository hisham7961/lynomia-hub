{{-- عدسة المشروع: مُرشِّحٌ واحد يمرّ بكل اللوحات التحليلية.
     يتوقع: $lens (من hub_lens()) — واختيارياً $lensModules لبيان ما لا يرتبط بمشروع. --}}
@php
    $lensList = hub_lens_projects();
    $lensQs = collect(request()->query())->except(['p', 'page'])->all();
    // الوحدات التي لا طريق لها إلى مشروع — تُقال صراحةً بدل أن تبدو مُرشَّحة
    $lensBlind = collect($lensModules ?? [])
        ->filter(fn ($m) => hub_lens_path($m)['mode'] === 'none')
        ->map(fn ($m) => hub_mod($m)['label'] ?? $m)->values();
@endphp
<div class="card" style="padding:10px 12px;margin-bottom:12px">
    <form method="GET" class="filters" style="margin:0">
        @foreach ($lensQs as $qk => $qv)
            @if (! is_array($qv))<input type="hidden" name="{{ $qk }}" value="{{ $qv }}">@endif
        @endforeach
        <label for="lens-p" style="font-weight:700">🔭 عدسة المشروع</label>
        <select class="inp" id="lens-p" name="p" onchange="this.form.submit()" style="max-width:280px">
            <option value="">كل المنشأة</option>
            @foreach ($lensList as $lid => $lname)
                <option value="{{ $lid }}" @selected(($lens['id'] ?? null) === $lid)>{{ $lname }}</option>
            @endforeach
        </select>
        @if ($lens['on'] ?? false)
            <a class="btn ghost sm" href="{{ request()->url() . ($lensQs ? '?' . http_build_query($lensQs) : '') }}">✕ أزل العدسة</a>
            <a class="btn ghost sm" href="{{ route('m.show', ['projects', $lens['id']]) }}">صفحة المشروع ↗</a>
        @endif
        @if (! count($lensList))
            <span class="sub">لا مشاريع في نطاقك بعد.</span>
        @endif
    </form>

    @if (($lens['on'] ?? false) && $lensBlind->isNotEmpty())
        <div class="sub" style="margin-top:8px">
            ⚠️ هذه الأجزاء لا ترتبط بمشروع فتبقى على مستوى المنشأة:
            <b>{{ $lensBlind->implode(' · ') }}</b> — لا تُقرأ كأنها مُرشَّحة.
        </div>
    @endif
</div>
