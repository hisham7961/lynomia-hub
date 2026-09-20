@extends('layouts.app')
@section('title', 'مركز الذكاء الاصطناعي')
@section('content')

<div class="hero">
    <div>
        <h2>🤖 مركز الذكاء الاصطناعي</h2>
        <div class="sub">بوّابةُ النماذج على خادمِ Hub نفسِه — مربوطةً داخليّاً بلا كشفٍ للإنترنت.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        {{-- شاشةٌ بلا رابطٍ ميزةٌ مخفيّة — ورابطُها بابُ متحكّمِها نفسُه --}}
        <a class="btn sm" href="{{ route('ai.providers.index') }}">🔌 المزوّدون والاعتمادات</a>
        @if ($configured)
            <form method="POST" action="{{ route('ai.test') }}">@csrf
                <button class="btn sm">🔌 اختبار الاتصال</button>
            </form>
        @endif
    </div>
</div>

{{-- ═══ حالةُ التشغيل — **أربعُ درجاتٍ لا درجتان** ═══
     وجودُ عنوانٍ ومفتاحٍ يعني «مهيّأٌ ولم يُختبر» لا «يعمل». ونجاحُ الفحصِ يعني
     أنّ البوّابةَ تردّ وتقبل المفتاحَ — **ولا يعني** أنّ نموذجاً ولّد إجابة. --}}
<div class="cards">
    @if (! $configured)
        <div class="stat"><span class="ico bdg wn">⚙️</span><b>غير مهيّأة</b>
            <span>{{ $whyNot }}</span></div>
    @elseif (! $probeOk)
        <div class="stat"><span class="ico bdg wn">❓</span><b>مهيّأة ولم تُختبر</b>
            <span>الإعدادُ محفوظٌ — <b>ولا دليلَ أنّ البوّابةَ تردّ</b>. اختبر الاتصال.</span></div>
    @elseif (! $enabled)
        <div class="stat"><span class="ico bdg">⏸️</span><b>الفحصُ ناجحٌ والتكاملُ مطفأ</b>
            <span>لا طلبَ يُرسَل حتّى يُفعَّل</span></div>
    @else
        <div class="stat"><span class="ico bdg ok">✅</span><b>الفحصُ ناجحٌ والتكاملُ مُشغَّل</b>
            <span>{{ $probedAt ? 'آخر فحص: ' . $probedAt : '' }}</span></div>
    @endif

    {{-- الدرجةُ الرابعة: توليدٌ فعليّ — لا يُدَّعى قبل المرحلة ٢ --}}
    <div class="stat">
        <span class="ico bdg {{ $genOk ? 'ok' : '' }}">🧪</span>
        <b>{{ $genOk ? 'توليدٌ تحقّق' : 'التوليدُ لم يُختبر' }}</b>
        <span>{{ $genOk ? 'وُلِّدت إجابةٌ فعليّةٌ من نموذج' : 'يُختبَر في المرحلة ٢ — فحصُ الاتصالِ لا يُغني عنه' }}</span>
    </div>

    <div class="stat"><span class="ico">🔗</span><b class="mono ltr">{{ $url ?: '—' }}</b>
        <span>عنوان البوّابة{{ $port ? ' · منفذٌ معتمَد ' . $port : '' }}</span></div>

    <div class="stat"><span class="ico">🔑</span><b class="mono ltr">{{ $keyMask ?: '—' }}</b>
        <span>مفتاح الإدارة {{ $hasKey ? '(محفوظٌ مشفّراً)' : '(غير محفوظ)' }}</span></div>

    @if ($configured && ! $loopback)
        <div class="stat"><span class="ico bdg wn">🌐</span><b>عنوانٌ غيرُ محلّيّ</b>
            <span>البوّابةُ تحمل مفاتيحَ المزوّدين — يُنصَح بإبقائها على 127.0.0.1</span></div>
    @endif
</div>

@if ($configured && ! $probeOk && $enabled)
    <div class="card" style="border-inline-start:3px solid var(--wn,#e67e22)">
        <b>⚠️ التكاملُ مُفعَّلٌ ولم ينجح فحصُ اتصالٍ على هذا الإعداد.</b>
        <div class="sub">أيُّ تغييرٍ للعنوانِ أو المفتاحِ يُبطل نتيجةَ الفحصِ السابقةَ تلقائيّاً — فأعد الاختبار.</div>
    </div>
@endif

{{-- نتيجةُ آخرِ فحصٍ إن وُجدت — ولا يُطلَق الفحصُ مع فتحِ الصفحة --}}
@if (! empty($probe))
    <div class="card">
        <h3 class="cardtitle">نتيجةُ آخرِ فحص</h3>
        <div class="sub">{{ \App\Support\ConnectionProbe::line($probe) }}</div>
        @if (($probe['detail']['models'] ?? null) !== null)
            <div class="sub" style="margin-top:4px">نماذجُ مُعلَنةٌ في البوّابة:
                <b>{{ $probe['detail']['models'] }}</b>
                @if ((int) $probe['detail']['models'] === 0)
                    — البوّابةُ تعمل ولم يُربَط بها مزوّدٌ بعد (المرحلة ٢)
                @endif
            </div>
        @endif
    </div>
@endif

{{-- ═══ إعدادُ البوّابة ═══ --}}
<div class="card">
    <h3 class="cardtitle">⚙️ إعدادُ البوّابة</h3>
    <div class="sub" style="margin-bottom:10px">
        البوّابةُ عمليّةٌ مستقلّةٌ تعمل بجوارِ Hub على الخادمِ نفسِه — راجع
        <code class="mono ltr">deploy/litellm/README.md</code>. وقبل التنصيب شغّل
        <code class="mono ltr">bash deploy/litellm/preflight.sh</code> على الخادم.
    </div>

    <form method="POST" action="{{ route('ai.save') }}">
        @csrf
        <div class="grid2">
            <label>عنوان البوّابة<br>
                <input type="url" name="url" class="inp ltr" dir="ltr" style="width:100%"
                       value="{{ old('url', $url) }}" placeholder="http://127.0.0.1:4000">
                <span class="sub">المتوقَّع محلّيّ — لا نطاقٌ عامّ.</span>
                @error('url')<span class="sub" style="color:var(--bad,#c0392b)">{{ $message }}</span>@enderror
            </label>

            <label>مفتاح الإدارة<br>
                <input type="password" name="key" class="inp ltr" dir="ltr" style="width:100%"
                       autocomplete="new-password" placeholder="{{ $hasKey ? 'محفوظٌ — اتركه فارغاً لإبقائه' : 'sk-…' }}">
                <span class="sub">يُحفَظ مشفّراً ولا يُعرَض ثانيةً. الفارغُ يُبقي المحفوظ.</span>
                @error('key')<span class="sub" style="color:var(--bad,#c0392b)">{{ $message }}</span>@enderror
            </label>

            <label>مهلة الاتصال (ثانية)<br>
                <input type="number" name="timeout_connect" class="inp" min="1" max="30"
                       value="{{ old('timeout_connect', $timeouts['connect']) }}">
            </label>

            <label>مهلة القراءة (ثانية)<br>
                <input type="number" name="timeout_read" class="inp" min="1" max="300"
                       value="{{ old('timeout_read', $timeouts['read']) }}">
            </label>
        </div>

        <label class="chip" style="cursor:pointer;margin-top:10px" for="ai-enabled">
            <input type="checkbox" id="ai-enabled" name="enabled" value="1" @checked(old('enabled', $enabled))>
            تفعيلُ التكامل
        </label>
        <div class="sub" style="margin-top:4px">لا يُفعَّل إلّا بعد نجاحِ اختبارِ الاتصال — وإلّا فأوّلُ طلبٍ يفشل.</div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn">💾 حفظ</button>
        </div>
    </form>

    @if ($hasKey)
        <form method="POST" action="{{ route('ai.forget') }}" style="margin-top:8px;border-top:1px solid var(--line,#eee);padding-top:8px"
              onsubmit="return confirm('سيُمسح مفتاحُ الإدارة ويُطفأ التكامل. متابعة؟')">
            @csrf
            <button class="btn ghost sm">🗑️ مسحُ المفتاح وإطفاءُ التكامل</button>
        </form>
    @endif
</div>

{{-- ═══ أقسامُ المراحلِ القادمة — تُعرَض ولا يُوعَد بها زرٌّ لا يعمل ═══ --}}
<div class="card">
    <h3 class="cardtitle">📍 ما لم يُنفَّذ بعد</h3>
    <div class="sub" style="margin-bottom:8px">
        تُعرَض هذه الأقسامُ لتُعرَف خريطةُ الطريق — <b>وبلا أزرارٍ</b>، فزرٌّ لا
        يفعل شيئاً أسوأُ من غيابِه.
    </div>
    <table class="tbl">
        <thead><tr><th>القسم</th><th>ماذا سيقدّم</th><th>المرحلة</th></tr></thead>
        <tbody>
            <tr><td>المزوّدون والحسابات</td>
                <td>متطلّباتُ كلِّ مزوّدٍ، وإدخالُ اعتمادِه من هذه الواجهة، واختبارُ ربطِه</td>
                <td><span class="bdg">٢</span></td></tr>
            <tr><td>كتالوج النماذج</td>
                <td>النماذجُ وقدراتُها وحدودُها، وحالتُها: في الكتالوج · يحتاج ربطاً · مربوط · اختُبر · متاحٌ لك · متعذّر</td>
                <td><span class="bdg">٢</span></td></tr>
            <tr><td>مساعد Hub («اسأل Hub»)</td>
                <td>محادثةٌ تقرأ المشاريعَ والمهامَّ <b>بصلاحيّةِ صاحبِها</b> وتذكر مصادرَ إجابتِها</td>
                <td><span class="bdg">٣</span></td></tr>
            <tr><td>سياساتُ الاختيارِ والبدائل</td>
                <td>نموذجٌ أساسيٌّ وبدائلُ مرتّبة، بأسبابٍ مفرّقة: رصيد · معدّل · تعطّل · سياق · اعتماد</td>
                <td><span class="bdg">٤</span></td></tr>
            <tr><td>الاستهلاكُ والحدود</td>
                <td>ما أُنفق، وحدودُ الاستخدامِ والتكلفة ومراقبتُها</td>
                <td><span class="bdg">٤</span></td></tr>
        </tbody>
    </table>
</div>
@endsection
