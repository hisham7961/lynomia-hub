@extends('layouts.app')
@section('title', 'اكتشافُ النماذج')
@section('content')

{{-- ═══ اكتشافُ النماذجِ واختيارُها — بلا كتابةِ معرّفٍ بيد ═══

     **العيبُ الذي وُلدت منه هذه الشاشة:** كان «الاكتشافُ» يقرأ سجلَّ البوّابةِ
     وحدَه — وهو فارغٌ لمزوّدٍ اعتمادُه جديد. فيُدفَع المديرُ إلى التسجيلِ
     اليدويّ، فيبحث عن معرّفِ النموذجِ **خارجَ النظام** ويكتبه بيدِه.

     **ولا معلومةَ تُخمَّن هنا.** كلُّ مرشَّحٍ يحمل مصدرَه، وما لا يُعرَف يُقال
     «غيرُ معروف» — ولا يُقرَأ ذلك «غيرُ مدعوم». --}}

<div class="hero">
    <div>
        <h2>🔍 اكتشافُ نماذجِ {{ $provider->label }}</h2>
        <div class="sub">اختر ما تحتاجه واستورده <b>بضغطة</b> — ولا تكتب معرّفَ نموذجٍ بيدِك.</div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a class="btn sm" href="{{ route('ai.models.index', $provider) }}">🧠 نماذجُ هذا المزوّد</a>
        <a class="btn sm" href="{{ route('ai.providers.index') }}">🔌 المزوّدون</a>
    </div>
</div>

@include('ai._sections')

{{-- ═══ من أين جاءت هذه القائمة؟ — المصدرُ يُعلَن ولا يُخفى ═══ --}}
<div class="card">
    <h3>مصادرُ الاكتشاف</h3>
    <div class="sub mut">
        <b>كلُّها قراءةٌ بكلفةِ صفر</b> — لا طلبَ يبلغ المزوّدَ من هذه الشاشة، ولا يُولَّد حرفٌ واحد.
    </div>
    <table class="tbl">
        <thead><tr><th>المصدر</th><th>ما يُثبِته</th><th>العدد</th><th>الحال</th></tr></thead>
        <tbody>
            <tr>
                <td><span class="mono ltr">gateway_registry</span></td>
                <td>نشرٌ <b>قائمٌ فعلاً</b> عند البوّابةِ مربوطٌ باعتمادِك</td>
                <td>{{ $found['sources']['gateway_registry']['count'] }}</td>
                <td>@if ($found['sources']['gateway_registry']['ok'])<span class="bdg ok">قُرئ</span>
                    @else<span class="bdg wn">تعذّر</span>
                         <span class="mut">{{ $found['sources']['gateway_registry']['error'] }}</span>@endif</td>
            </tr>
            <tr>
                <td><span class="mono ltr">litellm_catalog</span></td>
                <td>ما <b>تعرفه</b> البوّابةُ عن نماذجِ هذا المزوّدِ في السوق</td>
                <td>{{ $found['sources']['litellm_catalog']['count'] }}</td>
                <td>@if ($found['sources']['litellm_catalog']['ok'])<span class="bdg ok">قُرئ</span>
                    @else<span class="bdg wn">تعذّر</span>
                         <span class="mut">{{ $found['sources']['litellm_catalog']['error'] }}</span>@endif</td>
            </tr>
        </tbody>
    </table>
    <div class="sub mut">
        ⚠️ <b>وجودُ نموذجٍ في الكتالوجِ لا يعني أنّ اعتمادَك يبلغه</b> — قد يكون محجوباً عن حسابِك.
        والإثباتُ الوحيدُ فاحصُ الاعتماد <b>B</b>، وهو يُنفق بإقرارِك.
    </div>
    @if ($found['truncated'])
        <div class="sub mut">
            ✂️ <b>القائمةُ أطولُ ممّا عُرض</b> — عُرض أوّلُ {{ \App\Support\Ai\Catalog\AiModelSources::MAX_CANDIDATES }} مرشَّحاً.
            استعمل الترشيحَ للوصولِ إلى ما تريد.
        </div>
    @endif
    @if ($found['unowned'] > 0)
        <div class="sub mut">
            ℹ️ عند البوّابةِ {{ $found['unowned'] }} نموذجاً مسجّلاً <b>باعتمادٍ غيرِ اعتمادِك</b> — فلا يُنسَب إليك.
        </div>
    @endif
</div>

@if (! $yielded && $bothRead)
    <div class="card">
        <h3>لا اكتشافَ تلقائيَّ لهذا المزوّد</h3>
        <div class="sub">
            قُرئ المصدرانِ كلاهما بلا عطل، ولم يُعلِن أيٌّ منهما نموذجاً لهذا المزوّد.
            <b>والقائمةُ المخمّنةُ أسوأُ من لا قائمة</b> — فلن نعرض عليك ما لا نعرفه.
            سجّل نموذجاً يدويّاً من <a href="{{ route('ai.models.index', $provider) }}">شاشةِ النماذج</a>
            بمعرّفِه كما يكتبه المزوّدُ حرفاً.
        </div>
    </div>
@elseif (! $yielded)
    <div class="card">
        <h3>تعذّرت القراءة</h3>
        <div class="sub">
            <b>لم يُقرَأ أحدُ المصدرَين</b> — فغيابُ النماذجِ هنا عُطلٌ لا حقيقة.
            راجِع الجدولَ أعلاه، ثمّ أعِد المحاولة.
        </div>
    </div>
@endif

{{-- ═══ الترشيح ═══ --}}
<div class="card">
    <form method="GET" action="{{ route('ai.models.browse', $provider) }}" class="grid">
        <label><span>الوضع</span>
            <select name="mode" onchange="this.form.submit()">
                <option value="">كلُّ الأوضاع</option>
                @foreach ($modes as $m)
                    <option value="{{ $m }}" @selected($mode === $m)>{{ $m }}</option>
                @endforeach
            </select></label>
        <label><span>بحثٌ في المعرّف</span>
            <input type="text" name="q" class="ltr" maxlength="80" value="{{ $q }}"></label>
        <div style="align-self:end"><button class="btn sm">🔎 رشِّح</button></div>
    </form>
</div>

{{-- ═══ الاختيارُ والتبنّي ═══ --}}
<div class="card">
    <h3>المرشَّحون <span class="mut">({{ count($candidates) }})</span></h3>

    @if (count($candidates) === 0)
        <div class="sub mut">
            لا مرشَّحَ يطابق. @if ($q !== '' || $mode !== '')وسِّع الترشيحَ أو امسحه.@endif
        </div>
    @elseif (! ($manage ?? true))
        <div class="sub mut">العرضُ للاطّلاع — والتبنّي يحتاج صلاحيّةَ إدارة.</div>
    @endif

    @if (count($candidates) > 0 && ($manage ?? true))
    <form method="POST" action="{{ route('ai.models.adopt', $provider) }}">@csrf
        <div class="sub">
            {{-- تبديلٌ ضمنَ هذا النموذجِ وحدَه — بلا صنفٍ وسيطٍ ولا دالّةٍ عامّة --}}
            <button class="btn sm" type="button"
                    onclick="this.closest('form').querySelectorAll('input[name=&quot;picks[]&quot;]').forEach(c=>c.checked=true)">☑️ اختر الكلّ</button>
            <button class="btn sm" type="button"
                    onclick="this.closest('form').querySelectorAll('input[name=&quot;picks[]&quot;]').forEach(c=>c.checked=false)">☐ امسح الاختيار</button>
        </div>

        <table class="tbl">
            <thead><tr>
                <th></th><th>المعرّفُ عند المزوّد</th><th>الوضع</th>
                <th>القدرات</th><th>السياق</th><th>الإتاحة</th><th>الحال</th>
            </tr></thead>
            <tbody>
            @foreach ($candidates as $c)
                @php($caps = collect((array) $c['capabilities'])
                        ->filter(fn ($f) => \App\Support\Tri::allowsExecution(is_array($f) ? ($f['v'] ?? null) : $f))
                        ->keys()->all())
                @php($ctx = ((array) $c['limits'])['max_input_tokens'] ?? null)
                <tr>
                    <td>
                        @if ($c['already_imported'])
                            <span class="mut">—</span>
                        @elseif (! \App\Support\Ai\Catalog\AiModelSources::adoptableInOneClick($c['availability'] ?? ''))
                            <span class="mut" title="جذعُ عائلةٍ — يلزمه معرّفُك الكامل">✋</span>
                        @else
                            <input type="checkbox" name="picks[]"
                                   value="{{ $c['source'] }}|{{ $c['upstream_model'] }}">
                        @endif
                    </td>
                    <td><b class="mono ltr">{{ $c['upstream_model'] }}</b></td>
                    <td>{{ $c['mode'] ?? '—' }}</td>
                    <td>
                        @if ($caps === [])
                            <span class="mut">غيرُ معروفة</span>
                        @else
                            @foreach ($caps as $k)<span class="bdg">{{ $k }}</span>@endforeach
                        @endif
                    </td>
                    <td>{{ is_numeric(is_array($ctx) ? ($ctx['v'] ?? null) : $ctx)
                            ? number_format((float) (is_array($ctx) ? $ctx['v'] : $ctx))
                            : 'غيرُ معروف' }}</td>
                    <td>
                        @php($av = (string) ($c['availability'] ?? ''))
                        @if ($av === \App\Support\Ai\Catalog\AiModelSources::AVAIL_REGISTERED)
                            <span class="bdg ok" title="منشورٌ عند البوّابةِ بمرجعِ اعتمادِك">مُسجَّل</span>
                        @elseif ($av === \App\Support\Ai\Catalog\AiModelSources::AVAIL_ACCOUNT)
                            <span class="bdg wn" title="جذعُ عائلةٍ — معرّفُك الحقيقيُّ يحمل لاحقةَ حسابِك">جذعُ عائلة</span>
                        @else
                            <span class="bdg" title="يعرفه الكتالوجُ — ولا يُثبِت أنّ حسابَك يبلغه">كتالوج</span>
                        @endif
                        <span class="mut mono ltr">{{ $c['source'] }}</span>
                    </td>
                    <td>
                        @if ($c['already_imported'])<span class="bdg ok">في Hub</span>@endif
                        @if ($c['deprecated_on'])<span class="bdg wn">يُطوى {{ $c['deprecated_on'] }}</span>@endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="sub mut">
            <b>والإتاحةُ ثلاثُ درجاتٍ لا واحدة:</b>
            <b>مُسجَّل</b> = منشورٌ عند البوّابةِ بمرجعِ اعتمادِك ·
            <b>كتالوج</b> = تعرفه البوّابةُ و<b>لا يُثبِت أنّ حسابَك يبلغه</b> ·
            <b>جذعُ عائلة</b> = مفتاحُ تسعيرٍ لا معرّفَ نموذج، والحقيقيُّ يحمل لاحقةَ حسابِك
            فلا يُتبنّى بضغطة — أكمِله من «➕ تسجيلُ نموذجٍ يدويّاً» في
            <a href="{{ route('ai.models.index', $provider) }}">شاشةِ النماذج</a>.
            <b>ولا شيءَ منها إثباتُ وصول</b> — الإثباتُ فاحصُ B وحدَه.
        </div>
        <div class="sub mut">
            الاسمُ الداخليُّ في Hub <b>يُولَّد تلقائيّاً</b> من معرّفِ المزوّد — وتستطيع تغييرَه لاحقاً في التهيئة.
            <b>والتبنّي لا يُفعِّل</b>: النموذجُ يولد مُعطَّلاً حتّى تُفعّلَه صراحةً.
        </div>
        <div><button class="btn">⬇️ تبنَّ المُختار <b>مُعطَّلاً</b></button></div>
    </form>
    @endif
</div>

@endsection
