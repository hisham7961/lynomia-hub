@extends('layouts.app')
@section('title', 'سجل Webhook')
@section('content')
<div class="hero">
    <div>
        <h2>📜 سجل المحاولات — {{ $h->name }}</h2>
        <div class="sub mono ltr">{{ $h->url }}</div>
    </div>
    <a class="btn ghost sm" href="{{ route('webhooks.index') }}">🪝 كل الاشتراكات ←</a>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr><th>الحدث</th><th>الجسم</th><th>الرد</th><th>المحاولات</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($rows as $d)
            <tr>
                <td><b class="mono ltr" style="font-size:12px">{{ $d->event }}</b>
                    <div class="sub">{{ $d->created_at->diffForHumans() }} · <span class="mono ltr" style="font-size:10px">{{ substr($d->event_id, 0, 8) }}</span></div></td>
                <td style="max-width:340px">
                    <details><summary class="sub" style="cursor:pointer">JSON ▾</summary>
                        <pre class="mono ltr" style="font-size:10.5px;background:var(--pss);border-radius:8px;padding:8px;margin-top:4px;max-height:200px;overflow:auto">{{ \Illuminate\Support\Str::limit($d->payload, 3000) }}</pre>
                    </details>
                </td>
                <td class="sub">{{ $d->code ? 'HTTP ' . $d->code . ($d->ms !== null ? ' · ' . $d->ms . 'ms' : '') : '—' }}
                    @if ($d->error)<div class="txt-bad" style="font-size:11px">{{ \Illuminate\Support\Str::limit($d->error, 70) }}</div>@endif</td>
                <td>{{ $d->tries }}@if ($d->next_at && $d->state === 'queued')<div class="sub">التالية {{ $d->next_at->diffForHumans() }}</div>@endif</td>
                <td><span class="bdg {{ $d->state === 'sent' ? 'ok' : ($d->state === 'failed' ? 'bad' : 'wn') }}">{{ ['sent' => 'أُرسل', 'failed' => 'فشل', 'queued' => 'بالانتظار', 'sending' => 'قيد الإرسال'][$d->state] ?? $d->state }}</span></td>
                <td class="acts">
                    <form class="inline" method="POST" action="{{ route('webhooks.resend', [$h->id, $d->id]) }}">@csrf<button class="btn ghost xs">🔁 إعادة الآن</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">📭</span>لا محاولات بعد — جرّب زر الاختبار أو أنشئ سجلاً في وحدة مشمولة</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
{{ $rows->links('partials.pagination') }}
@endsection
