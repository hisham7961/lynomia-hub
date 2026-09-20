{{-- ═══ شريطُ أقسامِ المركزِ السبعةِ (المرحلة ٢ · W8 · §١١) ═══
     يُبنى من `AiAccess::sections()` — **مصدرُ حقيقةِ التنقّلِ الواحد**.
     وقسمٌ لا يُفتَح لهذا المستخدمِ **لا يُعرَض**: شرطُ العرضِ = شرطُ الباب. --}}
@if (! empty($sections))
<div class="row" style="gap:6px;flex-wrap:wrap;margin-bottom:12px">
    @foreach ($sections as $s)
        @continue (! $s['ok'])
        <a class="btn sm {{ ($section ?? '') === $s['key'] ? 'ok' : '' }}"
           href="{{ route($s['route']) }}"
           @if (($section ?? '') === $s['key']) aria-current="page" @endif>
            {{ $s['icon'] }} {{ $s['label'] }}
        </a>
    @endforeach
</div>
@endif
