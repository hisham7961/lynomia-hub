@extends('layouts.app')
@section('title', 'مركز الهوية والمسح')
@section('content')
<div class="hero">
    <div>
        <h2>📷 مركز الهوية والمسح</h2>
        <div class="sub">
            امسح أيَّ معرّفٍ — كودَ عهدةٍ أو منتجٍ أو باركوداً عالمياً أو سيريالاً —
            والنظامُ يعرفه أو يستكشفه من المصادر الخارجية ويسجّله في خطوةٍ واحدة.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_can(auth()->user(), 'products', 'v'))
            <a class="btn ghost sm" href="{{ route('m.index', 'products') }}">📋 سجل المنتجات</a>
        @endif
        <a class="btn ghost sm" href="{{ route('custody.catalog') }}">🏷️ كتالوج العهد</a>
        @if (hub_can(auth()->user(), 'products', 'a'))
            <a class="btn p sm" href="{{ route('m.create', 'products') }}">＋ منتج يدوياً</a>
        @endif
    </div>
</div>

<div class="cards">
    @if ($nProducts !== null)
        <div class="stat"><span class="ico">🧬</span><b>{{ number_format($nProducts) }}</b><span>طرازاً في السجل</span></div>
        <div class="stat"><span class="ico">✅</span><b>{{ number_format($nVerified) }}</b><span>موثّقاً</span></div>
    @endif
    @if ($nAssets !== null)
        <div class="stat"><span class="ico">📦</span><b>{{ number_format($nAssets) }}</b><span>قطعة مسجَّلة</span></div>
        <div class="stat"><span class="ico">🔗</span><b>{{ number_format($nLinked) }}</b><span>مربوطة بطرازها</span></div>
    @endif
    <div class="stat"><span class="ico">🆔</span><b>{{ number_format($nIds) }}</b><span>معرّفاً في السجل</span></div>
</div>

@include('identity._scan')

@if ($recent->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">🧬 أحدث المنتجات</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الكود</th><th>المنتج</th><th>العلامة</th><th>النوع</th><th>التوثيق</th></tr></thead>
            <tbody>
            @foreach ($recent as $p)
                <tr>
                    <td class="mono ltr"><a href="{{ route('m.show', ['products', $p->id]) }}">{{ $p->code }}</a></td>
                    <td>{{ \Illuminate\Support\Str::limit($p->name, 50) }}</td>
                    <td>{{ $p->brand ?: '—' }}</td>
                    <td>{{ $p->type ?: '—' }}</td>
                    <td><span class="bdg {{ hub_tone($p->status) }}">{{ $p->status ?: '—' }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif

@if ($lookups->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">🌐 آخر عمليات الاستكشاف الخارجي</h3>
        <div class="sub" style="margin-bottom:6px">باركود سُئل عنه المزوّدون يُجاب من الكاش
            {{ (int) setting('identity.cache_days', 30) }} يوماً — والعدّاد يشهد كم نداءً وُفّر.</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الباركود</th><th>النتيجة</th><th>إصابات الكاش</th><th>آخر سؤال</th></tr></thead>
            <tbody>
            @foreach ($lookups as $l)
                <tr>
                    <td class="mono ltr">{{ $l->norm }}</td>
                    <td><span class="bdg {{ $l->status === 'found' ? 'ok' : 'wn' }}">
                        {{ $l->status === 'found' ? 'عُرف' : 'لم يُعرف' }}</span>
                        @if ($l->status === 'found' && ($l->result['name'] ?? ''))
                            <span class="sub">{{ \Illuminate\Support\Str::limit($l->result['name'], 40) }}</span>
                        @endif</td>
                    <td>{{ number_format($l->hits) }}</td>
                    <td class="sub">{{ optional($l->checked_at)->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
@endsection
