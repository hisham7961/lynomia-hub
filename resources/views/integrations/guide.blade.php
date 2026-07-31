@extends('layouts.app')
@section('title', 'دليل الربط')
@section('content')
<div class="hero">
    <div>
        <h2>📖 دليل الربط</h2>
        <div class="sub">الاتجاهان كاملين: ماذا يبثّ الهَب للخارج، وأين تنزل بيانات الخارج داخل الهَب.</div>
    </div>
    <a class="btn ghost sm" href="{{ route('integrations.index') }}">← مركز التكاملات</a>
</div>

{{-- ═══ الاتجاه الأول: الهَب يبثّ ═══ --}}
<div class="card">
    <h3 class="cardtitle">⬅ الاتجاه الأول: الهَب يبثّ إليك (Webhook)</h3>
    <div class="sub" style="line-height:2;margin-bottom:10px">
        <b>١) أنشئ اشتراكاً</b> في <a href="{{ route('webhooks.index') }}">مركز Webhooks</a> برابط الاستقبال عندك
        (في n8n: عقدة <span class="mono ltr">Webhook</span> ← انسخ رابط الإنتاج).<br>
        <b>٢) اختر الأحداث</b> — بالأنماط: <span class="mono ltr">*</span> كل شيء ·
        <span class="mono ltr">tickets.created</span> حدث بعينه ·
        <span class="mono ltr">projects.*</span> كل أحداث وحدة ·
        <span class="mono ltr">*.status</span> كل تحولات الحالة.<br>
        <b>٣) تحقّق من التوقيع</b> — كل طلب موقّع HMAC-SHA256 بسرّ الاشتراك.
    </div>

    <h3 style="margin:14px 0 6px" class="sub">شكل الحمولة (POST · JSON)</h3>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">{
  "event":    "tickets.status",
  "module":   "tickets",
  "id":       "9d8b...",
  "display":  "تذكرة العميل",
  "status":   "تم الحل",
  "at":       "2026-07-31T20:00:00Z",
  "data":     { "subject": "...", "priority": "عاجلة", ... }
}</pre>
    <div class="sub">الترويسات: <span class="mono ltr">X-Hub-Event</span> ·
        <span class="mono ltr">X-Hub-Event-Id</span> (لمنع التكرار عندك) ·
        <span class="mono ltr">X-Hub-Signature</span> (توقيع HMAC).</div>

    <h3 style="margin:14px 0 6px" class="sub">تحقّق التوقيع في n8n (عقدة Code)</h3>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">const crypto = require('crypto');
const raw  = JSON.stringify($json.body);
const mine = crypto.createHmac('sha256', 'سرّ_الاشتراك').update(raw).digest('hex');
if (mine !== $json.headers['x-hub-signature']) throw new Error('توقيع غير صالح');
return $input.all();</pre>
</div>

{{-- ═══ الاتجاه الثاني: أين تنزل بياناتك ═══ --}}
<div class="card">
    <h3 class="cardtitle">➡ الاتجاه الثاني: أين تنزل بيانات n8n لصالح أي قسم؟</h3>
    <div class="sub" style="line-height:2;margin-bottom:10px">
        الجواب في سطر: <b>كل قسمٍ في النظام له نقطة استقبال</b> على
        <span class="mono ltr">POST /api/v1/{مفتاح_القسم}</span> بمفتاح API.
        المفتاح يُنشأ من <a href="{{ route('settings.edit') }}">الإعدادات</a>،
        والكتابة تخضع لنفس الصلاحيات والنطاق والتحقق والموافقات — لا بابَ خلفياً.
    </div>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">POST https://hub.example.com/api/v1/tickets
Authorization: Bearer lyn_xxxxxxxx
Idempotency-Key: n8n-run-42        // يمنع الإنشاء المزدوج عند إعادة المحاولة
Content-Type: application/json

{ "subject": "شكوى من المتجر", "priority": "عاجلة", "clientId": "9d8b..." }</pre>
    <div class="sub" style="margin-bottom:10px">
        الاستجابة تعيد السجل المُنشأ بمعرّفه — احفظه في n8n لتتابعه لاحقاً بـ
        <span class="mono ltr">PUT /api/v1/tickets/{id}</span>.
    </div>

    <h3 style="margin:14px 0 6px" class="sub">خريطة الأقسام ومفاتيحها</h3>
    <div class="tblwrap" style="max-height:420px;overflow:auto"><table class="tbl">
        <thead><tr><th>القسم</th><th>نقطة الاستقبال</th><th>الحقول الإلزامية</th><th>عدد الحقول</th></tr></thead>
        <tbody>
        @foreach ($inbound as $mk => $i)
            <tr>
                <td><b>{{ $i['label'] }}</b></td>
                <td class="mono ltr">/api/v1/{{ $mk }}</td>
                <td class="sub mono ltr">{{ $i['required'] ? implode(', ', $i['required']) : '—' }}</td>
                <td class="mono">{{ $i['fields'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

{{-- ═══ المقاييس الزمنية ═══ --}}
<div class="card">
    <h3 class="cardtitle">📈 الاتجاه الثالث: الأرقام المتحرّكة (مقاييس زمنية)</h3>
    <div class="sub" style="line-height:2;margin-bottom:10px">
        بعض الأرقام لا تُنشئ سجلاً بل <b>تتغيّر عليه</b>: المتابعون، الإعجابات، التحميلات،
        التقييم، المشاهدات. لو كُتبت في حقلٍ عادي لدهس كلُّ تحديثٍ ما قبله فلا تبقى ذاكرةٌ
        للنمو. لذلك لها نقطة استقبالٍ خاصة تحفظ <b>سلسلةً زمنية</b>:
    </div>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left">POST https://hub.example.com/api/v1/metrics
Authorization: Bearer lyn_xxxxxxxx
Content-Type: application/json

{ "points": [
  { "module": "social", "record_id": "9d8b...", "metric": "followers", "value": 12480,
    "at": "2026-07-31T08:00:00Z", "source": "n8n" },
  { "module": "posts",  "record_id": "7a1c...", "metric": "likes",     "value": 342 },
  { "module": "apps",   "record_id": "3f60...", "metric": "downloads", "value": 51200 }
] }</pre>
    <div class="sub" style="margin-bottom:10px">
        حتى ٥٠٠ نقطة في الدفعة. النقطة تُعرَّف بـ<span class="mono ltr">(module, record_id, metric, at)</span>
        — فإعادة الدفع <b>تُحدِّث ولا تكرّر</b>، ولا حاجة لـ<span class="mono ltr">Idempotency-Key</span> هنا.
        وكل نقطةٍ تمرّ بصلاحية تعديل وحدتها وبنطاق رؤيتك: لا تُسجَّل قياساتٌ على سجلٍ لا تراه.
    </div>

    <h3 style="margin:14px 0 6px" class="sub">المقاييس المعروفة (وأين تُعرَض)</h3>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>القسم</th><th>المقاييس</th><th>تُقرأ في</th></tr></thead>
        <tbody>
            <tr><td><b>السوشال ميديا</b> <span class="mono ltr sub">social</span></td>
                <td class="mono ltr">followers, reach, impressions, engagement</td>
                <td class="sub">تحليلات السوشال · صفحة الحساب</td></tr>
            <tr><td><b>منشورات ومشاهدات</b> <span class="mono ltr sub">posts</span></td>
                <td class="mono ltr">likes, comments, shares, saves, views, reach, impr, clicks</td>
                <td class="sub">تحليلات السوشال · صفحة المنشور</td></tr>
            <tr><td><b>التطبيقات</b> <span class="mono ltr sub">apps</span></td>
                <td class="mono ltr">downloads, rating, reviews, installs, crashes, dau</td>
                <td class="sub">مركز التطبيق · جودة التطبيقات</td></tr>
            <tr><td class="sub" colspan="3">وأي مقياسٍ آخر تختاره على أي وحدة — الاسم حرٌّ والقراءة عامة عبر
                <span class="mono ltr">GET /api/v1/metrics/{module}/{id}?metric=&days=</span></td></tr>
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:10px">
        <b>وبلا n8n أيضاً:</b> أمر <span class="mono ltr">php artisan hub:metrics-snapshot</span> يلتقط
        القيم الحالية من حقول السجلات يومياً فتُبنى السلسلة من نفسها — والتعديل اليدوي لأي
        حقلٍ مقيس يُسجَّل نقطةً فوراً. فلا تبدأ من فراغ.
    </div>
</div>

{{-- ═══ كتالوج الأحداث ═══ --}}
<div class="card">
    <h3 class="cardtitle">📡 كتالوج الأحداث الصادرة</h3>
    <div class="sub" style="margin-bottom:10px">
        مولَّدٌ من سجل الوحدات لا مكتوبٌ باليد — فلا يتقادم.
        لكل وحدة ثلاثة أحداث بدائية، ولبعضها <b>أحداث أعمال دلالية</b> أدقّ من مطابقة نص الحالة.
    </div>
    <div class="tblwrap" style="max-height:480px;overflow:auto"><table class="tbl">
        <thead><tr><th>الوحدة</th><th>الأحداث البدائية</th><th>أحداث الأعمال</th></tr></thead>
        <tbody>
        @foreach ($events as $mk => $e)
            <tr>
                <td><b>{{ $e['label'] }}</b></td>
                <td class="sub mono ltr">{{ implode(' · ', $e['basic']) }}</td>
                <td>
                    @forelse ($e['semantic'] as $s)<span class="bdg ok mono ltr">{{ $s }}</span> @empty<span class="sub">—</span>@endforelse
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

{{-- ═══ أودو ═══ --}}
<div class="card">
    <h3 class="cardtitle">🧩 أودو: ماذا يقرأ وأين يظهر</h3>
    <div class="sub" style="line-height:2;margin-bottom:10px">
        <b>الإعداد:</b> الرابط وقاعدة البيانات والمستخدم والمفتاح في
        <a href="{{ route('settings.edit') }}">الإعدادات</a> — والمفتاح <b>مقنّع ومشفّر</b>، ومعه زر «اختبار الاتصال».<br>
        <b>الآلية:</b> JSON-RPC مباشرٌ بلا أي اعتماديات، كل الاستدعاءات <span class="mono ltr">search_read</span> —
        <b>قراءة فقط</b>: لا يكتب الهَب في محاسبتك ولا يُنشئ فيها شيئاً أبداً.<br>
        <b>الكاش:</b> ١٠ دقائق لكل سجل، وزرّ تحديثٍ فوري على البطاقة.
    </div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الوحدة</th><th>ما الذي يُقرأ من أودو</th><th>أين تراه</th></tr></thead>
        <tbody>
        @foreach ($odooMods as $mk => $what)
            <tr>
                <td><b>{{ hub_mod($mk)['label'] ?? $mk }}</b></td>
                <td class="sub">{{ $what }}</td>
                <td class="mono ltr">/m/{{ $mk }}/{id}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="sub" style="margin-top:8px">
        <b>المطابقة عبر n8n:</b> حقول <span class="mono ltr">odooId</span> في
        <b>المالية</b> و<b>دليل الحسابات</b> و<b>قيود اليومية</b> تحفظ معرّف السجل المقابل في أودو —
        فيستطيع n8n مزامنة الاتجاهين بلا لبس.
    </div>
</div>
@endsection
