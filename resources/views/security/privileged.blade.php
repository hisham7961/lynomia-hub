@extends('layouts.app')
@section('title', 'مراجعة الامتيازات')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span>@include('security.parts.root_crumb')<span aria-hidden="true">‹</span><b>مراجعة الامتيازات</b></nav>
        <h2>🗂️ مراجعة الامتيازات</h2>
        <div class="sub">من يملك ماذا ولماذا — ثماني فئاتٍ تُراجَع بيد المالك: لا امتيازَ يُسحب تلقائياً، والإقرارُ على سكّة النتائج الأمنية نفسِها</div>
    </div>
    <a class="btn ghost sm" href="{{ route('security.identity') }}">🪪 خطر الهويّة</a>
</div>

@include('partials.cc.kpis', ['items' => collect(\App\Support\IdentityRisk::CATEGORIES)->map(fn ($c, $k) => [
    'label' => $c[0] . ' ' . $c[1],
    'value' => count($cats[$k] ?? []),
    'tone'  => count($cats[$k] ?? []) ? (in_array($k, ['no_mfa'], true) ? 'bad' : '') : 'ok',
    'url'   => route('security.privileged', ['cat' => $k]),
])->values()->all()])

@include('partials.cc.tabs', ['active' => $cat, 'tabs' => collect(\App\Support\IdentityRisk::CATEGORIES)
    ->map(fn ($c, $k) => ['key' => $k, 'label' => $c[1], 'url' => route('security.privileged', ['cat' => $k])])
    ->values()->all()])

<div class="card">
    <h3>{{ \App\Support\IdentityRisk::CATEGORIES[$cat][0] }} {{ \App\Support\IdentityRisk::CATEGORIES[$cat][1] }}
        <span class="sub">
            @switch($cat)
                @case('owners') وصولٌ كامل يتخطى المصفوفةَ والأعلام — أقلُّهم أسلم @break
                @case('risky') أدوارٌ تحمل رايةً حسّاسة (مستخدمون/أسرار/تصدير/تدقيق/نسخ) @break
                @case('scope') نطاقُ الدور «كل الشركات» — يرى المنشأةَ كلَّها @break
                @case('wide') بلا حصرِ شركاتٍ في الملف (users.companies فارغة) @break
                @case('idle') بلا دخولٍ يتجاوز العتبات (٣٠/٦٠/٩٠ من الإعدادات) @break
                @case('no_mfa') صلاحيةٌ خطرة تحميها كلمةُ مرورٍ وحدها — الأولى بالمعالجة @break
                @case('changed') قيودُ التدقيق على الأدوار والمستخدمين في ٣٠ يوماً @break
                @case('unused') وحداتٌ ممنوحةٌ في المصفوفة لم يمسّها صاحبُها ٩٠ يوماً @break
            @endswitch
        </span>
    </h3>
    @if ($cat === 'changed')
        @include('security.parts.priv_changes')
    @else
        @include('security.parts.priv_users')
    @endif
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
