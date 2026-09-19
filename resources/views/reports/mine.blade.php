@extends('layouts.app')
@section('title', 'تقرير اليوم')
@section('content')
@php
    // الزرُّ لمن يملكه فقط (الجولة 1 · F28): كان يُعرض للجميع ثم يصفع من لا يملك
    // `updates:a` بـ403 — زرٌّ كاذب. مكانَه رسالةٌ صادقة تدلّ على المخرج.
    $canAdd = hub_can(auth()->user(), 'updates', 'a');
    $stateLabel = match ($c['state']) {
        'not_required' => ['غير مطلوب اليوم', ''],
        'checked_in' => ['وردية مفتوحة — يمكنك التقديم', ''],
        'report_pending' => ['بانتظار تقديم التقرير', 'wn'],
        'present_reported' => ['مقدَّم — ممتثل', 'ok'],
        'present_without_report' => ['حضورٌ بلا تقرير — بعد المهلة', 'bad'],
        'late_report' => ['مقدَّم متأخّراً — يُراجَع', 'wn'],
        'absent' => ['لا حضور', 'bad'],
        default => [$c['state'], ''],
    };

    $needsRev = $c['review']['needs_revision'] > 0;
@endphp
<div class="hero">
    <div>
        <h2>📝 تقرير اليوم <span class="sub mono">{{ $date }}</span></h2>
        <div class="sub">حالُ يومك في مكانٍ واحد: الحضور، المهلة، وبنودُ ما أنجزت. أضِف عملك تحت
            مشاريعك أو كعملٍ داخليّ — لا كتابةَ مرّتين.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if ($canAdd)
            <a class="btn sm" href="{{ route('m.create', 'updates') }}">＋ أضف بندَ عمل</a>
        @else
            <span class="sub">👁️ دورك للعرض فقط — اطلب صلاحيّة بنود العمل من مديرك</span>
        @endif
    </div>
</div>

<div class="cards">
    {{-- **ورديّتُك التي بدأت أمس ما تزال مفتوحة** (N-19): كانت هذه البطاقةُ تعرض «—»
         بينما بطاقةُ يومِ العمل في الصفحةِ الرئيسةِ تعدّ ساعاتِك حيّاً. وموضعُ الصوابِ
         هنا — في **الحضور** — لا في «حالة التقرير»، فهما سؤالان لا سؤال. --}}
    @if (! empty($c['open_shift']))
        <div class="stat"><span class="ico bdg ok">🌙</span><b class="mono">{{ substr($c['open_shift']['time_in'], 0, 5) }}</b><span>على رأس العمل منذ {{ substr($c['open_shift']['time_in'], 0, 5) }} (أمس)</span></div>
    @else
        <div class="stat"><span class="ico">🕗</span><b class="mono">{{ $c['time_in'] ?: '—' }}@if($c['time_out']) – {{ $c['time_out'] }}@endif</b><span>الحضور</span></div>
    @endif
    <div class="stat"><span class="ico bdg {{ $stateLabel[1] }}">📋</span><b>{{ $stateLabel[0] }}</b><span>حالة التقرير</span></div>
    @if ($c['deadline_at'])<div class="stat"><span class="ico">⏰</span><b class="mono">{{ $c['deadline_at']->format('H:i') }}</b><span>مهلة اليوم</span></div>@endif
    <div class="stat"><span class="ico">🧾</span><b>{{ $c['report_count'] }}</b><span>بند</span></div>
    <div class="stat"><span class="ico">⏱️</span><b>{{ number_format($c['reported_hours'],1) }}</b><span>ساعة مسجَّلة</span></div>
</div>

@if ($c['state'] === 'report_pending' || $c['state'] === 'present_without_report')
    <div class="card" style="border-inline-start:4px solid var(--wn,#e67e22)">
        @if ($canAdd)
            📌 {{ $c['reason'] }}. أضِف بنودَ يومك الآن — حضورُك مسجَّلٌ ولن يتغيّر.
        @else
            {{-- لا تهويلَ على من لا يملك الفعل أصلاً — حضورُه مسجَّلٌ والنقصُ في صلاحيته لا في التزامه --}}
            📌 حضورُك مسجَّل، ودورُك الحالي للعرض فقط فلا يمكنك إضافة بنود — اطلب صلاحيّة بنود العمل من مديرك.
        @endif
    </div>
@elseif ($needsRev)
    <div class="card" style="border-inline-start:4px solid var(--wn,#e67e22)">
        ✏️ طلب مديرُك تنقيحَ أحد بنودك — راجع الملاحظة أدناه وعدّل البند ثم أعد الحفظ.
    </div>
@endif

<div class="card">
    <h3 class="cardtitle">بنودُ اليوم</h3>
    @forelse ($entries as $w)
        @php $rs = $w->review_status ?: 'pending_review'; @endphp
        <div class="card" style="margin:8px 0">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">
                <b>{{ $w->project?->name ?: 'عمل داخليّ' }}@if ($w->task) · {{ $w->task->title }}@endif</b>
                <span class="bdg {{ $rs==='accepted'?'ok':($rs==='needs_revision'?'wn':'') }}">
                    {{ ['pending_review'=>'بانتظار المراجعة','accepted'=>'مقبول','needs_revision'=>'يحتاج تنقيحاً'][$rs] ?? 'بانتظار' }}</span>
            </div>
            <div style="margin-top:6px">✅ {{ $w->done }}</div>
            @if ($w->problems)
                <div class="sub" style="color:var(--bad,#c0392b)">🚧 {{ $w->problems }}</div>
                {{-- **المعوّقُ يصير التزاماً** (v2.558): مالكٌ وموعدٌ وحالةٌ تُغلَق.
                     والزرُّ لمن يكتب البلاغاتِ وحدَه؛ والمُحوَّلُ سلفاً يُعرَض لا يُكرَّر. --}}
                @php $wIssue = data_get($w->meta, 'issue_id'); @endphp
                @if ($wIssue)
                    <div class="sub">🔗 <a href="{{ route('m.show', ['issues', $wIssue]) }}">حُوّل إلى بلاغ — افتحه</a></div>
                @elseif (hub_can(auth()->user(), 'issues', 'a'))
                    <form method="POST" action="{{ route('reports.blocker.issue', $w->id) }}" style="margin-top:4px">
                        @csrf
                        <button class="btn ghost xs" type="submit">🚩 حوّله إلى بلاغ</button>
                    </form>
                @endif
            @endif
            @if ($w->review_feedback)<div class="sub" style="border-inline-start:3px solid var(--wn,#e67e22);padding-inline-start:8px;margin-top:4px">💬 {{ $w->review_feedback }}</div>@endif
            <div style="margin-top:6px;display:flex;gap:6px">
                <span class="sub mono">{{ $w->hours ? number_format((float)$w->hours,1).' س' : '' }}</span>
                {{-- زرُّ التعديل كذلك لمن يملكه (F28): بنودٌ كتبها يومَ كان دورُه يسمح تبقى للعرض --}}
                @if ($rs !== 'accepted' && hub_can(auth()->user(), 'updates', 'e'))<a class="btn ghost xs" href="{{ route('m.edit', ['updates', $w->id]) }}">تعديل</a>
                @elseif ($rs === 'accepted')<span class="sub">مقبول — للتعديل اطلب من مديرك إعادةَ الفتح</span>@endif
            </div>
        </div>
    @empty
        @if ($canAdd)
            <div class="sub">لا بنودَ بعد. <a href="{{ route('m.create', 'updates') }}">أضِف أول بند لعملك اليوم ↗</a></div>
        @else
            <div class="sub">لا بنودَ بعد — دورك للعرض فقط، فإن كان عليك تقديمُ تقريرٍ يوميّ فاطلب صلاحيّة بنود العمل من مديرك.</div>
        @endif
    @endforelse
</div>
@endsection
