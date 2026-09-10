@extends('layouts.app')
@section('title', 'تفصيل يوم — ' . $emp->name)
@section('content')
@php
    $effTone = match ($c['effective']) {
        'present' => 'ok', 'leave' => 'ac', 'absent_due_to_missing_report' => 'bad',
        'non_compliant' => 'wn', 'absent' => 'bad', 'excused' => 'ac', default => '' };
    $canReview = \App\Support\ReportReview::canReviewAny(auth()->user());
    $canFinalize = hub_can(auth()->user(), 'hr', 'e') || auth()->user()->role?->is_owner;
@endphp
<div class="hero">
    <div>
        <h2>👤 {{ $emp->name }} <span class="sub mono">{{ $date }}</span></h2>
        <div class="sub">الحضورُ الفعليّ، التقرير، والحالة المحتسَبة — منفصلةً وواضحة (§12/§68).</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.index', ['date' => $date]) }}">‹ مركز التقارير</a>
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🟢</span><b>{{ $c['labels']['physical'] }}</b><span>الحضور الفعليّ</span></div>
    <div class="stat"><span class="ico">🕗</span><b class="mono">{{ $c['time_in'] ?: '—' }}@if($c['time_out']) – {{ $c['time_out'] }}@endif</b><span>حضور — انصراف</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ $c['labels']['compliance'] }}</b><span>التقرير</span></div>
    <div class="stat"><span class="ico bdg {{ $effTone }}">⚖️</span><b>{{ $c['labels']['effective'] }}</b><span>الحالة المحتسَبة</span></div>
    @if ($c['deadline_at'])<div class="stat"><span class="ico">⏰</span><b class="mono">{{ $c['deadline_at']->format('Y-m-d H:i') }}</b><span>مهلة التقرير</span></div>@endif
</div>

<div class="card">
    <div class="sub" style="margin-bottom:8px">📌 {{ $c['reason'] }}
        @if ($c['finalized'])<br><b>خُتم يدويّاً</b> — {{ $c['finalized_note'] }} <span class="mono">({{ optional($c['finalized_at'])->format('Y-m-d H:i') }})</span>@endif</div>

    @if ($canFinalize && $c['attendance'])
        <form method="post" action="{{ route('reports.finalize', $c['attendance']->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;border-top:1px solid var(--line,#eee);padding-top:8px">
            @csrf
            <label>ختمُ الأثر (§90)<br>
                <select name="outcome" class="in">
                    <option value="present">حاضر (قبول تقرير متأخر)</option>
                    <option value="excused">معذور</option>
                    <option value="absent_due_to_missing_report">غياب لعدم التقرير</option>
                    <option value="non_compliant">غير ممتثل</option>
                    <option value="clear">رفع الختم (اشتقاق حيّ)</option>
                </select></label>
            <label style="flex:1;min-width:200px">السبب (موثّق)<br><input type="text" name="note" class="in" style="width:100%" placeholder="سبب الختم — يُدقَّق"></label>
            <button class="btn sm">ختم</button>
        </form>
    @endif
</div>

<div class="card">
    <h3 class="cardtitle">بنود اليوم ({{ $entries->count() }}) <span class="sub">— ماذا عمل، على أيّ مشروع، أيّ مهمّة، كم ساعة، ما التقدّم والعوائق</span></h3>
    @forelse ($entries as $w)
        @php $rs = $w->review_status ?: 'pending_review'; @endphp
        <div class="card" style="margin:8px 0">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                <div>
                    <b>{{ $w->project?->name ?: 'عمل داخليّ/غير مرتبط بمشروع' }}</b>
                    @if ($w->task)<span class="sub">· مهمّة: {{ $w->task->title }}</span>@endif
                    <span class="bdg {{ $rs==='accepted'?'ok':($rs==='needs_revision'?'wn':'') }}">
                        {{ ['pending_review'=>'بانتظار المراجعة','accepted'=>'مقبول','needs_revision'=>'يحتاج تنقيحاً'][$rs] ?? 'بانتظار' }}</span>
                </div>
                <div class="sub mono">{{ optional($w->submitted_at)->format('H:i') }} · {{ $w->hours ? number_format((float)$w->hours,1).' س' : '' }}</div>
            </div>
            <div style="margin-top:6px">✅ {{ $w->done }}</div>
            @if ($w->doing)<div class="sub">🔧 جارٍ: {{ $w->doing }}</div>@endif
            @if ($w->problems)<div class="sub" style="color:var(--bad,#c0392b)">🚧 عوائق: {{ $w->problems }}</div>@endif
            @if ($w->next)<div class="sub">➡️ التالي: {{ $w->next }}</div>@endif
            @if ($w->progress !== null && $w->task)
                <div class="sub">📈 تقدّمٌ مقترح: {{ (float)$w->progress }}٪ (المهمّة الآن {{ (float)($w->task->progress ?? 0) }}٪)</div>@endif
            @if ($w->review_feedback)<div class="sub" style="border-inline-start:3px solid var(--wn,#e67e22);padding-inline-start:8px;margin-top:4px">💬 ملاحظة المراجع: {{ $w->review_feedback }}</div>@endif

            @if ($canReview && \App\Support\ReportReview::canReview(auth()->user(), $w))
                <form method="post" action="{{ route('reports.review.act', $w->id) }}" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;align-items:center">
                    @csrf
                    <input type="text" name="feedback" class="in" placeholder="ملاحظة (لطلب التنقيح)" style="flex:1;min-width:160px">
                    <button class="btn xs" name="action" value="accept">✅ قبول</button>
                    <button class="btn ghost xs" name="action" value="needs_revision">✏️ طلب تنقيح</button>
                    @if ($rs === 'accepted')<button class="btn ghost xs" name="action" value="reopen">↩️ إعادة فتح</button>@endif
                    <a class="btn ghost xs" href="{{ route('m.show', ['updates', $w->id]) }}">↗</a>
                </form>
            @endif
        </div>
    @empty
        <div class="sub">لا بنودَ تقريرٍ لهذا اليوم. {{ $c['report_required'] ? 'التقريرُ مطلوب.' : 'التقريرُ غيرُ مطلوبٍ اليوم.' }}</div>
    @endforelse
</div>
@endsection
