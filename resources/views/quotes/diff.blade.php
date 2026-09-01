@extends('layouts.app')
@section('title', 'مقارنة نسخ العرض')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><a href="{{ route('m.show', ['quotes', $q->id]) }}">العرض {{ $q->doc_no }}</a><span aria-hidden="true">‹</span><b>مقارنة النسخ</b></nav>
        <h2>🔀 ما تغيّر في العرض</h2>
        <div class="sub">النسخةُ الحاليّة ({{ (int) $q->version }}) مقابل النسخة {{ $baseVersion ?? '—' }}</div>
    </div>
    <a class="btn ghost sm" href="{{ route('m.show', ['quotes', $q->id]) }}">← عودة للعرض</a>
</div>

@if ($versions->count() > 1)
    <div class="card">
        <div class="crow" style="flex-wrap:wrap;gap:6px">
            <span class="sub">قارِن بنسخة:</span>
            @foreach ($versions->sortByDesc('version') as $v)
                @if ((int) $v->version < (int) $q->version)
                    <a class="chip {{ $baseVersion === $v->version ? 'on' : '' }}" href="{{ route('quotes.diff', ['id' => $q->id, 'v' => $v->version]) }}">نسخة {{ $v->version }}</a>
                @endif
            @endforeach
        </div>
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">التغييرات <span class="bdg {{ empty($changes) ? 'g' : 'wn' }}">{{ count($changes) }}</span></h3>
    @if (empty($changes))
        <div class="empty">لا فروقَ في الحقول التجاريّة بين النسختين — أو لا نسخةَ سابقةٌ للمقارنة.</div>
    @else
        <div class="tblwrap"><table>
            <thead><tr><th>الحقل</th><th>قبل</th><th>بعد</th></tr></thead>
            <tbody>
            @foreach ($changes as $c)
                <tr>
                    <td><b>{{ $c['label'] }}</b></td>
                    <td class="sub" style="text-decoration:line-through">{{ \Illuminate\Support\Str::limit($c['old'] ?: '—', 80) }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($c['new'] ?: '—', 80) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
        <div class="sub" style="margin-top:6px">مقارنةٌ على مستوى العرض (سعر/نطاق/حالة/صلاحية) — من لقطات الحفظ المحفوظة.</div>
    @endif
</div>
@endsection
