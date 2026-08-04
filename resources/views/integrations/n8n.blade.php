@extends('layouts.app')
@section('title', 'n8n — سير العمل')
@section('content')
<div class="hero">
    <div>
        <h2>🔗 n8n — محرّك سير العمل</h2>
        <div class="sub">
            n8n خدمةٌ منفصلة مفتوحة المصدر تعمل على خادمك، تُنصَّب من <span class="mono ltr">deploy/n8n</span>.
            هنا تصله بالنظام: رابطه وحالته، والجسر في الاتجاهين.
        </div>
    </div>
    <div class="spacer"></div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">↪ مركز التكاملات</a>
</div>

{{-- ═ الحالة ═ --}}
<div class="card" style="border-inline-start:4px solid {{ $url ? 'var(--ok)' : 'var(--wn)' }}">
    <h3>الحالة <span class="bdg {{ $url ? 'ok' : 'wn' }}">{{ $url ? 'موصول' : 'غير موصول' }}</span></h3>
    @if ($url)
        <div class="sub" style="line-height:2">مثيلُك: <span class="mono ltr">{{ $url }}</span> · المفتاح: {{ $hasKey ? 'محفوظ (مشفَّر)' : 'غير محفوظ' }}</div>
        <div class="crow" style="margin-top:8px">
            <a class="btn p sm ltr" href="{{ $url }}" target="_blank" rel="noopener">↗ افتح لوحة n8n</a>
        </div>
    @else
        <div class="sub">لم تُوصَل بعد — نصّب n8n على خادمك (<span class="mono ltr">deploy/n8n/README.md</span>) ثم ضع رابطه أدناه.</div>
    @endif
</div>

{{-- ═ كيف أنصّبه؟ — الخطوات أمامك لا في ملفٍ على الخادم ═ --}}
<div class="card">
    <details @if (! $url) open @endif>
        <summary style="cursor:pointer;font-weight:700;font-size:1.05em">🛠️ كيف أنصّب n8n على خادمي؟ — الخطوات الكاملة</summary>
        <div style="margin-top:12px;line-height:1.9">
            <div class="sub" style="border-inline-start:4px solid var(--wn);padding-inline-start:10px;margin-bottom:12px">
                <b>شرطٌ أساسي:</b> n8n خدمةُ <b>Node.js</b> مستقلّة — لا صفحةٌ في هذا النظام ولا كود PHP.
                يحتاج خادماً يشغّل <b>Docker</b> (VPS/خادم مخصّص بصلاحية <span class="mono ltr">root</span>).
                على استضافة PHP المشتركة (cPanel) <b>لا يعمل</b> — استعمل حينها
                <a href="{{ route('hooks.index') }}">الويبهوك الوارد</a> و<a href="{{ route('webhooks.index') }}">الصادر</a> بدلاً منه.
            </div>

            <b>١) على الخادم (VPS بصلاحية root)</b> — إن لم يكن Docker مثبَّتاً:
            <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">curl -fsSL https://get.docker.com | sh</pre>

            <b>٢) شغّل n8n من المستودع</b> (ملفات Docker Compose جاهزة مع PostgreSQL):
            <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">cd deploy/n8n
cp .env.example .env
nano .env          # املأ: N8N_HOST ، N8N_ENCRYPTION_KEY ، POSTGRES_PASSWORD
docker compose up -d</pre>

            <b>٣) وجّه نطاقاً فرعيّاً إليه</b> — n8n يستمع على <span class="mono ltr">127.0.0.1:5678</span> (لا يُكشف علناً).
            في DNS وجّه <span class="mono ltr">n8n.yourdomain.com</span> لخادمك، ثم في reverse proxy (Caddy مثالاً):
            <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">n8n.yourdomain.com {
    reverse_proxy 127.0.0.1:5678
}</pre>

            <b>٤) أنشئ حساب المالك</b> بفتح <span class="mono ltr">https://n8n.yourdomain.com</span> أول مرة،
            ثم انسخ الرابط والصقه في <b>«ربط المثيل»</b> أدناه. تمّ.

            <div class="sub" style="margin-top:10px">📖 التفاصيل الكاملة (التحديث، النسخ الاحتياطي، ربط الاتجاهين) في <span class="mono ltr">deploy/n8n/README.md</span>.</div>
        </div>
    </details>
</div>

{{-- ═ الربط ═ --}}
<div class="card">
    <h3>⚙️ ربط المثيل</h3>
    <form method="POST" action="{{ route('integrations.n8n.save') }}" class="frm">
        @csrf
        <label>رابط مثيل n8n<input class="inp ltr" name="url" value="{{ old('url', $url) }}" maxlength="300" dir="ltr" placeholder="https://n8n.yourdomain.com"></label>
        <label>مفتاح n8n API <span class="sub">(اختياري — للحالة، يُخزَّن مشفَّراً؛ اتركه فارغاً للإبقاء على المخزون)</span>
            <input class="inp ltr" type="password" name="key" value="" maxlength="500" dir="ltr" placeholder="{{ $hasKey ? '•••••• (محفوظ)' : 'n8n_api_...' }}"></label>
        <button class="btn p">حفظ الربط</button>
    </form>
</div>

{{-- ═ الجسر ═ --}}
<div class="kids">
    <div class="card kid">
        <h3>➡️ النظام إلى n8n</h3>
        <div class="sub" style="line-height:2">
            حدثٌ في النظام يُشغّل سير عملٍ في n8n:<br>
            <b>١)</b> في n8n: Workflow ببداية <b>Webhook node</b> — انسخ رابطه.<br>
            <b>٢)</b> في النظام: <a href="{{ route('webhooks.index') }}">Webhooks صادرة</a> ← أضف اشتراكاً لذلك الرابط على الحدث.<br>
            كلُّ حدثٍ يبثّ لـn8n بتوقيع HMAC فينطلق سير العمل.
        </div>
    </div>
    <div class="card kid">
        <h3>⬅️ n8n إلى النظام</h3>
        <div class="sub" style="line-height:2">
            سير عملٍ في n8n يُدخِل بياناتٍ أو يشغّل فعلاً في النظام:<br>
            <b>١)</b> في النظام: <a href="{{ route('hooks.index') }}">الويبهوك الوارد</a> ← أنشئ نقطةً، انسخ رابطها وسرّها.<br>
            <b>٢)</b> في n8n: عقدة <b>HTTP Request</b> إلى رابط النقطة بجسم JSON + ترويسة
            <span class="mono ltr">X-Hub-Signature</span> (عقدة Crypto → HMAC SHA256 بالسرّ).
        </div>
    </div>
</div>
@endsection
