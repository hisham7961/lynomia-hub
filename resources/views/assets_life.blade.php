@extends('layouts.app')
@section('title', 'العهدة ودورة حياة الأصول')
@section('content')
<div class="hero">
    <div>
        <h2>💼 العهدة ودورة الحياة</h2>
        <div class="sub">
            من يحمل ماذا · ما انتهى ضمانه · ما فات موعد صيانته · وما صار إصلاحه أغلى من إحلاله.
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn ghost sm" href="{{ route('assets.life', ['fresh' => 1]) }}">↻ تحديث</a>
        @if (hub_can(auth()->user(), 'assetlog', 'a'))
            <a class="btn ghost sm" href="{{ route('m.create', 'assetlog') }}">＋ قيد صيانة</a>
        @endif
    </div>
</div>

@if ($sum)
<div class="cards">
    <div class="stat"><span class="ico">📦</span><b>{{ number_format($sum['n']) }}</b><span>أصل مسجَّل</span></div>
    <div class="stat"><span class="ico">💰</span><b>{{ number_format($sum['value'], 0) }}</b><span>قيمة الشراء ({{ $sum['cur'] }})</span></div>
    <div class="stat"><span class="ico">🤲</span><b>{{ number_format($sum['held']) }}</b><span>في عهدة أشخاص</span></div>
    <div class="stat"><span class="ico">🔧</span><b>{{ number_format($sum['inMaint']) }}</b><span>في الصيانة الآن</span></div>
    <div class="stat"><span class="ico">🧾</span><b>{{ number_format($sum['spend12'], 0) }}</b><span>صيانة ١٢ شهراً</span></div>
</div>
@endif

{{-- قرار الإحلال: لا أحد يجمع فواتير الصيانة فيرى أنها تجاوزت الثمن --}}
@if (count($replace))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">♻️ إصلاحه أغلى من إحلاله</h3>
    <div class="sub" style="margin-bottom:8px">
        مجموع ما صُرف على صيانة كل أصل مقابل ثمنه — الفواتير متفرّقةٌ فلا تُرى إلا مجموعة.
    </div>
    <table class="mini">
        @foreach ($replace as $r)
            <tr>
                <td><a href="{{ route('m.show', ['assets', $r['id']]) }}"><b>{{ $r['name'] }}</b></a>
                    <div class="sub" style="font-size:12px">{{ $r['verdict'] }}</div></td>
                <td class="acts mono sub">{{ $r['times'] }} مرّة</td>
                <td class="acts mono">{{ number_format($r['spent'], 0) }} / {{ number_format($r['price'], 0) }}</td>
                <td class="acts"><span class="bdg {{ $r['pct'] >= 100 ? 'bad' : 'wn' }}">{{ $r['pct'] }}٪</span></td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- ما له ثمنٌ إن أُهمل --}}
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">⚠️ ما يستحق التفاتاً
        <span class="bdg {{ collect($flags)->where('tone','bad')->count() ? 'bad' : '' }}">{{ count($flags) }}</span>
    </h3>
    @forelse ($flags as $f)
        <div class="alrow">
            <span style="flex:none;font-size:17px;width:26px;text-align:center">{{ $f['icon'] }}</span>
            <div style="min-width:0;flex:1">
                <a href="{{ route('m.show', ['assets', $f['id']]) }}"><b>{{ \Illuminate\Support\Str::limit($f['title'], 70) }}</b></a>
                <div class="sub" style="font-size:12px">
                    <span class="bdg {{ $f['tone'] }}">{{ $f['kind'] }}</span> {{ $f['why'] }}
                </div>
            </div>
        </div>
    @empty
        <div class="empty" style="padding:24px 12px"><span class="big">✅</span>
            لا ضمانٌ ينتهي، ولا صيانةٌ فات موعدها، ولا أصلٌ خارج الخدمة معلَّق في الجرد.</div>
    @endforelse
</div>

{{-- من يحمل ماذا --}}
@if (count($custody))
<div class="card">
    <h3 class="cardtitle">🤲 من يحمل ماذا</h3>
    <table class="mini">
        @foreach ($custody as $c)
            <tr class="{{ $c['gone'] ? 'goneRow' : '' }}">
                <td>
                    <b>{{ $c['name'] }}</b>
                    @if ($c['gone'])
                        {{-- عهدةٌ باسم من لم يعد يعمل: تُستردّ أو تُنقل، ولا تبقى معلّقة --}}
                        <span class="bdg bad" title="حسابٌ موقوف أو محذوف">⚠️ لم يعد نشطاً</span>
                    @endif
                    <div class="sub" style="font-size:12px">{{ implode(' · ', $c['items']) }}</div>
                </td>
                <td class="acts mono sub">{{ $c['n'] }} قطعة</td>
                <td class="acts mono">{{ number_format($c['value'], 0) }}</td>
            </tr>
        @endforeach
    </table>
    <div class="sub" style="margin-top:8px;font-size:12px">
        🖊️ استلام العهدة يُقرّه حائزها من صفحة الأصل نفسها — بوقتٍ وعنوانٍ وجهاز.
    </div>
</div>
@endif

<style>
.alrow { display:flex; gap:9px; align-items:flex-start; padding:9px 4px; border-bottom:1px solid var(--ln) }
.alrow:last-child { border-bottom:0 }
.goneRow { background:color-mix(in srgb, var(--bad) 8%, transparent) }
</style>
@endsection
