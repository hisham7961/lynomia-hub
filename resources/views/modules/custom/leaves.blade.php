{{-- لوحةُ قرار طلب الإجازة (الجولة 1 · F5) — أزرارٌ صريحة بدل تحرير السجلّ الخام --}}
@php
    $lvAb = \App\Http\Controllers\Web\LeaveDecisionController::abilities(auth()->user(), $row);
@endphp

@if ($lvAb['pending'] && ($lvAb['mgr_approve'] || $lvAb['final_approve'] || $lvAb['reject']))
<div class="card" style="margin-bottom:12px;border-inline-start:3px solid var(--wn,#d97706)">
    <h3>⚖️ بانتظار قرارك</h3>
    <div class="sub" style="margin-bottom:8px">
        الحالة الآن: <span class="bdg {{ hub_tone($row->status) }}">{{ $row->status }}</span>
        @if ($lvAb['mgr_approve'] && ! $lvAb['final_approve']) · موافقتُك توصيةُ مديرٍ — الاعتمادُ النهائيّ للموارد البشرية @endif
    </div>
    <form method="post" action="{{ route('leaves.decide', $row->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        @csrf
        <label style="flex:1;min-width:220px">ملاحظة القرار <span class="sub">(إلزاميّة عند الرفض)</span>
            <input type="text" name="note" maxlength="500" value="{{ old('note') }}" placeholder="مثال: تعارض مع تسليم مشروع الخليج — أجّلها أسبوعاً">
        </label>
        @if ($lvAb['final_approve'])
            <button class="btn" name="decision" value="approve">✅ اعتماد</button>
        @elseif ($lvAb['mgr_approve'])
            <button class="btn" name="decision" value="approve">👍 موافقة المدير</button>
        @endif
        @if ($lvAb['reject'])
            <button class="btn danger" name="decision" value="reject"
                    onclick="return this.form.note.value.trim() ? true : (this.form.note.focus(), alert('اكتب سبب الرفض — يقرؤه صاحب الطلب'), false)">❌ رفض</button>
        @endif
    </form>
    @error('note')<div class="sub" style="color:var(--bad,#b91c1c)">{{ $message }}</div>@enderror
</div>
@elseif ($lvAb['self'] && $lvAb['pending'])
<div class="card sub" style="margin-bottom:12px">🕓 طلبك بانتظار القرار — يقرّر مديرُك ثم الموارد البشرية، وستصلك النتيجة إشعاراً.</div>
@endif
