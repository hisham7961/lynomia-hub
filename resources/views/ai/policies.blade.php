@extends('layouts.app')
@section('title', 'سياسات الذكاء')
@section('content')

{{-- ═══ السياساتُ — طبقةُ تضييقٍ لا نظامَ صلاحيّاتٍ موازٍ ═══

     الوصولُ الفعليّ = تخويلُ Hub ∩ سياسةُ الذكاء.
     فصفٌّ هنا **يمنع ما يُسمَح به، ولا يمنح ما يُمنَع منه أبداً**. --}}

<div class="hero">
    <div>
        <h2>🛡️ سياساتُ الذكاء</h2>
        <div class="sub">
            <b>الوصولُ الفعليّ = تخويلُ Hub ∩ السياسة.</b>
            صلاحيّةُ Hub وحدَها لا تكفي إن منعت السياسة، <b>والسياسةُ لا تمنح
            صلاحيّةً لا يملكها صاحبُها أصلاً</b>.
        </div>
    </div>
</div>

@include('ai._sections')

<div class="cards">
    <div class="stat"><span class="ico bdg">①</span><b>المنعُ يغلب</b>
        <span>صفُّ منعٍ واحدٌ يمنع مهما قابله من «اسمح» ومهما كانت أولويّتُه.</span></div>
    <div class="stat"><span class="ico bdg">②</span><b>قائمةُ سماحٍ عند أوّلِ تسمية</b>
        <span>إن سمّى صفُّ «اسمح» نموذجاً، صار بُعدُ النماذجِ قائمةَ سماحٍ — <b>وما ليس فيها ممنوع</b>.
        وبُعدٌ لم يُسمَّ لا يُضيَّق.</span></div>
    <div class="stat"><span class="ico bdg">③</span><b>الأشدُّ يفوز</b>
        <span>سقفانِ متطابقانِ يُنتجان الأصغر — <b>والتضييقُ اتّجاهٌ واحد</b>.</span></div>
    <div class="stat"><span class="ico bdg {{ $rules->where('enabled', true)->count() === 0 ? '' : 'ok' }}">④</span>
        <b>{{ $rules->where('enabled', true)->count() }} صفٍّ فاعل</b>
        <span>بلا صفٍّ واحدٍ يعمل النظامُ <b>كما كان حرفاً</b> — فلا تضييقَ بالأصل.</span></div>
</div>

@if ($rules->isEmpty())
    <div class="card empty">
        <b>لا سياسةَ مكتوبة.</b>
        <div class="sub">ولا تضييقَ إذاً: كلُّ ما يُصرَّح به في Hub يمرّ. أضِف صفّاً أدناه لتبدأ الحوكمة.</div>
    </div>
@else
<div class="card">
    <h3>الصفوف <span class="mut">({{ $rules->count() }})</span></h3>
    <div class="sub mut">مرتّبةً بالأولويّة — <b>الأصغرُ أسبق</b>، والمنعُ يغلب على كلِّ حال.</div>
    <table class="tbl">
        <thead><tr>
            <th>المفتاح</th><th>الأثر</th><th>النطاق</th><th>الأبعاد</th>
            <th>السقوف</th><th>الحال</th><th></th>
        </tr></thead>
        <tbody>
        @foreach ($rules as $r)
            <tr>
                <td>
                    <b>{{ $r->label }}</b><br>
                    <span class="mut mono ltr">{{ $r->key }}</span>
                    @if ($r->notes)<div class="sub mut">{{ $r->notes }}</div>@endif
                </td>
                <td>
                    @if ($r->effect === \App\Support\Ai\Governance\AiPolicy::DENY)
                        <span class="bdg danger">منع</span>
                    @else
                        <span class="bdg ok">سماح</span>
                    @endif
                    <div class="mut">أولويّة {{ $r->priority }}</div>
                </td>
                <td>
                    <span class="bdg">{{ $r->scope_type }}</span>
                    @if ($r->scope_id)<div class="mut mono ltr">{{ $r->scope_id }}</div>@endif
                </td>
                <td>
                    @if ($r->purpose)<div>غرض: <span class="mono ltr">{{ $r->purpose }}</span></div>@endif
                    @if ($r->provider_id)<div>مزوّد: <span class="mono ltr">{{ $r->provider_id }}</span></div>@endif
                    @if ($r->model_id)<div>نموذج: <span class="mono ltr">{{ $r->model_id }}</span></div>@endif
                    @if (! $r->purpose && ! $r->provider_id && ! $r->model_id)
                        <span class="mut">كلُّ الأبعاد</span>
                    @endif
                </td>
                <td>
                    @if ($r->allow_generation === false)<div><span class="bdg danger">لا توليد</span></div>@endif
                    @if ($r->allow_tools === false)<div><span class="bdg wn">بلا أدوات</span></div>@endif
                    @if ($r->max_output_tokens)<div class="mut">مخرَج ≤ {{ $r->max_output_tokens }}</div>@endif
                    @if ($r->max_calls_per_request)<div class="mut">نداءات ≤ {{ $r->max_calls_per_request }}</div>@endif
                </td>
                <td>{!! $r->enabled ? '<span class="bdg ok">فاعل</span>' : '<span class="bdg">مُعطَّل</span>' !!}</td>
                <td>
                    @if ($canManage)
                    <div class="row" style="gap:6px;flex-wrap:wrap">
                        <form method="POST" action="{{ route('ai.policies.toggle', $r) }}">@csrf
                            <button class="btn sm">{{ $r->enabled ? '⏸️ تعطيل' : '▶️ تفعيل' }}</button></form>
                        <form method="POST" action="{{ route('ai.policies.destroy', $r) }}"
                              onsubmit="return confirm('إزالةُ السياسة «{{ $r->label }}»؟')">
                            @csrf @method('DELETE')
                            <button class="btn sm danger">🗑️ إزالة</button></form>
                    </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

@if ($canManage)
<div class="card">
    <h3>صفٌّ جديد</h3>
    <div class="sub mut">
        <b>اتركِ البُعدَ فارغاً ليعني «أيُّ قيمة»</b> لا «لا قيمة». وصفُّ «اسمح»
        يُسمّي نموذجاً يجعل بُعدَ النماذجِ قائمةَ سماحٍ لهذا النطاق.
    </div>
    <form method="POST" action="{{ route('ai.policies.store') }}">@csrf
        <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-start">
            <label style="flex:1;min-width:170px">المفتاح
                <input name="key" required pattern="[a-z0-9._-]+" class="mono ltr"
                       placeholder="no-expensive-models"></label>
            <label style="flex:1;min-width:170px">الاسم المعروض
                <input name="label" required maxlength="191" placeholder="منعُ النماذجِ الغالية"></label>
            <label style="min-width:120px">الأثر
                <select name="effect">
                    <option value="allow">سماح</option>
                    <option value="deny">منع</option>
                </select></label>
            <label style="min-width:120px">النطاق
                <select name="scope_type">
                    @foreach (\App\Support\Ai\Governance\AiPolicy::SCOPES as $sc)
                        <option value="{{ $sc }}">{{ $sc }}</option>
                    @endforeach
                </select></label>
            <label style="flex:1;min-width:170px">معرّفُ النطاق
                <input name="scope_id" class="mono ltr" placeholder="يُترَك فارغاً مع «global»"></label>
        </div>
        <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-start;margin-top:8px">
            <label style="flex:1;min-width:170px">الغرض
                <select name="purpose">
                    <option value="">أيُّ غرض</option>
                    @foreach ($profiles as $pf)
                        <option value="{{ $pf->key }}">{{ $pf->label }} ({{ $pf->key }})</option>
                    @endforeach
                </select></label>
            <label style="flex:1;min-width:170px">المزوّد
                <select name="provider_id">
                    <option value="">أيُّ مزوّد</option>
                    @foreach ($providers as $pv)
                        <option value="{{ $pv->id }}">{{ $pv->label }}</option>
                    @endforeach
                </select></label>
            <label style="flex:1;min-width:170px">النموذج
                <select name="model_id">
                    <option value="">أيُّ نموذج</option>
                    @foreach ($models as $md)
                        <option value="{{ $md->id }}">{{ $md->litellm_model_name }}</option>
                    @endforeach
                </select></label>
        </div>
        <div class="row" style="gap:10px;flex-wrap:wrap;align-items:flex-start;margin-top:8px">
            <label style="min-width:150px">سقفُ رموزِ المخرَج
                <input name="max_output_tokens" type="number" min="1" max="100000"
                       placeholder="بلا سقفٍ من السياسة"></label>
            <label style="min-width:150px">سقفُ نداءاتِ الطلب
                <input name="max_calls_per_request" type="number" min="1" max="100"
                       placeholder="بلا سقفٍ من السياسة"></label>
            <label style="min-width:110px">الأولويّة
                <input name="priority" type="number" min="0" max="9999" value="100"></label>
            <label class="row" style="gap:6px;align-items:center">
                <input type="hidden" name="allow_generation" value="1">
                <input type="checkbox" name="allow_generation" value="0"> امنع التوليد</label>
            <label class="row" style="gap:6px;align-items:center">
                <input type="hidden" name="allow_tools" value="1">
                <input type="checkbox" name="allow_tools" value="0"> امنع الأدوات</label>
        </div>
        <label style="display:block;margin-top:8px">السبب — <span class="mut">يُعرَض للمستخدمِ حين يُمنَع</span>
            <input name="notes" maxlength="1000" placeholder="موقوفٌ حتّى مراجعةِ الكلفة"></label>
        <div style="margin-top:10px"><button class="btn">➕ أضِف السياسة</button></div>
    </form>
</div>
@endif

@endsection
