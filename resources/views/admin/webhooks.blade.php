@extends('layouts.app')
@section('title', 'مركز Webhooks')
@section('content')
<div class="hero">
    <div>
        <h2>🪝 مركز Webhooks</h2>
        <div class="sub">أرسل أحداث النظام لأي نظام خارجي (n8n، Zapier، خادمك) بطلب POST موقّع — مع إعادات تلقائية وسجل محاولات</div>
    </div>
</div>

<div class="kids">
    <div class="card kid">
        <h3>اشتراك جديد</h3>
        <form method="POST" action="{{ route('webhooks.store') }}" class="frm">
            @csrf
            <label>الاسم<input class="inp" name="name" value="{{ old('name') }}" required maxlength="120" placeholder="n8n — أتمتة التذاكر"></label>
            <label>رابط الاستقبال (URL)<input class="inp ltr" name="url" value="{{ old('url') }}" required maxlength="500" placeholder="https://n8n.example.com/webhook/abc" dir="ltr"></label>
            <label>الأحداث
                <input class="inp ltr" name="events" value="{{ old('events', '*') }}" required dir="ltr"
                       placeholder="tickets.created, projects.*, *.status">
            </label>
            <div class="sub">صيغ مقبولة: <span class="mono ltr">*</span> (كل شيء) · <span class="mono ltr">tickets.created</span> · <span class="mono ltr">projects.*</span> · <span class="mono ltr">*.status</span> — الأحداث: <span class="mono ltr">created</span> و<span class="mono ltr">updated</span> و<span class="mono ltr">status</span>، ومفاتيح الوحدات ترينها بالمرور على القائمة الجانبية أو في ROADMAP</div>
            <button class="btn">إنشاء الاشتراك</button>
        </form>
    </div>

    <div class="card kid">
        <h3>كيف أتحقق من التوقيع؟</h3>
        <div class="sub" style="line-height:2">
            كل طلب يحمل ترويسات:<br>
            <span class="mono ltr">X-Hub-Event</span> — اسم الحدث مثل <span class="mono ltr">tickets.created</span><br>
            <span class="mono ltr">X-Hub-Event-Id</span> — معرف فريد للحدث (خزّنه وتجاهل المكرر)<br>
            <span class="mono ltr">X-Hub-Signature</span> — <span class="mono ltr">sha256=hex(hmac_sha256(body, secret))</span><br>
            في n8n: عقدة Crypto بعملية HMAC-SHA256 على جسم الطلب الخام بالسر، وقارن الناتج بالترويسة.<br>
            الفاشل يُعاد تلقائياً (١ ← ٥ ← ١٥ ← ٦٠ ← ١٨٠ ← ٧٢٠ دقيقة)، وبعد ١٠ إخفاقات متتالية يوقف الاشتراك ساعةً ثم يستأنف.
        </div>
    </div>
</div>

<div class="card pad0">
    <div class="tblwrap">
    <table class="tbl">
        <thead><tr><th>الاشتراك</th><th>الأحداث</th><th>السر</th><th>آخر إرسال</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($hooks as $h)
            <tr>
                <td><b>{{ $h->name }}</b><div class="sub mono ltr" style="font-size:11px">{{ \Illuminate\Support\Str::limit($h->url, 55) }}</div></td>
                <td><span class="mono ltr" style="font-size:11px">{{ \Illuminate\Support\Str::limit($h->events, 40) }}</span></td>
                <td>
                    <details>
                        <summary class="sub pointer">••••••• كشف</summary>
                        <code class="mono ltr" style="font-size:10.5px;user-select:all;word-break:break-all">{{ $h->secret }}</code>
                    </details>
                </td>
                <td class="sub">
                    @if ($h->last_at)
                        <span class="bdg {{ $h->last_ok ? 'ok' : 'bad' }}">{{ $h->last_ok ? '✓' : '✗' }}</span>
                        {{ $h->last_at->diffForHumans() }} · {{ $h->runs }} إرسال
                    @else لم يُرسل بعد @endif
                </td>
                <td>
                    @if ($h->paused_until && $h->paused_until->isFuture())
                        <span class="bdg wn">⏸ موقوف حتى {{ $h->paused_until->format('H:i') }}</span>
                    @else
                        <span class="bdg {{ $h->active ? 'ok' : '' }}">{{ $h->active ? 'مفعّل' : 'معطّل' }}</span>
                    @endif
                    @if ($h->fail_streak)<span class="bdg bad">{{ $h->fail_streak }} فشل متتالٍ</span>@endif
                </td>
                <td class="acts">
                    <a class="btn ghost xs" href="{{ route('webhooks.log', $h->id) }}">📜 السجل ({{ $h->deliveries_count }})</a>
                    <form class="inline" method="POST" action="{{ route('webhooks.test', $h->id) }}">@csrf<button class="btn ghost xs">🧪 اختبار</button></form>
                    <form class="inline" method="POST" action="{{ route('webhooks.toggle', $h->id) }}">@csrf<button class="btn ghost xs">{{ $h->active ? '⏸ تعطيل' : '▶️ تفعيل' }}</button></form>
                    <form class="inline" method="POST" action="{{ route('webhooks.destroy', $h->id) }}" data-confirm="حذف الاشتراك وكل سجل محاولاته؟">@csrf @method('DELETE')<button class="btn ghost xs danger">🗑</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🪝</span>لا اشتراكات بعد — أنشئ أول اشتراك من النموذج أعلاه</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
