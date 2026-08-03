@extends('layouts.app')
@section('title', 'خطأ — ' . \Illuminate\Support\Str::limit($e->message, 40))
@section('content')
@php
    $kindLabel = ['php' => 'استثناء PHP', 'api' => 'خطأ API', 'js' => 'خطأ متصفح', 'slow' => 'طلب بطيء'][$e->kind] ?? $e->kind;
    $kindTone = $e->kind === 'slow' ? 'wn' : ($e->kind === 'js' ? 'g' : 'bad');
    $taskId = ($e->meta['task_id'] ?? null);
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل">
            <a href="{{ route('errors.index') }}">مركز الأخطاء</a><span aria-hidden="true">‹</span><b>تفاصيل الخطأ</b>
        </nav>
        <h2>🐞 {{ $kindLabel }}</h2>
        <div class="sub">
            <span class="bdg {{ $kindTone }}">{{ $kindLabel }}</span>
            <span class="bdg {{ $e->status === 'محلول' ? 'ok' : ($e->status === 'جديد' ? 'bad' : 'wn') }}">{{ $e->status }}</span>
            تكرّر <b>{{ number_format($e->count) }}</b> مرة ·
            أول ظهور {{ $e->first_seen?->diffForHumans() }} · آخر ظهور {{ $e->last_seen?->diffForHumans() }}
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('errors.index') }}">← القائمة</a>
</div>

{{-- ما هو: الرسالة كاملةً بلا بتر --}}
<div class="card" style="border-inline-start:4px solid var(--bad, #c0392b)">
    <h3 class="cardtitle">❓ ما هو الخطأ؟</h3>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left;white-space:pre-wrap;font-size:12.5px">{{ $e->message }}</pre>
</div>

{{-- أين: الموضع والمقتطف --}}
<div class="card">
    <h3 class="cardtitle">📍 أين وقع؟</h3>
    <table class="mini" style="margin-bottom:10px">
        <tr><td class="sub" style="width:130px">الملف والسطر</td>
            <td class="mono ltr">{{ $relPath ? $relPath . ($e->line ? ':' . $e->line : '') : '— غير مسجَّل —' }}</td></tr>
        <tr><td class="sub">الرابط</td>
            <td class="mono ltr">{{ $e->method ? $e->method . ' ' : '' }}{{ $e->url ?: '— (أمر طرفية أو مجدول) —' }}</td></tr>
        <tr><td class="sub">المستخدم المتأثر</td>
            <td>{{ $e->user_id ? ($users[$e->user_id] ?? 'مستخدم محذوف') : 'زائر / نظام' }}</td></tr>
        <tr><td class="sub">معرّف الطلب</td>
            <td class="mono ltr">{{ $e->request_id ?: '—' }}</td></tr>
    </table>

    @if ($snippet)
        <div class="sub" style="margin-bottom:6px">الشيفرة عند موضع الخطأ:</div>
        <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:10px;overflow:auto;direction:ltr;text-align:left;font-size:12px">@foreach ($snippet as $s)<div style="{{ $s['hot'] ? 'background:rgba(192,57,43,.16);font-weight:700' : '' }}"><span class="sub" style="display:inline-block;width:46px">{{ $s['n'] }}</span>{{ $s['code'] }}</div>@endforeach</pre>
    @elseif ($e->file)
        <div class="sub">تعذّرت قراءة الملف لعرض المقتطف (قد يكون خارج المشروع أو غير قابل للقراءة).</div>
    @endif
</div>

{{-- أثر النداء --}}
@if ($e->trace)
    <div class="card">
        <h3 class="cardtitle">🧵 أثر النداء (Stack trace)</h3>
        <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left;max-height:420px;font-size:11.5px">{{ $e->trace }}</pre>
    </div>
@endif

{{-- ما العمل --}}
<div class="card">
    <h3 class="cardtitle">🛠️ ما العمل؟</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        @foreach (['قيد المعالجة', 'محلول', 'جديد'] as $to)
            @continue($to === $e->status)
            <form class="inline" method="POST" action="{{ route('errors.status', $e->id) }}">
                @csrf<input type="hidden" name="to" value="{{ $to }}">
                <button class="btn ghost sm">علّمه «{{ $to }}»</button>
            </form>
        @endforeach

        @if ($taskId)
            <a class="btn ghost sm" href="{{ route('m.show', ['tasks', $taskId]) }}">✅ مهمة الإصلاح ↗</a>
        @elseif (hub_can(auth()->user(), 'tasks', 'a'))
            <form class="inline" method="POST" action="{{ route('errors.task', $e->id) }}">
                @csrf<button class="btn">🛠️ حوّله لمهمة إصلاح</button>
            </form>
        @endif
    </div>
    <div class="sub" style="margin-top:8px">
        المهمة ترث الرسالة والموضع والرابط ومعرّف الطلب — وأولويتها «عاجلة» إن تكرّر الخطأ ١٠ مرات فأكثر.
    </div>
</div>

{{-- أخطاء شقيقة --}}
@if ($siblings->isNotEmpty())
    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🔗 أخطاء من الملف/الرابط نفسه</h3>
        <div class="sub" style="padding:0 14px 8px">قد تكون أعراضاً لعطلٍ جذريٍّ واحد.</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الرسالة</th><th>النوع</th><th>التكرار</th><th>آخر ظهور</th></tr></thead>
            <tbody>
            @foreach ($siblings as $s)
                <tr>
                    <td><a href="{{ route('errors.show', $s->id) }}">{{ \Illuminate\Support\Str::limit($s->message, 80) }}</a></td>
                    <td><span class="bdg g">{{ $s->kind }}</span></td>
                    <td class="mono">{{ $s->count }}</td>
                    <td class="sub">{{ $s->last_seen?->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
@endsection
