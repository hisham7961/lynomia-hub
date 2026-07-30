@extends('layouts.app')
@section('title', 'مركز الأخطاء')
@section('content')
<div class="hero">
    <div>
        <h2>🐞 مركز الأخطاء والسجلات</h2>
        <div class="sub">استثناءات PHP وأخطاء API والمتصفح والطلبات البطيئة — مجمعة بالتكرار وبمعرف الطلب</div>
    </div>
    <a class="btn ghost sm" href="{{ route('ops.index') }}">🖥️ مركز التشغيل ←</a>
</div>

<div class="toolbar">
    <form class="filters" method="GET">
        <select class="inp" name="st" onchange="this.form.submit()">
            <option value="">كل الحالات</option>
            @foreach (['جديد', 'قيد المعالجة', 'محلول'] as $s)<option @selected($st === $s)>{{ $s }}</option>@endforeach
        </select>
        <select class="inp" name="k" onchange="this.form.submit()">
            <option value="">كل الأنواع</option>
            @foreach (['php' => 'PHP', 'api' => 'API', 'js' => 'متصفح', 'slow' => 'بطيء'] as $kk => $kl)<option value="{{ $kk }}" @selected($k === $kk)>{{ $kl }}</option>@endforeach
        </select>
        <noscript><button class="btn sm">تصفية</button></noscript>
    </form>
</div>

<div class="card pad0">
    <table class="tbl">
        <thead><tr><th>الخطأ</th><th>النوع</th><th>التكرار</th><th>آخر ظهور</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($rows as $e)
            <tr>
                <td style="max-width:480px">
                    <b>{{ \Illuminate\Support\Str::limit($e->message, 90) }}</b>
                    <div class="sub">
                        {{ $e->file ? \Illuminate\Support\Str::limit(str_replace(base_path() . '/', '', $e->file), 50) . ($e->line ? ':' . $e->line : '') : '' }}
                        {{ $e->url ? ' · ' . \Illuminate\Support\Str::limit($e->url, 45) : '' }}
                        {{ $e->user_id ? ' · ' . ($users[$e->user_id] ?? '؟') : '' }}
                        @if ($e->request_id) · <span class="mono ltr" style="font-size:10px">{{ substr($e->request_id, 0, 8) }}</span>@endif
                    </div>
                    @if ($e->trace)
                        <details><summary class="sub" style="cursor:pointer">Stack trace ▾</summary>
                            <pre class="mono ltr" style="font-size:10.5px;background:var(--pss);border-radius:8px;padding:8px;margin-top:4px;max-height:220px;overflow:auto">{{ \Illuminate\Support\Str::limit($e->trace, 4000) }}</pre>
                        </details>
                    @endif
                </td>
                <td><span class="bdg {{ $e->kind === 'slow' ? 'wn' : ($e->kind === 'js' ? 'g' : 'bad') }}">{{ ['php' => 'PHP', 'api' => 'API', 'js' => 'متصفح', 'slow' => 'بطيء'][$e->kind] ?? $e->kind }}</span></td>
                <td><b>{{ $e->count }}</b></td>
                <td class="sub">{{ $e->last_seen->diffForHumans() }}</td>
                <td><span class="bdg {{ $e->status === 'محلول' ? 'ok' : ($e->status === 'جديد' ? 'bad' : 'wn') }}">{{ $e->status }}</span></td>
                <td class="acts">
                    @foreach (['قيد المعالجة', 'محلول', 'جديد'] as $to)
                        @continue($to === $e->status)
                        <form class="inline" method="POST" action="{{ route('errors.status', $e->id) }}">
                            @csrf<input type="hidden" name="to" value="{{ $to }}"><button class="btn ghost xs">{{ $to }}</button>
                        </form>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🎉</span>لا أخطاء مسجلة — النظام نظيف</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
{{ $rows->links('partials.pagination') }}
@endsection
