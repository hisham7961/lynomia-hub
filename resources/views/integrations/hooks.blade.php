@extends('layouts.app')
@section('title', 'الويبهوك الوارد')
@section('content')
<div class="hero">
    <div>
        <h2>📥 الويبهوك الوارد</h2>
        <div class="sub">
            نقاطُ استقبالٍ أصلية: نظامٌ خارجيّ (n8n، نموذج، خدمة) يستدعي رابطاً موقّعاً
            فيُخزَّن ما أرسله حدثاً — الجسر الذي يُدخِل البيانات للنظام. بلا خدمةٍ خارجية.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">↪ مركز التكاملات</a>
</div>

{{-- ═ إنشاء نقطة ═ --}}
<div class="card">
    <h3>➕ نقطةُ استقبالٍ جديدة</h3>
    <form method="POST" action="{{ route('hooks.store') }}" class="frm">
        @csrf
        <label>الاسم<input class="inp" name="name" required maxlength="190" placeholder="استقبال من n8n / نموذج الموقع"></label>
        <label>اسم الحدث <span class="sub">(اختياري — يُعاد في الرد ويُصنّف الحمولة)</span>
            <input class="inp ltr" name="event" maxlength="120" dir="ltr" placeholder="lead.created"></label>
        <label class="chk"><input type="checkbox" name="signed" value="1" checked> توقيع HMAC (يُنشأ سرٌّ يُتحقّق به من المُرسِل — مُستحسَن)</label>
        <button class="btn p">إنشاء النقطة</button>
    </form>
</div>

{{-- ═ النقاط القائمة ═ --}}
@forelse ($hooks as $h)
    <div class="card">
        <div class="crow" style="align-items:center;gap:10px">
            <h3 style="margin:0">{{ $h->name }}
                <span class="bdg {{ $h->enabled ? 'ok' : 'wn' }}">{{ $h->enabled ? 'يعمل' : 'موقوف' }}</span>
                @if ($h->event)<span class="bdg mono ltr">{{ $h->event }}</span>@endif
            </h3>
            <span class="spacer"></span>
            <span class="sub">الطلبات: <b>{{ number_format($h->hits) }}</b>
                @if ($h->last_hit_at) · آخر استقبال {{ \Illuminate\Support\Carbon::parse($h->last_hit_at)->diffForHumans() }}@endif</span>
        </div>

        <div class="sub" style="margin-top:8px">رابطُ الاستقبال (POST):</div>
        <div class="mono ltr" style="background:var(--cd);border:1px solid var(--ln);border-radius:8px;padding:8px;overflow:auto;word-break:break-all">{{ $h->url() }}</div>

        @if (session('newhook') === $h->id && $h->secret)
            <div class="sub" style="margin-top:8px;padding:10px;border:1px solid var(--wn);border-radius:10px;background:var(--cd)">
                🔐 سرُّ التوقيع (لن يظهر ثانيةً — انسخه الآن):
                <div class="mono ltr" style="word-break:break-all"><b>{{ $h->secret }}</b></div>
                <div class="sub">وقّع الحمولة: <span class="mono ltr">X-Hub-Signature: sha256=hmac_sha256(body, secret)</span></div>
            </div>
        @elseif ($h->secret)
            <div class="sub" style="margin-top:6px">🔐 موقّع بـ HMAC — أرسل ترويسة <span class="mono ltr">X-Hub-Signature: sha256=…</span></div>
        @else
            <div class="sub" style="margin-top:6px">⚠️ بلا توقيع — أيّ من يعرف الرابط يستطيع الإرسال. احذفها وأنشئ موقّعةً للحساسة.</div>
        @endif

        <div class="crow" style="margin-top:10px">
            <form method="POST" action="{{ route('hooks.toggle', $h->id) }}">@csrf
                <button class="btn ghost xs">{{ $h->enabled ? '⏸ إيقاف' : '▶ تفعيل' }}</button></form>
            <form method="POST" action="{{ route('hooks.destroy', $h->id) }}" onsubmit="return confirm('حذفُ النقطة وسجلّها؟')">
                @csrf @method('DELETE')
                <button class="btn ghost xs danger">🗑 حذف</button></form>
        </div>

        @php $evs = $events[$h->id] ?? collect(); @endphp
        @if ($evs->count())
            <details style="margin-top:10px">
                <summary class="sub pointer">آخر {{ $evs->count() }} استقبالاً</summary>
                <div class="tblwrap"><table class="tbl mini">
                    <thead><tr><th>الوقت</th><th>من</th><th>الحمولة (مقتطف)</th></tr></thead>
                    <tbody>
                    @foreach ($evs as $e)
                        <tr>
                            <td class="sub">{{ \Illuminate\Support\Carbon::parse($e->created_at)->format('m-d H:i:s') }}</td>
                            <td class="mono ltr sub">{{ $e->ip }}</td>
                            <td class="mono ltr" style="font-size:11px">{{ \Illuminate\Support\Str::limit((string) $e->payload, 120) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </details>
        @endif
    </div>
@empty
    <div class="card"><div class="sub">لا نقاطَ استقبالٍ بعد — أنشئ واحدةً أعلاه، وانسخ رابطها إلى n8n أو أي خدمة لتُدخِل البيانات.</div></div>
@endforelse

{{-- ═ الدليل ═ --}}
<div class="card">
    <h3>📖 كيف تُرسِل؟</h3>
    <div class="sub" style="line-height:2">
        <b>١)</b> أنشئ نقطةً أعلاه وانسخ رابطها.<br>
        <b>٢)</b> أرسل <span class="mono ltr">POST</span> بجسمٍ JSON إلى الرابط. مثال:<br>
        <div class="mono ltr" style="background:var(--cd);border:1px solid var(--ln);border-radius:8px;padding:8px;margin:6px 0;white-space:pre-wrap">curl -X POST '{{ $hooks->first()?->url() ?? url('/hook/TOKEN') }}' \
  -H 'Content-Type: application/json' \
  -d '{"name":"عميل جديد","value":100}'</div>
        <b>٣)</b> إن كانت موقّعةً: أضف ترويسة <span class="mono ltr">X-Hub-Signature: sha256=&lt;hmac&gt;</span> على الجسم الخام بالسرّ.<br>
        <b>٤)</b> الرد <span class="mono ltr">{"ok":true,...}</span> يعني القبول — ويظهر الاستقبال في سجلّ النقطة.
    </div>
</div>
@endsection
