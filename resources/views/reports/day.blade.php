@extends('layouts.app')
@section('title', 'تفصيل يوم — ' . $emp->name)
@section('content')
@php
    $effTone = $c['tones']['effective'];   // النغمةُ من الكاتب (N-29)
    $canReview = \App\Support\Workforce\ReportReview::canReviewAny(auth()->user());
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
    {{-- ورديّةٌ عبرت منتصفَ الليل وما تزال مفتوحة (التحقّقُ الثاني عشر · ع‑٥):
         كانت هذه الصفحةُ وحدَها لا تقرأ `open_shift` فتقول «لم يسجّل» لمن هو على
         رأسِ عملِه — وشاشةُ المدير وبطاقتُه تقولانها. --}}
    @if (! $c['physical'] && ! empty($c['open_shift']))
        <div class="stat"><span class="ico bdg ok">🌙</span><b>على رأس العمل</b>
            <span>منذ {{ $c['open_shift']['since'] }} (أمس)</span></div>
    @else
        <div class="stat"><span class="ico">🟢</span><b>{{ $c['labels']['physical'] }}</b><span>الحضور الفعليّ</span></div>
    @endif
    <div class="stat"><span class="ico">🕗</span><b class="mono">{{ $c['time_in'] ?: '—' }}@if($c['time_out']) – {{ $c['time_out'] }}@endif</b><span>حضور — انصراف
        {{-- F11: دخولٌ بلا انصرافٍ في يومٍ ماضٍ — شارةٌ صريحةٌ ورابطُ التصحيح --}}
        @if ($c['checked_in'] && ! $c['checked_out'] && $date < \App\Support\Platform\BusinessDate::today())
            @if ($c['attendance'] && hub_can(auth()->user(), 'attend', 'e'))
                <a class="bdg wn" href="{{ route('m.edit', ['attend', $c['attendance']->id]) }}" title="صحّح صفَّ الحضور — الساعاتُ لا تُختلق">انصراف مفقود ✎</a>
            @else
                <span class="bdg wn">انصراف مفقود</span>
            @endif
        @endif</span></div>
    <div class="stat"><span class="ico">📝</span><b>{{ $c['labels']['compliance'] }}</b><span>التقرير</span></div>
    @if ($c['verdict_pending'] ?? false)
        <div class="stat"><span class="ico bdg">⚖️</span><b>—</b><span>الحالة المحتسَبة
            <span class="sub">لم يبدأ الدوامُ بعد</span></span></div>
    @else
        <div class="stat"><span class="ico bdg {{ $effTone }}">⚖️</span><b>{{ $c['labels']['effective'] }}</b><span>الحالة المحتسَبة</span></div>
    @endif
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
            @if ($w->problems)
                <div class="sub" style="color:var(--bad,#c0392b)">🚧 عوائق: {{ $w->problems }}</div>
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
            @if ($w->next)<div class="sub">➡️ التالي: {{ $w->next }}</div>@endif
            @if ($w->progress !== null && $w->task)
                <div class="sub">📈 تقدّمٌ مقترح: {{ (float)$w->progress }}٪ (المهمّة الآن {{ (float)($w->task->progress ?? 0) }}٪)</div>@endif
            @if ($w->review_feedback)<div class="sub" style="border-inline-start:3px solid var(--wn,#e67e22);padding-inline-start:8px;margin-top:4px">💬 ملاحظة المراجع: {{ $w->review_feedback }}</div>@endif

            @if ($canReview && \App\Support\Workforce\ReportReview::canReview(auth()->user(), $w))
                {{-- ═══ المدقّق (§٣.٤ · A3): ملاحظتُه للمراجع وحدَه، ومسودتُه تُعبّأ ولا تُرسَل —
                     الموظّفُ لا يرى إلّا ما حرّره المراجعُ وأرسله بـ«طلب تنقيح» (قرارُ المالك §٣.٦) ═══ --}}
                @php $notes = $auditNotes[(string) $w->id] ?? []; $draftNote = collect($notes)->pluck('note')->filter()->first(); @endphp
                @foreach ($notes as $n)
                    <div class="sub" style="border-inline-start:3px solid var(--acc,#2c7be5);padding-inline-start:8px;margin-top:6px" data-auditor-note>
                        🔎 <b>المدقّق · {{ $n['label'] }}</b>@if ($n['ai']) <span class="bdg">بالذكاء — تحقّق</span>@endif — {{ $n['summary'] }}
                    </div>
                @endforeach
                {{-- **المسودةُ اقتراحٌ لا قيمة:** لا تُعبّأ في الحقل — فحقلُ الملاحظة يُرسَل مع «قبول» أيضاً، ومسودةٌ
                     معبّأةٌ كانت ستصل الموظّفَ حين يرفض المراجعُ حكمَ المدقّق ويقبل التقرير. زرٌّ ينسخها إلى الحقل
                     بقرار المراجع، ثمّ يحرّرها ويرسلها بـ«طلب تنقيح». --}}
                @if ($draftNote)
                    <div class="sub" style="margin-top:4px" data-auditor-draft>
                        ✍️ مسودةٌ مقترحة: «<span data-draft-text>{{ $draftNote }}</span>»
                        <button type="button" class="btn ghost xs"
                            onclick="var f=this.closest('.card').querySelector('input[name=feedback]');f.value=this.parentNode.querySelector('[data-draft-text]').textContent;f.focus()">استعمل المسودة</button>
                    </div>
                @endif
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
