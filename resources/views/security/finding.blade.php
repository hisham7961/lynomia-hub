@extends('layouts.app')
@section('title', 'نتيجة أمنية')
@section('content')
@php
    $sfEv = json_decode((string) $f->evidence, true) ?: [];
    $sfStatusTone = ['open' => 'bad', 'acknowledged' => 'wn', 'resolved' => 'ok', 'ignored' => 'g'][$f->status] ?? 'g';
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><a href="{{ route('security.index') }}">مركز الأمان</a><span aria-hidden="true">‹</span><a href="{{ route('security.findings') }}">النتائج</a><span aria-hidden="true">‹</span><b><bdi class="mono ltr">{{ $f->code }}</bdi></b></nav>
        <h2>🔎 {{ $f->title }}</h2>
        <div class="sub">
            <span class="bdg {{ \App\Support\Severity::tone($f->severity) }}">{{ \App\Support\Severity::label($f->severity) }}</span>
            <span class="bdg {{ $sfStatusTone }}">{{ \App\Support\SecurityFindings::STATUSES[$f->status] ?? $f->status }}</span>
            @if ($f->entity_type !== 'org')<span class="bdg g" title="نتيجةُ كيانٍ لا منظّمة">{{ $f->entity_type }}</span>@endif
        </div>
    </div>
    @if (! empty($isOwner))
        <div class="crow" style="gap:6px">@include('security.parts.finding_actions', ['f' => $f])</div>
    @endif
</div>

<div class="card" style="margin-bottom:12px">
    <h3>لماذا تهمّ</h3>
    <div class="sub" style="line-height:1.9">{{ $f->description ?: '—' }}</div>

    <h3 style="margin-top:12px">العلاج</h3>
    <div style="font-size:13px;line-height:1.9">{{ $f->remediation ?: 'لا توصيةَ مرفقة — الشرطُ يوثَّق ولا يُخترَع له علاج.' }}</div>
    @if (! empty($sfEv['url']))
        <a class="btn ghost xs" style="margin-top:8px" href="{{ $sfEv['url'] }}">اذهب لإصلاحه ←</a>
    @endif
</div>

<div class="kids">
    <div class="card kid">
        <h3>🕰️ دورة الحياة</h3>
        <table class="mini">
            <tr><td>أول رصد</td><td class="acts sub">{{ \Illuminate\Support\Carbon::parse($f->first_seen_at)->diffForHumans() }}</td></tr>
            <tr><td>آخر رصد</td><td class="acts sub">{{ \Illuminate\Support\Carbon::parse($f->last_seen_at)->diffForHumans() }}</td></tr>
            @if ($f->acknowledged_at)
                <tr><td>الإقرار</td><td class="acts sub">{{ $ackBy ?: 'مستخدم محذوف' }} · {{ \Illuminate\Support\Carbon::parse($f->acknowledged_at)->diffForHumans() }}</td></tr>
            @endif
            @if ($f->resolved_at)
                <tr><td>الحل</td><td class="acts sub">{{ \Illuminate\Support\Carbon::parse($f->resolved_at)->diffForHumans() }}</td></tr>
            @endif
            @if ($f->request_id)
                <tr><td>معرّف الطلب</td><td class="acts"><a href="{{ route('system.trace', $f->request_id) }}"><bdi class="mono ltr">{{ $f->request_id }}</bdi></a></td></tr>
            @endif
        </table>
        <div class="sub" style="margin-top:6px">الإغلاقُ التلقائيّ يقع حين يُشاهَد الشرطُ زائلاً في التسوية اليومية — والصفُّ يبقى تاريخاً لا يُحذف.</div>
    </div>

    <div class="card kid">
        <h3>🧾 الدليل</h3>
        @if (! $sfEv)
            @include('partials.empty', ['text' => 'لا دليلَ مرفقاً بهذه النتيجة', 'icon' => '🍃'])
        @else
            <div class="tblwrap">
                <table class="tbl">
                    <thead><tr><th scope="col">البند</th><th scope="col">القيمة</th></tr></thead>
                    <tbody>
                    @foreach ($sfEv as $sfK => $sfV)
                        <tr>
                            <td><bdi class="mono ltr">{{ $sfK }}</bdi></td>
                            <td>{{ is_scalar($sfV) || $sfV === null ? ($sfV ?? '—') : json_encode($sfV, JSON_UNESCAPED_UNICODE) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
