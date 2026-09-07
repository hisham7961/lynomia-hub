@extends('layouts.app')
@section('title', 'لوحة التسليم التشغيليّة')
@section('content')

{{-- أصنافُ عرضِ اللوحة (Work OS · WP-D.3): الشبكةُ (lanes)، والصفُّ المصغّر (row-lite)،
     وشارةُ العدّ (badge) — تُعرَّف هنا كي تعرفها الورقةُ (StyleVocabularyTest)، وتُكمِّل
     الأنماطَ السطرية لا تصطدم بها. الشارةُ تُلوَّن بأصنافِ النبرة القائمة (bad/wn/ok). --}}
<style>
.lanes{align-items:start}
.row-lite{transition:background .12s ease}
.row-lite:hover{background:var(--card2,#f6f7f9)}
.badge{display:inline-block;min-width:20px;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.6;text-align:center;background:var(--card2,#f2f3f5);border:1px solid var(--line,#e5e7eb)}
</style>

{{-- لوحةُ PSA التشغيليّة (Work OS · الطور D · WP-D.3 · §17) — تجميعٌ فوق سكّة
     العرض→المشروع: خمسُ طبقاتٍ تشخيصيّةٍ للمشاريع الخارجية، كلٌّ من محرّكٍ قائمٍ
     يُقرأ لا يُعاد حسابُه. داخليّةٌ حصراً (PortalGuard + projects:v). --}}
<div class="hero">
    <div>
        <h2>🛰️ لوحة التسليم التشغيليّة</h2>
        <div class="sub">
            المشاريعُ الخارجيةُ في طبقاتٍ تشخيصيّة — <b>نشطة</b>، و<b>في خطر</b>،
            و<b>تنتظر العميل</b>، و<b>محجوبة داخلياً</b>، و<b>معالمُ مستحقة</b>.
            كلُّ طبقةٍ تقرأ محرّكاً قائماً (الصحة · المعالم · التذاكر) لا رقماً مُختلَقاً.
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('delivery.psa', ['fresh' => 1]) }}">↻ تحديث</a>
</div>

{{-- شريطُ العدّادات: مجموعُ كل طبقةٍ رابطاً محايداً (لا قيمةَ مخترعة) --}}
@php
    $ccKpis = collect($lanes)->map(fn ($l) => [
        'label' => $l['icon'] . ' ' . $l['label'],
        'value' => $l['count'],
        'tone'  => $l['count'] > 0 ? ($l['tone'] ?: 'g') : 'g',
    ])->all();
@endphp
@include('partials.cc.kpis', ['items' => $ccKpis])

@include('partials.cc.freshness', ['at' => $at, 'ttl' => 0])

{{-- الطبقاتُ الخمس — كلٌّ بطاقةٌ فيها صفوفُها مرتَّبةً حتميّاً (status ثم id) --}}
<div class="lanes" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;margin-top:12px">
    @foreach ($lanes as $lane)
        <div class="card" style="display:flex;flex-direction:column">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                <b>{{ $lane['icon'] }} {{ $lane['label'] }}</b>
                <span class="badge {{ $lane['count'] > 0 ? ($lane['tone'] ?: '') : '' }}">{{ $lane['count'] }}</span>
            </div>

            @forelse ($lane['rows'] as $r)
                <a class="row-lite" href="{{ route('m.show', ['projects', $r['id']]) }}"
                   style="display:block;padding:8px;border-radius:8px;color:inherit;text-decoration:none;border-top:1px solid var(--line,#eee)">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                        <span style="font-weight:600">{{ $r['name'] }}</span>
                        {{-- الصحةُ من hub_project_health — لونُها بعتبةِ ActionCenter نفسِها --}}
                        <span class="badge {{ $r['health'] < 55 ? 'bad' : ($r['health'] < 80 ? 'wn' : 'ok') }}"
                              title="صحةُ المشروع">{{ $r['health'] }}</span>
                    </div>
                    <div class="sub" style="margin-top:2px">
                        <span>{{ $r['status'] ?: '—' }}</span>
                        @if ($lane['key'] === 'milestones' && ! empty($r['due']))
                            · <span class="txt-bad">💳 {{ $r['due']['count'] }} معلم · {{ number_format($r['due']['total'], 3) }}</span>
                        @endif
                        @if ($lane['key'] === 'blocked' && ! empty($r['hold']))
                            · <span class="txt-bad">⛔ {{ $r['hold'] }}</span>
                        @elseif ($r['blocked'] && $lane['key'] !== 'blocked')
                            · <span class="txt-bad" title="محجوبٌ داخلياً">⛔</span>
                        @endif
                    </div>
                </a>
            @empty
                @include('partials.empty', ['text' => 'لا شيء في هذه الطبقة', 'icon' => $lane['icon']])
            @endforelse
        </div>
    @endforeach
</div>

<div class="card" style="margin-top:12px">
    <div class="sub">
        الطبقاتُ <b>تشخيصيّةٌ لا متنافية</b>: مشروعٌ «في خطر» يبقى «نشطاً» — الخطرُ
        إبرازٌ مُرشَّح لا حالةُ حياةٍ بديلة، فلا يُنقَل المشروعُ من عموده في الـkanban.
        «محجوبٌ داخلياً» و«معالمُ مستحقة» إشاراتٌ إضافيّة: الأولى من علَمٍ صريحٍ على
        المشروع، والثانية من معالمِ عرضٍ بُلغت ولم تُفوتَر (بلا ازدواجِ فوترة).
    </div>
</div>

@endsection
