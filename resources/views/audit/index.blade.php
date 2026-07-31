@extends('layouts.app')
@section('title', 'سجل التدقيق')
@section('content')
<div class="sub" style="margin-bottom:10px">🔗 السجل مسلسل بتجزئة تشابكية — لا يُعدَّل ولا يُحذف من الواجهة، وأي عبث مباشر بالقاعدة يكشفه أمر <span class="mono ltr">php artisan hub:audit-verify</span>. تشمل السلسلة أيضاً عمليات التصدير و<b>عرض الأسرار</b>.</div>
<div class="toolbar">
    <form class="filters" method="GET">
        <select class="inp" name="module" onchange="this.form.submit()">
            <option value="">كل الوحدات</option>
            @foreach (hub_modules() as $mk => $md)<option value="{{ $mk }}" @selected(request('module') === $mk)>{{ $md['label'] }}</option>@endforeach
        </select>
        <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="اسم السجل…">
        <button class="btn sm">بحث</button>
    </form>
</div>
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>الوحدة</th><th>السجل</th><th>IP</th></tr></thead>
        <tbody>
        @forelse ($rows as $a)
            <tr>
                <td class="mono">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
                <td>{{ $a->user_name ?? '—' }}</td>
                <td><span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : '') }}">{{ $a->action }}</span></td>
                <td>{{ hub_mod($a->module)['label'] ?? $a->module }}</td>
                <td>{{ \Illuminate\Support\Str::limit($a->name, 44) }}
                    @if ($a->reason)<div class="sub">💬 السبب: {{ \Illuminate\Support\Str::limit($a->reason, 70) }}</div>@endif
                    @if ($a->before || $a->after)
                        <details><summary class="sub" style="cursor:pointer">التغيير ▾</summary>
                            <pre class="mono ltr" style="font-size:11px;background:var(--pss);border-radius:8px;padding:8px;margin-top:4px;max-width:420px;overflow:auto">{{ json_encode(['قبل' => json_decode($a->before ?? 'null'), 'بعد' => json_decode($a->after ?? 'null')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                        </details>
                    @endif
                </td>
                <td class="mono ltr" @if ($a->device) title="{{ $a->device }}" @endif>{{ $a->ip }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">لا سجلات</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
{{ $rows->links('partials.pagination_simple') }}
@endsection
