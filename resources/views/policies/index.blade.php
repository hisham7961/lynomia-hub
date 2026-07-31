@extends('layouts.app')
@section('title', 'السياسات والإقرارات')
@section('content')
@php
    $tot = ['n' => count($board), 'full' => 0, 'pend' => 0];
    foreach ($board as $b) { if ($b['state']['pct'] >= 100) $tot['full']++; $tot['pend'] += $b['state']['pending']; }
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>الحوكمة</span><span aria-hidden="true">‹</span><b>السياسات والإقرارات</b></nav>
        <h2>📜 السياسات والإقرارات</h2>
        <div class="sub">
            <b>من قرأ ومن لم يقرأ</b> بالأسماء — وتحديثُ النسخة يُسقط الإقرارات القديمة ويُعيد الإبلاغ،
            فلا يبقى أحدٌ «مُقِرّاً» بنسخةٍ ماتت.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('m.index', 'policies') }}">جدول السياسات ←</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'kb') }}">قاعدة المعرفة ←</a>
        <a class="btn ghost sm" href="{{ route('m.index', 'policyacks') }}">سجل الإقرارات ←</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">📜</span><b>{{ $tot['n'] }}</b><span>سجلاً يلزمه إقرار</span></div>
    <div class="stat"><span class="ico">✅</span><b>{{ $tot['full'] }}</b><span>مكتمل الإقرار</span></div>
    <div class="stat"><span class="ico">⏳</span><b class="{{ $tot['pend'] ? 'txt-bad' : '' }}">{{ $tot['pend'] }}</b>
        <span>إقراراً معلّقاً بانتظار موظفين</span></div>
</div>

@forelse ($board as $b)
    @php $r = $b['row']; $st = $b['state']; $mk = $b['module']; @endphp
    <div class="card" @if ($st['pct'] < 60) style="border-inline-start:4px solid var(--bad,#c0392b)" @endif>
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
            <div style="min-width:0;flex:1">
                <h3 class="cardtitle" style="margin-bottom:4px">
                    <span class="bdg g">{{ $b['label'] }}</span>
                    <a href="{{ route('m.show', [$mk, $r->id]) }}">{{ $r->title }}</a>
                    <span class="bdg wn mono">نسخة {{ $st['ver'] }}</span>
                </h3>
                <div class="sub">
                    {{ $r->cat ?: '—' }}
                    @if ($r->eff_date ?? null) · سريان {{ substr((string) $r->eff_date, 0, 10) }}@endif
                    @if ($r->review_date ?? null) · مراجعة {{ substr((string) $r->review_date, 0, 10) }}@endif
                    @if ($r->project_id ?? null) · مشروع: {{ hub_ref_labels('projects', [$r->project_id])[$r->project_id] ?? '—' }}@endif
                    @if ($r->announced_at ?? null) · أُعلن {{ \Illuminate\Support\Carbon::parse($r->announced_at)->diffForHumans() }}@endif
                </div>
            </div>
            <div style="text-align:center;min-width:96px">
                <div style="font-size:24px;font-weight:700" class="{{ $st['pct'] < 60 ? 'txt-bad' : '' }}">{{ $st['pct'] }}٪</div>
                <span class="sub">{{ $st['done'] }}/{{ $st['total'] }} أقرّوا</span>
            </div>
        </div>

        <div class="pbar" style="margin:10px 0 8px"><span style="width:{{ $st['pct'] }}%"></span></div>

        @if (! $st['total'])
            <div class="sub" style="color:var(--wn)">
                ⚠️ لم يُعلَن بعد — لا أحد يعرف بوجوده. اضغط «أبلغ من يعنيهم» ليصل الإشعار ويُفتح لكلٍّ إقرارٌ باسمه.
            </div>
        @else
            <div class="kids" style="gap:10px">
                <div>
                    <div class="sub" style="margin-bottom:4px">✅ أقرّوا ({{ $st['done'] }}):</div>
                    <div class="crow">
                        @forelse ($st['doneRows'] as $d)
                            <span class="chip" title="{{ $d['at'] ? \Illuminate\Support\Carbon::parse($d['at'])->format('Y-m-d H:i') : '' }}">
                                {{ $d['signed'] ? '✍️' : '✓' }} {{ $d['user'] }}</span>
                        @empty
                            <span class="sub">لا أحد بعد</span>
                        @endforelse
                    </div>
                </div>
                <div>
                    <div class="sub" style="margin-bottom:4px">⏳ لم يُقِر ({{ $st['pending'] }}):</div>
                    <div class="crow">
                        @forelse ($st['pendingRows'] as $d)
                            <span class="chip" style="border-color:var(--bad)">{{ $d['user'] }}</span>
                        @empty
                            <span class="sub">الجميع أقرّوا 🎉</span>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
            @if (hub_can(auth()->user(), $mk, 'e'))
                <form method="POST" action="{{ route('acks.announce', [$mk, $r->id]) }}" class="inline"
                      data-confirm="إبلاغ كل من يعنيهم بهذه النسخة وفتح إقرارٍ معلّق لكلٍّ منهم؟">
                    @csrf<button class="btn ghost sm">📢 أبلغ من يعنيهم</button>
                </form>
            @endif
            @php $mine = collect($st['pendingRows'])->firstWhere('user', auth()->user()->name); @endphp
            @if ($mine)
                <form method="POST" action="{{ route('acks.ack', [$mk, $r->id]) }}" class="inline"
                      data-confirm="أقرّ بأنك قرأت «{{ \Illuminate\Support\Str::limit($r->title, 40) }}» نسخة {{ $st['ver'] }}؟">
                    @csrf<button class="btn sm">✅ أقرّ بقراءتها</button>
                </form>
            @endif
            @if (hub_can(auth()->user(), 'contracts', 'a'))
                <a class="btn ghost sm" href="{{ route('esign.index', ['link' => $mk . ':' . $r->id, 'title' => $r->title . ' — إقرار نسخة ' . $st['ver']]) }}">✍️ اطلب توقيعاً إلكترونياً</a>
            @endif
        </div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">📜</span>
        لا سياسة ولا مقالٍ عليه «إقرار إلزامي» — فعّل الخانة على السياسة أو المقال ليظهر هنا.
    </div></div>
@endforelse

<div class="card">
    <h3 class="cardtitle">كيف تعمل حلقة الإقرار؟</h3>
    <div class="sub" style="line-height:2">
        ① تُعلَن السياسة فيصل <b>إشعارٌ</b> لكل من يعنيهم ويُفتح لكلٍّ <b>إقرارٌ معلّق باسمه</b> —
        فسؤال «من لم يُقِر؟» له جوابٌ لا تخمين.<br>
        ② الإقرار يُحفظ بـ<b>نسخته</b> ووقته وعنوان الشبكة والجهاز — دليلُ امتثالٍ لا مجرد علامة.<br>
        ③ عند <b>تحديث النسخة</b> تسقط الإقرارات السابقة (وتُوسم «منتهية بتحديث النسخة» لا تُمحى)
        ويُعاد الإبلاغ تلقائياً.<br>
        ④ من يريد توقيعاً رسمياً يطلب <b>توقيعاً إلكترونياً</b> على السجل نفسه، فيُقرن الإقرار بطلب التوقيع.<br>
        <b>الجهة المخاطَبة</b>: كل النشطين، أو أعضاء مشروع السياسة إن رُبطت بمشروع، أو منسوبو شركتها.
    </div>
</div>
@endsection
