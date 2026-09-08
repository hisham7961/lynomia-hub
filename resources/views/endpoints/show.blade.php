@extends('layouts.app')
@section('title', 'جهاز طرفي — ' . $device->hostname)
@section('content')
{{-- (Work OS · الطور J · WP-J.3 · §43/§63) صفحةُ الجهاز: هويّةٌ + وضعيّةٌ **صادقة**
     (C15 — «غير مُهيّأ» لما مُنعت قراءتُه، لا ادّعاء) + سياسةُ USB («يتطلب MDM /
     رصدٌ فقط» ما دام enforce=false) + أوامرُ القائمة المغلقة وأحداثُ الوكيل.
     الهويّةُ التقنية (السيريال من hw وهويّةُ الوكيل) خلف مفتاح الحقل `hw` —
     نظيرُ سيريال العهدة في الملفّ ٣٦٠ (field-mode لا إخفاءُ عرض). --}}
@php
    $epStatus = [
        'active'    => ['نشط', 'g'],
        'suspended' => ['موقوف', 'wn'],
        'locked'    => ['مقفول', 'bad'],
        'retired'   => ['مسحوب', 'wn'],
    ];
    [$stLabel, $stTone] = $epStatus[$device->status] ?? [$device->status, 'wn'];
    $epTech = hub_field_mode(auth()->user(), 'endpoints', 'hw') !== 'hide';
    $hw = is_array($device->hw) ? $device->hw : [];
    $usbModes = [
        'allow'         => 'سماحٌ كامل',
        'audit'         => 'رصدٌ (Audit)',
        'readonly'      => 'قراءةٌ فقط',
        'block_storage' => 'حجبُ التخزين',
        'block_all'     => 'حجبُ الكل',
    ];
@endphp
<div class="hero">
    <div>
        <h2>💻 {{ $device->hostname }} <span class="bdg {{ $stTone }}">{{ $stLabel }}</span>
            @unless (\App\Support\Endpoint::isSupported($device->os))<span class="bdg wn">نظامٌ قديمٌ — غيرُ مدعوم</span>@endunless</h2>
        <div class="sub">
            {{ \App\Support\Endpoint::label($device->os) }}{{ $device->agent_version ? ' · وكيل ' . $device->agent_version : '' }}
            · آخر نبضة: {{ $device->last_heartbeat_at?->format('Y-m-d H:i') ?? 'لم ينبض بعد' }}
        </div>
    </div>
    <div><a class="btn ghost sm" href="{{ route('endpoints.index') }}">→ الأسطول</a></div>
</div>

@php $assetReason = $device->asset?->endpointIneligibleReason(); @endphp
@if ($assetReason)
    <div class="flash wn" style="margin-top:10px">⚠️ الأصلُ المرتبط: {{ $assetReason }} —
        @if ($device->status !== 'active')
            إدارةُ هذا الجهازِ مُعلَّقةٌ تبعاً لحالة الأصل (لا تُصدَر له أوامرُ جديدة).
        @else
            انتبه: قد يلزم قفلُه/عزلُه أو تقاعُدُ الجهاز.
        @endif
    </div>
@endif

<div class="kids">
    <div class="card kid">
        <h3>🪪 الهويّة</h3>
        <table class="mini">
            <tr><td class="sub">الحامل</td><td>{{ $holder?->name ?? '—' }}</td></tr>
            @if ($epTech)
                <tr><td class="sub">هويّة الوكيل</td><td class="mono" dir="ltr">{{ $device->device_uuid }}</td></tr>
                @if ($hw['serial'] ?? null)
                    <tr><td class="sub">السيريال (من الجرد)</td><td class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit((string) $hw['serial'], 40) }}</td></tr>
                @endif
                <tr><td class="sub">بصمة المفتاح العامّ</td><td class="mono" dir="ltr">{{ \Illuminate\Support\Str::limit((string) $device->pubkey_fp, 24) }}</td></tr>
            @else
                <tr><td class="sub">الهويّة التقنية</td><td>••• محجوبة (صلاحيةُ حقل)</td></tr>
            @endif
            <tr><td class="sub">سُجّل</td><td class="sub">{{ $device->created_at?->format('Y-m-d H:i') }}</td></tr>
        </table>
        <div class="sub" style="margin-top:6px">الخادمُ يخزّن المفتاحَ العامَّ فقط — الخاصُّ وُلد على الجهاز ولم يغادره.</div>
    </div>

    <div class="card kid">
        <h3>🩺 الوضعيّة الصادقة</h3>
        @include('endpoints._posture', ['posture' => $device->posture])
    </div>

    <div class="card kid">
        <h3>🔌 سياسة USB</h3>
        @if ($policy)
            <table class="mini">
                <tr><td class="sub">السياسة</td><td>{{ $policy->name }}</td></tr>
                <tr><td class="sub">الوضع</td><td>{{ $usbModes[$policy->usb_mode] ?? $policy->usb_mode }}</td></tr>
                <tr><td class="sub">الفرض</td>
                    <td>
                        @if ($policy->enforce)
                            <span class="bdg wn">مُقِرٌّ بمتطلب MDM</span>
                        @else
                            <span class="bdg g">رصدٌ فقط (Audit only)</span>
                        @endif
                    </td></tr>
            </table>
        @else
            <div class="sub">لا سياسةَ مُسنَدةً لهذا الجهاز — الوكيلُ يرصد أحداثَ USB ويبلّغها فقط.</div>
        @endif
        {{-- نصُّ الصدق الإلزاميّ (C15) — يُعرَض دائماً: لا ادّعاءَ حجبٍ بلا MDM --}}
        <div class="flash wn" style="margin-top:8px">
            ⚠️ يتطلب MDM / رصدٌ فقط — {{ \App\Models\EndpointPolicy::ENFORCE_NOTICE }}
        </div>
    </div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>🎛️ الأوامر <span class="sub">(قائمةٌ مغلقة — لا أوامرَ حرّة)</span></h3>
        @if ((hub_is_owner() || hub_monitor()) && $device->status !== 'active')
            <div class="sub">الجهازُ غيرُ نشط ({{ $stLabel }}) — لا تُصدَر له أوامر.</div>
        @elseif (hub_is_owner() || hub_monitor())
            <form method="post" action="{{ route('endpoints.command', $device->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                @csrf
                <select name="type" class="inp">
                    <option value="refresh_inventory">تحديث الجرد</option>
                    <option value="refresh_posture">إعادة قراءة الوضعيّة</option>
                    <option value="apply_policy">تطبيق السياسة</option>
                    <option value="isolate">عزل الجهاز (تصعيدُ هوية + سبب)</option>
                    <option value="lock">قفل الجهاز (تصعيدُ هوية + سبب)</option>
                </select>
                <input class="inp" name="reason" placeholder="السبب (إلزاميّ للعزل/القفل)" maxlength="400">
                <button class="btn sm">إصدار</button>
            </form>
            <div class="sub" style="margin:6px 0">الأمرُ يُسحَب مع نبضة الوكيل التالية — لا قناةَ دفعٍ فورية.</div>
        @endif
        <table class="mini">
            @forelse ($commands as $c)
                <tr>
                    <td>{{ $c->type }}
                        @if ($c->reason)<div class="sub">{{ \Illuminate\Support\Str::limit($c->reason, 60) }}</div>@endif</td>
                    <td><span class="bdg {{ ['done' => 'g', 'failed' => 'bad', 'expired' => 'bad'][$c->state] ?? 'wn' }}">{{ $c->state }}</span></td>
                    <td class="sub">{{ $c->created_at?->format('m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا أوامرَ بعد</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>📨 أحدثُ الأحداث <span class="sub">(ملخّصاتٌ منقّحة — لا محتوى مراقبة)</span></h3>
        <table class="mini">
            @forelse ($events as $e)
                <tr>
                    <td><span class="bdg {{ \App\Support\SecurityEvents::SEVERITY_TONE[$e->severity] ?? 'wn' }}">{{ $e->kind }}</span></td>
                    <td>{{ \Illuminate\Support\Str::limit((string) $e->summary, 90) }}</td>
                    <td class="sub">{{ $e->created_at?->format('m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:14px;text-align:center">لا أحداثَ مبلَّغة</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
