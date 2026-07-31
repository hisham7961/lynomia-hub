@extends('layouts.app')
@section('title', 'جودة البيانات')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span><b>مركز جودة البيانات</b></nav>
        <h2>🧹 مركز جودة البيانات</h2>
        <div class="sub">مكررات ونواقص وركود — نظّفها هنا قبل أن تُفسد تقاريرك</div>
    </div>
</div>

@if ($clean)
    <div class="card"><div class="empty"><span class="big">🎉</span>بياناتك نظيفة — لا مكررات ولا نواقص مكتشفة</div></div>
@endif

@if ($groups)
    <div class="card">
        <h3>👥 عملاء يُرجّح تكرارهم ({{ count($groups) }} مجموعة)</h3>
        <div class="sub" style="margin-bottom:10px">اختر السجل الأساسي ثم ادمج — تُعاد كل الإشارات (عقود، عروض، اجتماعات، قرارات، تعليقات) إليه، وتُملأ فراغاته من المدموجين، والمدموجون إلى سلة المحذوفات (قابل للتراجع).</div>
        @foreach ($groups as $ids => $g)
            <form method="POST" action="{{ route('quality.merge') }}" style="border:1px solid var(--brd);border-radius:12px;padding:12px;margin-bottom:10px"
                  data-confirm="دمج المجموعة في السجل المحدد؟">
                @csrf
                <input type="hidden" name="ids" value="{{ $ids }}">
                <div class="sub" style="margin-bottom:6px">تشابه: {{ ['norm' => 'الاسم', 'email' => 'البريد', 'phone' => 'الهاتف'][$g['by']] ?? $g['by'] }}</div>
                @foreach ($g['rows'] as $i => $c)
                    <div style="display:flex;gap:8px;align-items:center;padding:4px 0;flex-wrap:wrap">
                        <label style="display:flex;gap:8px;align-items:center">
                            <input type="radio" name="keep" value="{{ $c->id }}" @checked($i === 0)>
                            <b>{{ $c->name }}</b>
                        </label>
                        {{-- bdi يعزل الأرقام والبريد عن إعادة الترتيب الثنائي — قرار الدمج لا رجعة فيه فلا يُقرأ رقم معكوساً --}}
                        <span class="sub"><bdi>{{ $c->email ?: '—' }}</bdi> · <bdi>{{ $c->phone ?: '—' }}</bdi> · أُنشئ <bdi>{{ $c->created_at ? \Illuminate\Support\Carbon::parse($c->created_at)->format('Y-m-d') : '؟' }}</bdi></span>
                        <a class="btn ghost xs" href="{{ route('m.show', ['clients', $c->id]) }}" target="_blank" rel="noopener">فحص ↗</a>
                    </div>
                @endforeach
                <button class="btn sm" style="margin-top:6px">🔀 دمج في المحدد</button>
            </form>
        @endforeach
    </div>
@endif

@if ($checks)
    <div class="kids">
        @foreach ($checks as $c)
            <div class="card kid">
                <h3>{{ $c['label'] }} <span class="bdg wn">{{ $c['count'] }}</span></h3>
                @if ($c['hint'])<div class="sub" style="margin-bottom:6px">{{ $c['hint'] }}</div>@endif
                <table class="mini">
                    @foreach ($c['sample'] as $s)
                        <tr><td><a href="{{ route('m.show', [$c['module'], $s['id']]) }}">{{ \Illuminate\Support\Str::limit($s['name'], 45) }}</a></td>
                            <td class="acts"><a class="btn ghost xs" href="{{ route('m.edit', [$c['module'], $s['id']]) }}">تصحيح ←</a></td></tr>
                    @endforeach
                </table>
                @if ($c['count'] > count($c['sample']))
                    <div class="sub" style="margin-top:6px">و{{ $c['count'] - count($c['sample']) }} غيرها — <a href="{{ route('m.index', $c['module']) }}">افتح الوحدة</a></div>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection
