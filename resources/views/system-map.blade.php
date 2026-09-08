@extends('layouts.app')
@section('title', 'خريطة النظام')
@section('content')
{{-- خريطةُ النظام (IA · الطور 6): النظامُ كلُّه في شجرةٍ واحدةٍ مُنطَّقة — سطوحٌ عامّة
     ثمّ مجالاتٌ بأقسامها ووجهاتها. لا يظهر إلا ما يبلغه المستخدمُ فعلاً (لا تسريب).
     عرضٌ صرفٌ بأصنافٍ قائمة (.card/.stat/<details>/.navsection) — لا CSS جديد. --}}
@component('partials.pagehead', ['icon' => '🗺️', 'title' => 'خريطة النظام',
    'crumb' => 'الرئيسية', 'sub' => 'النظامُ كلُّه منظّماً في مجالاتٍ وأقسام — ما تراه هو ما تبلغه'])
@endcomponent

{{-- مُرشِّحٌ فوريّ (عميلٌ فقط): يُخفي الوجهاتِ التي لا تطابق الكلمة — بحثٌ بلا خادم --}}
<div class="toolbar" style="margin-bottom:12px">
    <input class="inp" type="search" id="smq" placeholder="🔎 رشّح الوجهات…" autocomplete="off" style="max-width:320px"
           oninput="(function(q){q=q.value.trim().toLowerCase();document.querySelectorAll('[data-smrow]').forEach(function(r){r.style.display=(!q||r.getAttribute('data-smrow').indexOf(q)>-1)?'':'none'});})(this)">
</div>

@php
    $impBadge = ['primary' => '', 'secondary' => '·', 'advanced' => '»'];
@endphp

{{-- سطوحٌ عامّة: الرئيسية ومهامّي --}}
@foreach ($map['surfaces'] as $sk => $s)
    <details class="card" open>
        <summary style="font-weight:600;cursor:pointer">{{ $s['icon'] }} {{ $s['label'] }} <span class="sub">(سطحٌ عامّ)</span></summary>
        @foreach ($s['sections'] as $secKey => $sec)
            <div class="navsection">{{ $sec['label'] }}</div>
            <div class="crow" style="flex-wrap:wrap;gap:6px">
                @foreach ($sec['destinations'] as $d)
                    @php $hay = mb_strtolower(($d['label'] ?? '') . ' ' . implode(' ', (array) ($d['synonyms'] ?? []))); @endphp
                    <span data-smrow="{{ $hay }}">
                        @if (! empty($d['url']))
                            <a class="btn ghost xs" href="{{ $d['url'] }}">{{ $impBadge[$d['importance'] ?? 'secondary'] ?? '' }} {{ $d['label'] }}</a>
                        @else
                            <span class="bdg" title="وجهةٌ سياقيّة (تُفتح من سجلّها)">{{ $d['label'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endforeach
    </details>
@endforeach

{{-- المجالاتُ التسعة: كلٌّ بأقسامه ووجهاته المرئيّة --}}
@foreach ($map['domains'] as $dk => $d)
    <details class="card" {{ $loop->first ? 'open' : '' }}>
        <summary style="font-weight:600;cursor:pointer">{{ $d['icon'] }} {{ $d['label'] }}
            <span class="sub">({{ $d['plane'] === 'system' ? 'نظام' : 'مجال عمل' }})</span></summary>
        @foreach ($d['sections'] as $secKey => $sec)
            <div class="navsection">{{ $sec['label'] }}</div>
            <div class="crow" style="flex-wrap:wrap;gap:6px">
                @foreach ($sec['destinations'] as $dd)
                    @php $hay = mb_strtolower(($dd['label'] ?? '') . ' ' . implode(' ', (array) ($dd['synonyms'] ?? []))); @endphp
                    <span data-smrow="{{ $hay }}">
                        @if (! empty($dd['url']))
                            <a class="btn ghost xs" href="{{ $dd['url'] }}">{{ $impBadge[$dd['importance'] ?? 'secondary'] ?? '' }} {{ $dd['label'] }}</a>
                        @else
                            <span class="bdg" title="وجهةٌ سياقيّة (تُفتح من سجلّها)">{{ $dd['label'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
        @endforeach
    </details>
@endforeach

{{-- لوحةُ تشخيصِ المالك (النسخةُ الحيّةُ من 06-final-audit): كلُّ الوحدات ببيتها،
     والأيتامُ والبيوتُ المكرّرةُ يجب أن تكون صفراً. لا تظهر إلا للمالك (systemMap يقرّر). --}}
@isset($map['diagnostic'])
    @php $dg = $map['diagnostic']; @endphp
    <details class="card">
        <summary style="font-weight:600;cursor:pointer">🩺 تشخيصُ المعمارية (للمالك) —
            <span class="bdg {{ empty($dg['orphans']) && empty($dg['duplicate_homes']) ? 'ok' : 'bad' }}">
                {{ empty($dg['orphans']) && empty($dg['duplicate_homes']) ? 'سليمة ✓' : 'تحتاج انتباهاً' }}</span></summary>
        <div class="cards">
            <div class="stat"><span class="ico">🗂</span><b>{{ $dg['module_count'] }}</b><span>وحدةً مسجّلة</span></div>
            <div class="stat"><span class="ico">🏠</span><b>{{ $dg['homed'] }}</b><span>ببيتٍ أساسيّ</span></div>
            <div class="stat"><span class="ico">📦</span><b>{{ count($dg['deprecated']) }}</b><span>مؤرشفة</span></div>
            <div class="stat"><span class="ico">{{ empty($dg['orphans']) ? '✅' : '⚠️' }}</span><b class="{{ empty($dg['orphans']) ? '' : 'txt-bad' }}">{{ count($dg['orphans']) }}</b><span>يتيمة (يجب 0)</span></div>
            <div class="stat"><span class="ico">{{ empty($dg['duplicate_homes']) ? '✅' : '⚠️' }}</span><b class="{{ empty($dg['duplicate_homes']) ? '' : 'txt-bad' }}">{{ count($dg['duplicate_homes']) }}</b><span>بيتٌ مكرّر (يجب 0)</span></div>
        </div>
        @if (! empty($dg['orphans']))
            <p class="sub">يتيمةٌ بلا بيت: <b>{{ implode('، ', $dg['orphans']) }}</b></p>
        @endif
        @if (! empty($dg['duplicate_homes']))
            <p class="sub">بيوتٌ مكرّرة: <b>{{ implode('، ', array_keys($dg['duplicate_homes'])) }}</b></p>
        @endif
        <div class="navsection">كلُّ وحدةٍ وبيتُها</div>
        <table class="mini">
            <tr><th>الوحدة</th><th>الحالة</th><th>البيت</th></tr>
            @foreach ($dg['modules'] as $mk => $info)
                <tr data-smrow="{{ mb_strtolower($mk) }}">
                    <td class="mono">{{ $mk }}</td>
                    <td>@if ($info['status'] === 'DEPRECATED_CONFIRMED')<span class="bdg">مؤرشفة</span>@else<span class="bdg ok">مُبيَّتة</span>@endif</td>
                    <td class="sub">@if (! empty($info['home'])){{ $info['home']['domain'] ?? $info['home']['surface'] ?? '—' }} ‹ {{ $info['home']['section'] ?? '—' }}@else—@endif</td>
                </tr>
            @endforeach
        </table>
    </details>
@endisset
@endsection
