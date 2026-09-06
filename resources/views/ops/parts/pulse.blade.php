{{-- (WP-2.7) النبضُ من دلاء http_metric_buckets المجمَّعة (لا صفوفَ زيارات تُجلب
     للعدّ) — وبلا حركةٍ مقيسةٍ بعدُ يقولها بصدق بدل رسمِ أعمدةٍ صفرية موهِمة. --}}
@php $pulseMeasured = collect($pulse)->contains(fn ($p) => $p['hits'] > 0 || $p['errs'] > 0); @endphp
<div class="card">
    <h3 class="cardtitle">📈 نبض ٢٤ ساعة <span class="sub">· طلبات HTTP لكل ساعة من دلاء القياس، والساعات ذات الأخطاء بالأحمر</span></h3>
    @if (! $pulseMeasured)
        @include('partials.empty', ['text' => 'لا حركةَ مقيسةً خلال ٢٤ ساعة — سيبدأ القياس من الآن', 'icon' => '📈'])
    @else
        <div style="display:flex;align-items:flex-end;gap:3px;height:78px;padding-top:6px">
            @foreach ($pulse as $p)
                <div title="الساعة {{ $p['hour'] }}:00 — {{ $p['hits'] }} طلباً{{ $p['errs'] ? '، ' . $p['errs'] . ' خطأ' : '' }}"
                     style="flex:1;min-width:4px;height:100%;display:flex;align-items:flex-end">
                    <span style="display:block;width:100%;border-radius:3px 3px 0 0;min-height:2px;
                                 height:{{ max(2, $p['pct']) }}%;
                                 background:{{ $p['errs'] ? 'var(--bad)' : 'var(--p)' }};
                                 opacity:{{ $p['hits'] ? 1 : .18 }}"></span>
                </div>
            @endforeach
        </div>
        <div class="sub" style="display:flex;justify-content:space-between;margin-top:4px">
            <span>{{ $pulse[0]['hour'] }}:00 (قبل ٢٤ ساعة)</span>
            <span>الآن {{ end($pulse)['hour'] }}:00</span>
        </div>
    @endif
</div>
