@extends('layouts.app')
@section('title', 'مستكشف العلاقات — ' . ($def['label'] ?? $module))
@section('content')
{{-- ═ (الطور H · WP-H.2 · §33–36) مستكشفُ العلاقات ═
     إسقاطٌ فوق النماذج الحيّة مُرشَّحٌ خادميّاً بالكامل (RelationshipProjection):
     الشجرةُ `<ul>` الدلاليّةُ هي **الأصلُ** وتحمل الإسقاطَ كاملاً، والوضعُ البصريُّ
     عارضُ SVG محليٌّ ذاتيُّ التأليف (public/vendor/lynomia-graph) — لا مكتبةَ من
     شبكةِ توزيعٍ خارجية ولا خطوطَ ولا أيَّ طلبٍ خارجيّ من هذه الصفحة. --}}
@php $rootNode = $byKey[$p['root']] ?? null; @endphp

<header class="rechead">
    <nav class="crumbs" aria-label="مسار التنقل">
        <a href="{{ route('m.index', $module) }}">{{ $def['label'] ?? $module }}</a>
        <span aria-hidden="true">‹</span>
        <b>🕸️ مستكشف العلاقات: {{ \Illuminate\Support\Str::limit($rootNode['label'] ?? $id, 44) }}</b>
    </nav>
    <div class="rh-row">
        <div class="rh-id">
            <h2>🕸️ مستكشف العلاقات</h2>
            <div class="rh-meta">
                <span class="sub">الجذر: {{ $def['label'] ?? $module }} — {{ $rootNode['label'] ?? $id }}</span>
                <span class="sub">عُقد: {{ count($p['nodes']) }} · حوافّ: {{ count($p['edges']) }} · قفزات: {{ $p['hops'] }}</span>
            </div>
        </div>
        <div class="spacer"></div>
        <div class="rh-acts">
            {{-- عمقُ القفزات — يقلّمه graph.max_hops صراحةً في الخدمة --}}
            @foreach (range(1, (int) $p['max_hops']) as $h)
                <a class="btn ghost sm {{ $h === (int) $p['hops'] ? 'p' : '' }}"
                   href="{{ route('graph.explore', ['m' => $module, 'id' => $id, 'hops' => $h]) }}">{{ $h }} قفزة</a>
            @endforeach
            <a class="btn ghost sm" href="{{ route('graph.explore', ['m' => $module, 'id' => $id, 'hops' => $p['hops'], 'fresh' => 1]) }}">↻ تحديث</a>
            <a class="btn ghost sm" href="{{ route('m.show', [$module, $id]) }}">فتح السجل</a>
        </div>
    </div>
</header>

{{-- السقفُ الصريح (نقدُ C4): بلوغُ graph.max_nodes يُعلَن — لا اقتطاعَ صامتاً --}}
@if ($p['capped'])
    <div class="card warn" role="status">
        ⚠️ <b>أُوقِف الإسقاطُ عند حدّ العُقد ({{ $p['max_nodes'] }})</b> — الجرافُ الكاملُ أوسعُ
        ممّا عُرض. ضيّق الجذرَ أو أنقص القفزات، أو ارفع «graph.max_nodes» من الإعدادات.
        العدّاداتُ على العُقد تبقى دقيقةً (مُرشَّحةً بنطاقك) ولو لم تُرسَم كلُّ الأبناء.
    </div>
@endif

<div class="card">
    <div class="gbar">
        <button class="btn sm p" type="button" id="gmode-tree">🌳 الشجرة</button>
        <button class="btn ghost sm" type="button" id="gmode-viz">🕸️ الجراف</button>
        <span class="spacer"></span>
        {{-- عروضُ المستكشف المحفوظة — SavedView القائم (module='graph')، لا جدولَ ثانياً --}}
        @foreach ($views as $v)
            <a class="bdg" href="{{ $v->url() }}">📌 {{ $v->name }}</a>
        @endforeach
        <form method="POST" action="{{ route('views.store') }}" class="inline gsave">
            @csrf
            <input type="hidden" name="module" value="graph">
            <input type="hidden" name="query" value="m={{ $module }}&id={{ $id }}&hops={{ $p['hops'] }}">
            <input type="text" name="name" maxlength="60" required placeholder="احفظ هذا الإسقاط باسم…">
            <button class="btn ghost sm">💾 حفظ</button>
        </form>
    </div>

    {{-- ① الشجرةُ الدلاليّة — الأصلُ والبديلُ النصّيُّ معاً: الإسقاطُ المُرشَّحُ كاملاً --}}
    <div id="gtree">
        <ul class="gtree">
            @include('graph._node', ['key' => $p['root'], 'edge' => ''])
        </ul>
    </div>

    {{-- ② الوضعُ البصريّ — يُصيَّر محليّاً من الحمولة المضمَّنة نفسِها (لا طلبَ ثانياً) --}}
    <div id="gviz" hidden
         data-expand="{{ route('graph.expand') }}"
         data-explore="{{ route('graph.explore') }}"
         data-hops="{{ $p['hops'] }}">
        <p class="sub">اضغط عقدةً لتوسيعها قفزةً (من الخادم، بترشيحه هو) — وضغطةٌ مزدوجة تجعلها جذراً.</p>
        <svg id="gsvg" role="img" aria-label="جراف العلاقات — البديل النصّيّ الكامل في الشجرة أعلاه"></svg>
    </div>
</div>

<script type="application/json" id="graph-data">@json($p)</script>
<script src="{{ asset('vendor/lynomia-graph/graph.js') }}" defer></script>

<style>
    .gbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
    .gsave { display: flex; gap: 6px; align-items: center; }
    .gsave input[type=text] { padding: 4px 8px; border: 1px solid var(--brd, #ccc); border-radius: 6px; background: transparent; color: inherit; }
    .gtree, .gtree ul { list-style: none; padding-inline-start: 18px; margin: 4px 0; border-inline-start: 1px dashed var(--brd, #ccc3); }
    .gtree > li { border-inline-start: none; }
    .gnode { display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; padding: 2px 0; }
    .glbl { font-weight: 600; text-decoration: none; }
    .glbl:hover { text-decoration: underline; }
    .gmod { font-size: 11px; opacity: .75; border: 1px solid var(--brd, #ccc5); border-radius: 10px; padding: 0 8px; }
    .gvia { font-size: 11px; }
    .gcnt { font-size: 11px; }
    .gjump { text-decoration: none; opacity: .6; }
    .gjump:hover { opacity: 1; }
    #gsvg { width: 100%; min-height: 420px; display: block; }
    .card.warn { border: 1px solid #d97706; background: #d9770612; margin-bottom: 12px; }
</style>
@endsection
