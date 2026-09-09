@extends('layouts.app')
@section('title', 'سجلّ القدرات')
@section('content')
@php use App\Support\FeatureStatus; @endphp

<div class="hero">
    <div>
        <h2>🧩 سجلّ القدرات</h2>
        <div class="sub">مصدرُ الحقيقةِ الواحد لِما تدعمه المنصّة وحالتِه — مُفعَّلٌ/جاهزٌ/مؤجَّلٌ/غيرُ مُهيَّأ. <b>التوافرُ والصلاحيةُ فحصان مستقلّان.</b></div>
    </div>
    <a class="btn ghost sm" href="{{ route('settings.edit') }}">⚙️ الإعدادات</a>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="note wn">{{ session('err') }}</div>@endif

{{-- بطاقاتُ الملخّص لكلِّ حالة — كلُّ رقمٍ من `FeatureRegistry::counts` --}}
<div class="crow" style="flex-wrap:wrap;gap:8px;margin-bottom:12px">
    @foreach (FeatureStatus::ALL as $st)
        @php $n = $counts[$st] ?? 0; @endphp
        <a href="{{ route('features.index', ['status' => $status === $st ? null : $st]) }}"
           class="card" style="flex:1;min-width:120px;padding:11px 13px;text-decoration:none;color:inherit;
                  {{ $status === $st ? 'outline:2px solid var(--p)' : '' }}">
            <div style="font-size:22px;font-weight:700">{{ $n }}</div>
            <div class="sub" style="font-size:12px">{{ FeatureStatus::icon($st) }} {{ FeatureStatus::labelAr($st) }}</div>
        </a>
    @endforeach
</div>

{{-- مرشّحاتٌ: بحثٌ + مجالٌ + حالةٌ + راية --}}
<form method="GET" action="{{ route('features.index') }}" class="card" data-noguard
      style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:12px">
    <div style="flex:2;min-width:200px">
        <label class="lbl" for="fq">بحث</label>
        <input class="inp" id="fq" name="q" value="{{ $q }}" placeholder="اسم · مفتاح · مجال · حالة · مزوّد" autocomplete="off">
    </div>
    <div style="flex:1;min-width:150px">
        <label class="lbl" for="fdomain">المجال</label>
        <select class="inp" id="fdomain" name="domain">
            <option value="">كل المجالات</option>
            @foreach ($domains as $dk => $d)<option value="{{ $dk }}" @selected($domain === $dk)>{{ $d['icon'] }} {{ $d['label_ar'] }}</option>@endforeach
        </select>
    </div>
    <div style="flex:1;min-width:150px">
        <label class="lbl" for="fstatus">الحالة</label>
        <select class="inp" id="fstatus" name="status">
            <option value="">كل الحالات</option>
            @foreach ($statuses as $st)<option value="{{ $st }}" @selected($status === $st)>{{ FeatureStatus::labelAr($st) }}</option>@endforeach
        </select>
    </div>
    <div style="flex:1;min-width:150px">
        <label class="lbl" for="fflag">مرشّح</label>
        <select class="inp" id="fflag" name="flag">
            <option value="">—</option>
            <option value="toggleable" @selected($flag === 'toggleable')>قابلٌ للتبديل</option>
            <option value="invariant" @selected($flag === 'invariant')>ثابتٌ نظاميّ</option>
            <option value="external" @selected($flag === 'external')>اعتمادٌ خارجيّ</option>
            <option value="mobile" @selected($flag === 'mobile')>خلفيّةُ جوالٍ جاهزة</option>
            <option value="configurable" @selected($flag === 'configurable')>يحتاج تهيئة</option>
        </select>
    </div>
    <button class="btn sm" type="submit">تطبيق</button>
    @if ($q !== '' || $domain !== '' || $status !== '' || $flag !== '')
        <a class="btn ghost sm" href="{{ route('features.index') }}">مسح</a>
    @endif
</form>

<div class="sub" style="margin-bottom:8px">عرض <b>{{ $shown }}</b> قدرة{{ $shown === 0 ? '' : 'اً' }}.</div>

@forelse ($groups as $dk => $g)
    <div class="card" style="margin-bottom:12px">
        <h3>{{ $g['meta']['label_ar'] ?? $dk }} <span class="bdg g">{{ count($g['features']) }}</span></h3>
        <div style="display:flex;flex-direction:column">
            @foreach ($g['features'] as $key => $f)
                <a class="fx-row" href="{{ route('features.show', $key) }}">
                    <span class="bdg {{ FeatureStatus::tone($f['status']) }}" style="flex:none">{{ FeatureStatus::icon($f['status']) }} {{ FeatureStatus::labelAr($f['status']) }}</span>
                    <span style="flex:1;min-width:0">
                        <b>{{ $f['title_ar'] }}</b>
                        <span class="sub" style="font-size:12px"> · {{ $f['title_en'] }}</span>
                        <div class="sub mono" style="font-size:11px">{{ $key }}</div>
                    </span>
                    @if ($f['toggleable_now'])<span class="bdg i" style="flex:none" title="قابلٌ للتبديل">🎚️</span>@endif
                    @if (! $f['available'] && ($f['reason'] ?? '') !== '')
                        <span class="sub" style="flex:none;max-width:38%;font-size:11.5px;text-align:end">{{ \Illuminate\Support\Str::limit($f['reason'], 70) }}</span>
                    @endif
                    <span class="sub" style="flex:none">›</span>
                </a>
            @endforeach
        </div>
    </div>
@empty
    <div class="empty" style="padding:28px"><span class="big">🧩</span> لا قدرةَ تطابق المرشّح.</div>
@endforelse

<style>
.fx-row { display:flex; align-items:center; gap:10px; padding:9px 4px; border-top:1px solid var(--ln); color:inherit; text-decoration:none }
.fx-row:first-child { border-top:0 }
.fx-row:hover { background:var(--pss) }
</style>
@endsection
