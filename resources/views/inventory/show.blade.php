@extends('layouts.app')
@section('title', 'جلسةُ جرد')
@section('content')
@php
    $isOpen = (string) $session->status === \App\Models\InventorySession::OPEN;
    $canEdit = hub_can(auth()->user(), 'assets', 'e');
    // ألوانُ الأحكام — دلالةٌ بصريّةٌ لا أكثر
    $tone = ['موجود' => 'ok', 'مفقود' => 'bad', 'انتقل' => 'wn', 'غير متوقع' => 'wn', 'معلّق' => ''];
@endphp
<div class="hero">
    <div>
        <h2>📋 جلسةُ جرد <span dir="ltr" class="sub">{{ \Illuminate\Support\Str::limit($session->id, 8, '') }}</span></h2>
        <div class="sub">الحالة: <b>{{ $session->status }}</b> · فُتِحت {{ optional($session->created_at)->format('Y-m-d H:i') }}</div>
    </div>
    <a class="btn ghost" href="{{ route('inventory.center') }}" style="margin-inline-start:auto">→ كلُّ الجلسات</a>
</div>

@if (session('ok'))<div class="flash ok">{{ session('ok') }}</div>@endif
@if (session('err'))<div class="flash bad">{{ session('err') }}</div>@endif
@if ($errors->any())<div class="flash bad">{{ $errors->first() }}</div>@endif

@if ($canEdit && $isOpen)
<div class="card">
    <h3 class="cardtitle">🔎 مسحُ رمز</h3>
    <form method="POST" action="{{ route('inventory.scan', $session->id) }}" class="row" style="gap:8px">
        @csrf
        <input class="inp ltr" name="code" placeholder="امسح أو اكتب رمزَ الأصل" autofocus autocomplete="off" dir="ltr" required>
        <button class="btn" type="submit">مسح</button>
    </form>
    <div class="acts" style="margin-top:10px">
        <form method="POST" action="{{ route('inventory.reconcile', $session->id) }}" style="display:inline">
            @csrf<button class="btn ghost" type="submit">⚖️ مصالحةٌ الآن</button>
        </form>
        <form method="POST" action="{{ route('inventory.close', $session->id) }}" style="display:inline"
              onsubmit="return confirm('إغلاقُ الجلسة يمنع أيَّ مسحٍ بعده — متابعة؟')">
            @csrf<button class="btn" type="submit">🔒 إغلاقُ الجلسة</button>
        </form>
    </div>
</div>
@endif

<div class="card">
    {{-- (AUDIT-8) العدُّ الكلّيُّ من تجميعِ القاعدة ($total) لا من صفحةِ العرض — صادقٌ مهما كانت الصفحة --}}
    <h3 class="cardtitle">🧮 الأصنافُ المجمَّدة ({{ $total }})</h3>
    @php $c = fn ($k) => (int) ($counts[$k] ?? 0); @endphp
    <div class="sub" style="margin-bottom:8px">
        موجود {{ $c('موجود') }} · مفقود {{ $c('مفقود') }} · انتقل {{ $c('انتقل') }}
        · غير متوقع {{ $c('غير متوقع') }} · معلّق {{ $c('معلّق') }}
    </div>
    @if ($total === 0)
        <div class="sub">لا أصنافَ في اللقطة.</div>
    @else
        <table class="mini">
            <thead><tr><th>الرمز</th><th>الاسم</th><th>النوع</th><th>الحكم</th></tr></thead>
            <tbody>
            @foreach ($items as $it)
                <tr>
                    <td dir="ltr">{{ data_get($it->snapshot, 'code') }}</td>
                    <td>{{ data_get($it->snapshot, 'name') }}</td>
                    <td>{{ data_get($it->snapshot, 'type') }}</td>
                    <td><span class="bdg {{ $tone[$it->verdict] ?? '' }}">{{ $it->verdict }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{-- الترقيمُ على مستوى القاعدة (صفحةٌ ٥٠) — لا تُحمَّل اللقطةُ كاملةً في الصفحة --}}
        {{ $items->links('partials.pagination') }}
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">🖊️ المسحات ({{ $scans->count() }})</h3>
    @if ($scans->isEmpty())
        <div class="sub">لا مسحاتٍ بعد.</div>
    @else
        <table class="mini">
            <thead><tr><th>النتيجة</th><th>الرمز</th><th>الماسِح</th><th>الوقت</th></tr></thead>
            <tbody>
            @foreach ($scans as $sc)
                <tr>
                    <td>{{ $sc->result }}</td>
                    {{-- رمزٌ من اللقطة المنطَّقة وحدَها — لا يُعرَض رمزٌ لأصلٍ خارجَ اللقطة/النطاق --}}
                    <td dir="ltr">{{ $sc->asset_id ? ($itemCodes[$sc->asset_id] ?? '—') : '—' }}</td>
                    <td>{{ $sc->by_id ? ($names[$sc->by_id] ?? '—') : '—' }}</td>
                    <td>{{ optional($sc->at)->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
