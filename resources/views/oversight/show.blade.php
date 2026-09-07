@extends('layouts.app')
@section('title', 'رقابة: ' . ($conv->title ?: $kindLbl))
@section('content')
@php
    $roleLabels = ['owner' => 'مالك', 'moderator' => 'مشرف', 'member' => 'عضو', 'guest' => 'ضيف'];
@endphp

@include('partials.pagehead', [
    'icon' => '🛡️',
    'title' => $conv->title ?: $kindLbl,
    'crumb' => 'رقابةُ الاتصالات',
    'crumbUrl' => route('oversight.index', ['reason' => $reason]),
    'sub' => $kindLbl . ' · ' . $members->count() . ' عضواً · ' . $count . ' رسالة',
])

{{-- لافتةُ الوصول المُسجَّل — هذه القراءةُ نفسُها دُوّنت في سجل التدقيق (§6) --}}
<div class="note wn" style="border-color:var(--wn);display:flex;gap:10px;align-items:flex-start">
    <span aria-hidden="true" style="font-size:18px">🛡️</span>
    <div>
        <b>وصولُ امتثالٍ — مُسجَّل.</b>
        قراءةٌ رقابيّةٌ للقراءة فقط — لم تُحرّك إيصالَ قراءةِ أحد، ولا تخوّل تحريرَ رسالةٍ أو حذفَها.
        دُوّنت في سجل التدقيق بمعرّفك والسبب ومعرّفِ المحادثة والعنوان ومعرّفِ الطلب.
        <div class="sub" style="margin-top:4px">💬 سببُ الوصول: {{ $reason }}</div>
    </div>
</div>

<div class="grid" style="grid-template-columns:2fr 1fr;gap:16px;align-items:start;margin-top:12px">
    <div class="card">
        <h3>الرسائل <span class="bdg g">{{ $count }}</span></h3>
        @forelse ($items as $m)
            <div class="row" style="padding:10px 4px;border-top:1px solid var(--brd)">
                <div style="display:flex;gap:8px;align-items:center">
                    <span class="ava sm">{{ mb_substr($m['who'] ?? '؟', 0, 1) }}</span>
                    <b style="flex:1;min-width:0">{{ $m['who'] }}</b>
                    @if (! empty($m['pinned']))<span class="bdg" title="مثبّتة">📌</span>@endif
                    @if (! empty($m['deleted']))<span class="bdg bad" title="سُحبت الرسالة">🗑️ محذوفة</span>@endif
                    @if (! empty($m['at']))<span class="sub mono ltr" style="font-size:11px">{{ $m['at'] instanceof \Carbon\CarbonInterface ? $m['at']->format('Y-m-d H:i') : $m['at'] }}</span>@endif
                </div>
                <div style="margin:4px 0 0 32px;white-space:pre-wrap;word-break:break-word">{{ $m['body'] }}</div>

                @foreach ($m['replies'] ?? [] as $rp)
                    <div style="margin:8px 0 0 40px;padding-inline-start:10px;border-inline-start:2px solid var(--brd)">
                        <div style="display:flex;gap:6px;align-items:center">
                            <b class="sub">{{ $rp['who'] }}</b>
                            @if (! empty($rp['deleted']))<span class="bdg bad" style="font-size:10px">محذوفة</span>@endif
                            @if (! empty($rp['at']))<span class="sub mono ltr" style="font-size:10px">{{ $rp['at'] instanceof \Carbon\CarbonInterface ? $rp['at']->format('Y-m-d H:i') : $rp['at'] }}</span>@endif
                        </div>
                        <div style="white-space:pre-wrap;word-break:break-word">{{ $rp['body'] }}</div>
                    </div>
                @endforeach
            </div>
        @empty
            @include('partials.empty', ['text' => 'لا رسائلَ في هذه المحادثة', 'icon' => '💬'])
        @endforelse
    </div>

    <div class="card">
        <h3>👥 الأعضاء <span class="bdg g">{{ $members->count() }}</span></h3>
        @forelse ($members as $mem)
            <div class="row" style="display:flex;gap:8px;align-items:center;padding:8px 4px;border-top:1px solid var(--brd)">
                <span class="ava sm">{{ mb_substr($mem->user?->name ?? '؟', 0, 1) }}</span>
                <b style="flex:1;min-width:0">{{ $mem->user?->name ?? 'مستخدم محذوف' }}</b>
                <span class="bdg">{{ $roleLabels[$mem->role] ?? $mem->role }}</span>
            </div>
        @empty
            <div class="sub" style="padding:8px 0">لا أعضاء مسجّلين</div>
        @endforelse

        <div class="sub" style="margin-top:12px;border-top:1px solid var(--brd);padding-top:10px;line-height:1.9">
            هذه شاشةُ قراءةٍ رقابيّةٍ للقراءة فقط: لا نشرَ ولا ردَّ ولا تحرير ولا حذف.
            كلُّ فتحٍ منها مُدوَّنٌ في <a href="{{ route('audit.index') }}">سجل التدقيق</a>.
        </div>
    </div>
</div>

@endsection
