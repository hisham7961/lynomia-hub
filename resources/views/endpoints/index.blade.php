@extends('layouts.app')
@section('title', 'مركز النقاط الطرفية')
@section('content')
{{-- (Work OS · الطور J · WP-J.3 · §43/§63) أسطولُ أجهزة الشركة المسجَّلة بالتسجيل
     اللاتماثليّ (عقدُ Es256). داخليٌّ حصراً (مالك/مراقب — العميلُ ٤٠٤ من الحارس
     والمتحكّم)، والترتيبُ حتميّ (hostname ثم id). الوضعيّةُ **صادقة** (C15). --}}
@php
    $epStatus = [
        'active'    => ['نشط', 'g'],
        'suspended' => ['موقوف', 'wn'],
        'locked'    => ['مقفول', 'bad'],
        'retired'   => ['مسحوب', 'wn'],
    ];
@endphp
<div class="hero">
    <div>
        <h2>🛡️ مركز النقاط الطرفية</h2>
        <div class="sub">
            أجهزةُ الشركة المسجَّلة بمفتاحٍ عامّ (الخاصُّ لا يغادر الجهاز) — الوضعيّةُ
            تُعرَض كما بلّغها النظامُ فعلاً: ما مُنعت قراءتُه «غير مُهيّأ» لا يُدّعى،
            وسياساتُ USB بلا MDM رصدٌ فقط.
        </div>
    </div>
    @if (hub_is_owner())
        {{-- (الطور L · WP-L.2) مركزُ التنزيل — إدارتُه للمالك وحدَه --}}
        <a class="btn sm" href="{{ route('endpoints.releases') }}">📦 مركز تنزيل الوكيل</a>
        {{-- (مسارُ التصحيح §8/§9) تكاملُ MDM — رصدٌ فقط، لا حجبَ يُزعَم --}}
        <a class="btn sm" href="{{ route('endpoints.mdm') }}">🛡️ تكامل MDM</a>
    @endif
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('enroll_token'))
    <div class="flash wn">
        🔑 رمزُ التسجيل (يُعرَض مرةً واحدة — القاعدةُ لا تحمل إلا بصمتَه):
        <span class="mono" dir="ltr">{{ session('enroll_token') }}</span>
        <div class="sub">صالحٌ حتى {{ session('enroll_expires_at') }} — يستهلكه جهازٌ واحدٌ لا غير.</div>
        {{-- بابُ المسار الآليّ: يُسلَّم للوكيل مع الرمز — الجهازُ لا يملك جلسةً يقرأ بها الشاشة --}}
        <div class="sub">يرسله الوكيلُ إلى <span class="mono" dir="ltr">POST /api/v1/endpoint/enroll</span> مع مفتاحه العامّ وحدَه.</div>
    </div>
@endif

<div class="cards">
    <div class="stat"><span class="ico">💻</span><b>{{ $stats['total'] }}</b><span>أجهزة مسجَّلة</span></div>
    <div class="stat"><span class="ico">🟢</span><b>{{ $stats['active'] }}</b><span>نشطة</span></div>
    <div class="stat"><span class="ico">📵</span><b>{{ $stats['silent'] }}</b><span>صامتة عن النبض (&gt; {{ $intervalMin * 3 }} دقيقة)</span></div>
    <div class="stat"><span class="ico">📨</span><b>{{ $stats['events7'] }}</b><span>أحداث آخر ٧ أيام</span></div>
</div>

<div class="card">
    <h3 class="cardtitle">🗂️ الأسطول</h3>
    @if ($devices->isEmpty())
        <div class="sub">لا جهازَ مسجَّلاً بعد — يُسَكّ رمزُ تسجيلٍ (مالك/مراقب + تصعيدُ هوية) ويُشغَّل الوكيلُ على الجهاز.</div>
    @else
        <table class="mini">
            <thead><tr><th>الجهاز</th><th>الحامل</th><th>الوضعيّة</th><th>آخر نبضة</th><th>الحالة</th></tr></thead>
            <tbody>
            @foreach ($devices as $d)
                @php
                    $p = is_array($d->posture) ? $d->posture : [];
                    $pOk = count(array_filter($p, fn ($v) => $v === 'active'));
                    $pNc = count(array_filter($p, fn ($v) => $v !== 'active' && $v !== 'inactive'));
                    [$stLabel, $stTone] = $epStatus[$d->status] ?? [$d->status, 'wn'];
                @endphp
                <tr>
                    <td>
                        <a href="{{ route('endpoints.show', $d->id) }}">{{ $d->hostname }}</a>
                        <div class="sub">{{ $d->os }}{{ $d->agent_version ? ' · وكيل ' . $d->agent_version : '' }}</div>
                    </td>
                    <td>{{ $holders->get($d->employee_id)?->name ?? '—' }}</td>
                    <td>
                        @if ($p === [])
                            <span class="sub">لا قراءةَ بعد</span>
                        @else
                            {{-- «سليمة x/y» عدُّ القراءات 'active' **الصادقة** فقط — والمجهول/الممنوع يُعَدّ «غير مُهيّأ» --}}
                            سليمة {{ $pOk }}/{{ count($p) }}@if ($pNc > 0) · <span class="bdg wn">غير مُهيّأ {{ $pNc }}</span>@endif
                        @endif
                    </td>
                    <td class="sub">
                        {{ $d->last_heartbeat_at?->format('Y-m-d H:i') ?? 'لم ينبض بعد' }}
                        @if (in_array($d->id, $silentIds, true)) <span class="bdg wn">صامت</span>@endif
                    </td>
                    <td class="acts"><span class="bdg {{ $stTone }}">{{ $stLabel }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>

@if (hub_is_owner() || hub_monitor())
    <div class="card">
        <h3 class="cardtitle">🔑 تسجيلُ جهازٍ جديد</h3>
        <div class="sub" style="margin-bottom:8px">
            يُسَكّ رمزٌ لمرّةٍ واحدة (بمهلة {{ max(5, (int) setting('endpoint.enroll_ttl_min', 15)) }} دقيقة)
            مربوطٌ بأصلٍ <b>مملوكٍ للشركة</b> — الشركةُ والحاملُ والمحطّةُ تُشتقّ من الأصل خادميّاً
            (§2)؛ الجهازُ يولّد زوجَ مفاتيحه محلياً ويرسل العامَّ وحدَه.
        </div>
        <form method="post" action="{{ route('enroll.mint') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @csrf
            <select name="assetId" class="inp" required>
                <option value="">— أصلٌ مملوكٌ للشركة —</option>
                @foreach (hub_scope(\App\Models\Asset::query(), 'assets')
                    ->whereNotNull('company_id')
                    ->where(fn ($q) => $q->whereNull('owner_scope')->orWhere('owner_scope', '!=', \App\Models\Asset::OWNER_BYOD))
                    ->whereNotIn('status', \App\Models\Asset::ENDPOINT_INELIGIBLE_STATUSES)
                    ->orderBy('name')->orderBy('id')->limit(100)->get(['id', 'code', 'name']) as $as)
                    <option value="{{ $as->id }}">{{ $as->code ? $as->code . ' — ' : '' }}{{ $as->name }}</option>
                @endforeach
            </select>
            <button class="btn sm">سكُّ رمزِ تسجيل</button>
            <span class="sub">يتطلب تصعيدَ هوية (step-up). الأصلُ الشخصيّ (BYOD) لا يظهر ولا يُقبَل.</span>
        </form>
    </div>
@endif
@endsection
