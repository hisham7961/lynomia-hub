{{-- (WP-4.6 · §42.9 · §42.10) تفصيلُ حدثٍ أمنيّ واحد من السجلّ المشتقّ — بمفتاح
     المصدر+المعرّف. لغير المالك تصل البياناتُ مطموسةً من المتحكّم (critic #9)،
     ولا يظهر هنا اعتمادٌ ولا before/after أبداً. --}}
@extends('layouts.app')
@section('title', 'تفصيل حدث أمني')
@section('content')
@php
    $sevLabel = ['info' => 'معلومة', 'notice' => 'ملحوظ', 'warning' => 'تحذير', 'high' => 'خطِر'][$e['severity']] ?? $e['severity'];
    $modDef = $e['module'] ? (hub_mod($e['module']) ?? null) : null;
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}#secevents">السجل الأمني</a><span aria-hidden="true">‹</span><b><bdi class="mono ltr">{{ $e['source'] }}#{{ $e['id'] }}</bdi></b></nav>
        <h2>🧾 {{ $e['label'] }} <span class="bdg {{ $e['tone'] }}">{{ $sevLabel }}</span></h2>
        <div class="sub">
            <bdi class="mono ltr">{{ $e['code'] }}</bdi>
            · المصدر: {{ $e['source'] === 'audit' ? 'سلسلة التدقيق المختومة' : 'رادار المنع' }}
            · وقع {{ \Illuminate\Support\Carbon::parse($e['at'])->diffForHumans() }}
        </div>
    </div>
    @if ($createUrl)
        <a class="btn p" href="{{ $createUrl }}" title="نموذجُ إنشاء حادثة معبّأً بأدلّة هذا الحدث — الحفظُ عندك">🚨 افتح حادثة</a>
    @endif
</div>

<div class="kids">
    <div class="card kid">
        <h3>👤 من وماذا</h3>
        <table class="mini">
            <tr><td>الحساب</td><td class="acts">
                {{ $e['user'] ?? ($e['user_id'] ? 'مستخدم محذوف' : 'زائرٌ غير مستخدم') }}
                @if ($emailMode !== 'hide' && ! empty($e['email']))<div class="sub">{{ $e['email'] }}</div>@endif
            </td></tr>
            <tr><td>الحدث</td><td class="acts">{{ $e['label'] }} <span class="sub mono ltr">({{ $e['code'] }})</span></td></tr>
            @if (! empty($e['name']))
                <tr><td>التفصيل</td><td class="acts sub">{{ $e['name'] }}</td></tr>
            @endif
            @if ($e['source'] === 'radar')
                <tr><td>الطلب المرفوض</td><td class="acts"><bdi class="mono ltr">{{ $e['method'] ?: '—' }} {{ $e['path'] ?: '' }}</bdi></td></tr>
                @if (! empty($e['detail']))<tr><td>سبب المنع</td><td class="acts sub">{{ $e['detail'] }}</td></tr>@endif
            @endif
            @if ($modDef)
                <tr><td>الوحدة</td><td class="acts">
                    {{ $modDef['label'] ?? $e['module'] }}
                    @if ($e['record_id'] && hub_can(auth()->user(), $e['module'], 'v'))
                        · <a href="{{ route('m.show', [$e['module'], $e['record_id']]) }}">السجل المعنيّ ←</a>
                    @endif
                </td></tr>
            @endif
        </table>
    </div>

    <div class="card kid">
        <h3>🕰️ متى ومن أين</h3>
        <table class="mini">
            <tr><td>الوقت</td><td class="acts sub">{{ $e['at'] }} · {{ \Illuminate\Support\Carbon::parse($e['at'])->diffForHumans() }}</td></tr>
            <tr><td>العنوان</td><td class="acts">
                @if (! $e['ip'])<span class="sub">—</span>
                @elseif ($ipMasked)<span class="sub">‹عنوان محجوب›</span>
                @else<a href="{{ route('security.ip', $e['ip']) }}"><bdi class="mono ltr">{{ $e['ip'] }}</bdi></a>@endif
            </td></tr>
            @if (! empty($e['device']))
                <tr><td>الجهاز</td><td class="acts sub"><bdi>{{ $e['device'] }}</bdi></td></tr>
            @endif
            <tr><td>معرّف الطلب</td><td class="acts">
                @if (! empty($e['request_id']))
                    <a href="{{ route('system.trace', $e['request_id']) }}" title="أثرُ الطلب عبر الطبقات"><bdi class="mono ltr">{{ $e['request_id'] }}</bdi></a>
                @else<span class="sub">— (قيدٌ أقدم من عمود المعرّف أو بلا طلب)</span>@endif
            </td></tr>
        </table>
        <div class="sub" style="margin-top:6px">معرّفُ الطلب يجمع أثرَ الطبقات كلِّها في صفحة التتبّع — سكّةُ التحقيق الواحدة.</div>
    </div>
</div>

<div class="card">
    <h3>🚨 الحادثة المرتبطة</h3>
    @if ($incident)
        <div class="crow" style="gap:8px;align-items:center;flex-wrap:wrap">
            <a href="{{ route('m.show', ['incidents', $incident->id]) }}"><b>{{ $incident->title }}</b></a>
            @if ($incident->severity)<span class="bdg wn">{{ $incident->severity }}</span>@endif
            @if ($incident->status)<span class="bdg g">{{ $incident->status }}</span>@endif
        </div>
        <div class="sub" style="margin-top:6px">رُبطت بالمرجع الآليّ في ملاحظاتها أو بمعرّف الطلب نفسِه — التحقيقُ يتابع هناك.</div>
    @elseif ($createUrl)
        @include('partials.empty', ['icon' => '🚨',
                 'text' => 'لا حادثةَ مرتبطةً بهذا الحدث بعد — زرُّ «افتح حادثة» يفتح نموذجَ الوحدة معبّأً بالأدلّة، والحفظُ قرارُك'])
    @else
        @include('partials.empty', ['icon' => '🚨',
                 'text' => 'لا حادثةَ مرتبطةً بهذا الحدث — وفتحُ الحوادث يتطلّب صلاحيةَ الإضافة على وحدة الحوادث'])
    @endif
</div>

{{-- (WP-6.2) وربطُه بحادثةٍ **قائمة** غيرُ فتحِ حادثةٍ جديدة: المرجعُ مفتاحُ
     الحدث المشتقّ (المصدر#المعرّف) فلا يتكرّر الدليلُ في الخطّ الزمنيّ --}}
@include('partials.incident_link', [
    'ilKind' => 'security',
    'ilRef' => $e['source'] . '#' . $e['id'],
    'ilSummary' => 'حدث أمنيّ: ' . $e['label'] . ' (' . $e['code'] . ')'
        . (! empty($e['user']) ? ' — ' . $e['user'] : ''),
    {{-- الوحدةُ والسجلّ يُمرَّران فقط إن كانا من السجلّ فعلاً وبمعرّفٍ سليم:
         قيدٌ نظاميّ («الإعدادات») أو معرّفٌ غيرُ uuid يسقط في تحقّق المتحكّم --}}
    'ilModule' => $modDef ? $e['module'] : null,
    'ilRecord' => ($modDef && $e['record_id'] && \Illuminate\Support\Str::isUuid($e['record_id'])) ? $e['record_id'] : null,
])
@endsection
