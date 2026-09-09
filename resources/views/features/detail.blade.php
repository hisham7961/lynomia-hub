@extends('layouts.app')
@section('title', $f['title_ar'])
@section('content')
@php
    use App\Support\FeatureStatus;
    $mob = ['ready' => 'الخلفيّةُ جاهزة', 'development' => 'قيدَ التطوير', 'not_applicable' => 'لا ينطبق'];
    $nat = ['deferred' => 'مؤجَّلة', 'available' => 'متاحة', 'not_applicable' => 'لا ينطبق'];
    $oa  = ['documented' => 'موثَّقة', 'not_applicable' => 'لا ينطبق'];
@endphp

<div class="hero">
    <div>
        <h2>{{ FeatureStatus::icon($f['status']) }} {{ $f['title_ar'] }}</h2>
        <div class="sub">{{ $f['title_en'] }} · <span class="mono">{{ $f['key'] }}</span> · {{ $domain['label_ar'] ?? $f['domain'] }}</div>
    </div>
    <a class="btn ghost sm" href="{{ route('features.index') }}">← السجلّ</a>
</div>

@if (session('ok'))<div class="note ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="note wn">{{ session('err') }}</div>@endif

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start">
    <div>
        {{-- الحالةُ والسبب --}}
        <div class="card">
            <div class="crow" style="gap:8px;align-items:center;flex-wrap:wrap">
                <span class="bdg {{ FeatureStatus::tone($f['status']) }}" style="font-size:13px">{{ FeatureStatus::labelAr($f['status']) }} · {{ FeatureStatus::labelEn($f['status']) }}</span>
                @if ($f['available'])<span class="bdg ok">متاحةٌ تشغيليّاً</span>@else<span class="bdg g">غيرُ متاحةٍ بعد</span>@endif
                @if ($f['toggleable_now'])<span class="bdg i">🎚️ قابلٌ للتبديل</span>@endif
                @if ($f['status'] === FeatureStatus::SYSTEM_INVARIANT)<span class="bdg i">🔒 ثابتٌ نظاميّ — لا يُطفأ</span>@endif
            </div>
            @if (($f['reason'] ?? '') !== '')<div class="sub" style="margin-top:8px"><b>لماذا هذه الحالة؟</b> {{ $f['reason'] }}</div>@endif
            @if (($f['desc_ar'] ?? '') !== '')<div style="margin-top:8px">{{ $f['desc_ar'] }}</div>@endif
            @if (($f['limitations'] ?? '') !== '')<div class="note wn" style="margin-top:8px"><b>حدود:</b> {{ $f['limitations'] }}</div>@endif
            @if (($f['deferred_notes'] ?? '') !== '')<div class="note" style="margin-top:8px">🕒 <b>ملاحظةُ التأجيل:</b> {{ $f['deferred_notes'] }}</div>@endif
        </div>

        {{-- التبديلُ الآمن (للاختياريّة فقط) — عبر Settings::put --}}
        @if ($f['toggleable_now'])
            <div class="card" style="margin-top:12px">
                <h3>🎚️ التبديل</h3>
                <div class="sub" style="margin-bottom:8px">قدرةٌ اختياريّةٌ — إطفاؤها يجعل مسارَها يفشل بأمان (لا تسريب). يُكتب عبر مركز الإعدادات (كاتبٌ واحد + تاريخ + تدقيق).</div>
                <form method="POST" action="{{ route('features.toggle', $f['key']) }}" class="crow" style="gap:8px;align-items:end;flex-wrap:wrap"
                      data-confirm="{{ $f['available'] ? 'إطفاءُ' : 'تفعيلُ' }} «{{ $f['title_ar'] }}»؟">
                    @csrf
                    <input type="hidden" name="on" value="{{ $f['available'] ? '0' : '1' }}">
                    <div style="flex:1;min-width:160px">
                        <label class="lbl" for="freason">السبب (اختياريّ — يُحفظ في التدقيق)</label>
                        <input class="inp" id="freason" name="reason" maxlength="300" placeholder="لماذا هذا التغيير؟">
                    </div>
                    <button class="btn {{ $f['available'] ? '' : 'p' }} sm" type="submit">{{ $f['available'] ? '⬛ إطفاء' : '✅ تفعيل' }}</button>
                </form>
            </div>
        @endif

        {{-- الاعتماديّاتُ والحواجز --}}
        @if ($f['depends'] || $f['optional_depends'] || $blockers['blocking'] || $blockers['external'])
            <div class="card" style="margin-top:12px">
                <h3>🔗 الاعتماديّات</h3>
                @if ($blockers['blocking'])
                    <div class="note wn"><b>حواجزُ إلزاميّة:</b>
                        @foreach ($blockers['blocking'] as $b)
                            <a href="{{ route('features.show', $b['key']) }}" class="bdg wn" style="text-decoration:none">{{ $b['title'] }} ({{ FeatureStatus::labelAr($b['status']) }})</a>
                        @endforeach
                    </div>
                @endif
                @if ($f['depends'])
                    <div class="sub" style="margin-top:6px"><b>يتوقّف على:</b>
                        @foreach ($f['depends'] as $dep)<a href="{{ route('features.show', $dep) }}" class="bdg g" style="text-decoration:none">{{ \App\Support\FeatureRegistry::title($dep) }}</a>@endforeach
                    </div>
                @endif
                @if ($f['external_depends'])
                    <div class="sub" style="margin-top:6px"><b>أنظمةٌ خارجيّة مطلوبة:</b> {{ implode(' · ', $f['external_depends']) }}</div>
                @endif
                @if (($f['provider'] ?? '') !== '')<div class="sub" style="margin-top:4px"><b>المزوّد:</b> {{ $f['provider'] }}</div>@endif
                @if ($dependents)
                    <div class="sub" style="margin-top:6px"><b>تعتمد عليها:</b>
                        @foreach ($dependents as $d)<a href="{{ route('features.show', $d['key']) }}" class="bdg" style="text-decoration:none">{{ $d['title'] }}</a>@endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- التاريخُ (للقابلِ للتبديل) — من سكّةِ الإعدادات نفسِها --}}
        @if ($f['toggleable_now'])
            <div class="card" style="margin-top:12px">
                <h3>🕘 آخرُ تغيير</h3>
                @if ($history)
                    <div class="sub">{{ $history['at'] }} · بواسطة {{ $history['user'] ?? 'النظام' }} @if(($history['source'] ?? '') !== '')· من {{ $history['source'] }}@endif</div>
                @else
                    <div class="sub">لم تُبدَّل بعد — على افتراضيّها (مُفعَّلة).</div>
                @endif
            </div>
        @endif
    </div>

    {{-- لوحُ الحقائق --}}
    <div class="card">
        <h3>الحقائق</h3>
        @php
            $fact = function ($label, $value) {
                if ($value === null || $value === '' || $value === []) return;
                echo '<div style="display:flex;gap:8px;padding:6px 0;border-top:1px solid var(--ln)"><span class="sub" style="flex:none;width:96px">' . e($label) . '</span><span style="flex:1;min-width:0">' . $value . '</span></div>';
            };
        @endphp
        {!! $fact('من يُدير', $f['permissions'] ? 'صلاحيّة: ' . e(implode('، ', $f['permissions'])) : 'المالك (مركز القدرات)') !!}
        {!! $fact('من يستعمل', e(implode('، ', array_map(fn ($a) => $a === 'client' ? 'العميل' : 'الداخليّ', $f['account_types'])))) !!}
        {!! $fact('أين', $f['admin_surface'] ? '<span class="mono" style="font-size:12px">' . e($f['admin_surface']) . '</span>' : ($f['web_routes'] ? '<span class="mono" style="font-size:12px">' . e(implode(' · ', $f['web_routes'])) . '</span>' : 'داخليّ')) !!}
        {!! $fact('واجهات API', $f['api_routes'] ? '<span class="mono" style="font-size:11px">' . e(implode(' · ', $f['api_routes'])) . '</span>' : ($f['openapi'] === 'documented' ? 'موثَّقة' : 'لا ينطبق')) !!}
        {!! $fact('خلفيّة الجوال', e($mob[$f['mobile_backend']] ?? $f['mobile_backend'])) !!}
        {!! $fact('الجوال الأصيل', e($nat[$f['native_mobile']] ?? $f['native_mobile'])) !!}
        {!! $fact('OpenAPI', e($oa[$f['openapi']] ?? $f['openapi'])) !!}
        {!! $fact('النسخة', $f['introduced'] ? e($f['introduced']) : null) !!}
        {!! $fact('التوثيق', $f['docs'] ? '<span class="mono" style="font-size:11px">' . e($f['docs']) . '</span>' : null) !!}
    </div>
</div>
@endsection
