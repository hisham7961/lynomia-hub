@extends('layouts.portal')
@section('title', 'مساحة العميل')
@section('content')

<div class="hero">
    <div>
        <h2>👋 أهلاً {{ auth()->user()->name }}</h2>
        <div class="sub">
            @if ($clients->isNotEmpty())
                {{ $clients->pluck('name')->join('، ') }}
            @else
                مساحتُك الخاصّة — تظهر هنا مشاريعُك ووثائقُك وفواتيرُك ما إن تتوفّر.
            @endif
        </div>
    </div>
</div>

@php
    // البوابةُ كلُّها خاويةٌ؟ رسالةٌ واحدةٌ صادقةٌ لا أصفارٌ مُلفّقة (§82)
    $anything = $engagements->isNotEmpty() || $projects->isNotEmpty()
        || $documents->isNotEmpty() || $invoices->isNotEmpty() || $conversations->isNotEmpty();
@endphp

@unless ($anything)
    <div class="cportal-empty">
        <div style="font-size:32px">📭</div>
        <h3 style="margin:8px 0 4px">لا بيانات بعد</h3>
        <p class="sub">لم تُشارَك معك مشاريعُ أو وثائقُ أو فواتيرُ حتى الآن. سيصلك إشعارٌ حين تتوفّر.</p>
    </div>
@else
    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">

        {{-- المشاريع --}}
        <div class="card">
            <h3 style="margin-bottom:8px">📁 مشاريعك <a class="btn ghost xs" style="float:inline-end" href="{{ route('portal.projects') }}">الكل</a></h3>
            @forelse ($projects as $p)
                <div class="inbrow" style="display:flex;justify-content:space-between;gap:8px;padding:6px 0">
                    <a href="{{ route('portal.project', $p->id) }}"><b>{{ \Illuminate\Support\Str::limit($p->name, 46) }}</b></a>
                    <span class="bdg">{{ $p->status ?: '—' }}</span>
                </div>
            @empty
                <p class="sub">لا بيانات بعد</p>
            @endforelse
        </div>

        {{-- الارتباطات --}}
        <div class="card">
            <h3 style="margin-bottom:8px">🤝 ارتباطاتك <a class="btn ghost xs" style="float:inline-end" href="{{ route('portal.engagements') }}">الكل</a></h3>
            @forelse ($engagements as $e)
                <div class="inbrow" style="display:flex;justify-content:space-between;gap:8px;padding:6px 0">
                    <span><b>{{ \Illuminate\Support\Str::limit($e->name, 46) }}</b></span>
                    <span class="bdg">{{ $e->status ?: '—' }}</span>
                </div>
            @empty
                <p class="sub">لا بيانات بعد</p>
            @endforelse
        </div>

        {{-- الوثائق --}}
        <div class="card">
            <h3 style="margin-bottom:8px">📄 وثائقُك <a class="btn ghost xs" style="float:inline-end" href="{{ route('portal.documents') }}">الكل</a></h3>
            @forelse ($documents as $d)
                <div class="inbrow" style="display:flex;justify-content:space-between;gap:8px;padding:6px 0">
                    <a href="{{ route('portal.document', $d->id) }}"><b>{{ \Illuminate\Support\Str::limit($d->name, 46) }}</b></a>
                    <span class="sub">{{ $d->cat ?: '' }}</span>
                </div>
            @empty
                <p class="sub">لا بيانات بعد</p>
            @endforelse
        </div>

        {{-- الفواتير --}}
        <div class="card">
            <h3 style="margin-bottom:8px">🧾 فواتيرُك <a class="btn ghost xs" style="float:inline-end" href="{{ route('portal.invoices') }}">الكل</a></h3>
            @forelse ($invoices as $inv)
                <div class="inbrow" style="display:flex;justify-content:space-between;gap:8px;padding:6px 0">
                    <a href="{{ route('portal.invoice', $inv->id) }}"><b>{{ $inv->doc_no }}</b></a>
                    <span class="bdg">{{ $inv->state ?: '—' }}</span>
                </div>
            @empty
                <p class="sub">لا بيانات بعد</p>
            @endforelse
        </div>

        {{-- المحادثات --}}
        <div class="card">
            <h3 style="margin-bottom:8px">💬 محادثاتك <a class="btn ghost xs" style="float:inline-end" href="{{ route('portal.conversations') }}">الكل</a></h3>
            @forelse ($conversations as $c)
                <div class="inbrow" style="padding:6px 0">
                    <a href="{{ route('portal.conversation', $c->id) }}"><b>{{ \Illuminate\Support\Str::limit($c->title ?: 'محادثة', 46) }}</b></a>
                </div>
            @empty
                <p class="sub">لا بيانات بعد</p>
            @endforelse
        </div>

    </div>
@endunless

@endsection
