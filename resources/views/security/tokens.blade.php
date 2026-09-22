@extends('layouts.app')
@section('title', 'مركز رموز API')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span>@include('security.parts.root_crumb')<span aria-hidden="true">‹</span><b>رموز API</b></nav>
        <h2>🔑 مركز رموز API</h2>
        <div class="sub">كلُّ مفتاحٍ باسمه وصاحبه ونطاقه وحالته من التصنيف الواحد — الإبطالُ هنا، والتدويرُ لصاحب الرمز وحدَه من ملفه (فالنصُّ الصريح لا يمرّ بأحدٍ سواه)</div>
    </div>
    <a class="btn ghost sm" href="{{ route('security.secrets') }}">🗝️ صحّة الأسرار</a>
</div>

@if ($frozen)<div class="flash wn" style="position:static;margin-bottom:12px">🧊 سكُّ الرموز مجمَّدٌ الآن بمفتاح طوارئ — الإبطالُ يعمل، والسكُّ والتدوير موقوفان حتى يُرفع من مركز الأمان</div>@endif

@include('partials.cc.kpis', ['items' => [
    ['label' => 'كل المفاتيح', 'value' => $summary['total']],
    ['label' => 'سارية', 'value' => $summary['live'], 'tone' => 'ok'],
    ['label' => 'خطرة (خاملة أو بلا انتهاء)', 'value' => $summary['risky'],
     'tone' => $summary['risky'] ? 'bad' : 'ok', 'hint' => 'خمولٌ يتجاوز ' . $unusedDays . ' يوماً، أو بلا تاريخ انتهاء'],
    ['label' => 'مُبطَلة', 'value' => $summary['revoked'], 'tone' => $summary['revoked'] ? 'wn' : ''],
    ['label' => $require2fa ? 'متوقّفةٌ الآن (بلا ثنائيّة)' : 'تتوقّف لو أُشترطت الثنائيّة',
     'value' => $hardening['tokens_break'], 'tone' => $hardening['tokens_break'] ? 'wn' : 'ok',
     'hint' => 'مفاتيحُ سارية صاحبُها لم يُفعّل المصادقة الثنائية'],
]])

{{-- #14 · رافعةُ اشتراطِ الثنائيّة: القائمةُ تُقرأ قبل الإشعال لا بعده --}}
@if ($require2fa)
    <div class="flash wn" style="position:static;margin-bottom:12px">🔐 اشتراطُ المصادقة الثنائية على مفاتيح API <b>مشتعل</b> —
        كلُّ مفتاحٍ صاحبُه بلا ثنائيّةٍ يُرَدُّ الآن بـ403. العمودُ أدناه يسمّيهم، والإطفاءُ من مركز الإعدادات يُعيد كلَّ شيءٍ لحظتَه.</div>
@elseif ($hardening['tokens_break'])
    <div class="flash" style="position:static;margin-bottom:12px">🔐 <b>{{ $hardening['tokens_break'] }}</b>
        من {{ $hardening['tokens'] }} مفتاحاً سارياً صاحبُه بلا مصادقةٍ ثنائية — وهؤلاء وحدَهم مَن يتوقّف لو أُشعل
        <span class="mono ltr">security.api_require_2fa</span>. فعّل ثنائيّاتِهم أوّلاً، ثمّ أشعِل المفتاح.</div>
@endif

<div class="card">
    <h3 style="margin:0 0 8px">🧾 المفاتيح <span class="sub">(بترتيبٍ ثابتٍ عبر الصفحات — والفرزُ من رؤوس الجدول)</span></h3>
    @include('security.parts.tokens_table')
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
