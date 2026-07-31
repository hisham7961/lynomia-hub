{{-- لوحة امتثال السياسة — يتوقع $row (السياسة). نسبة الإقرار على النسخة الحالية --}}
@php
    $pcAcks = \App\Models\PolicyAck::where('policy_id', $row->id)->where('ver', (string) $row->ver)->get();
    $pcDone = $pcAcks->where('status', 'مُقرّة');
    $pcPending = $pcAcks->where('status', 'بانتظار الإقرار');
    $pcNames = \App\Models\User::whereIn('id', $pcAcks->pluck('user_id')->filter())->pluck('name', 'id');
    $pcPct = $pcAcks->count() ? (int) round($pcDone->count() / $pcAcks->count() * 100) : null;
    $pcStale = \App\Models\PolicyAck::where('policy_id', $row->id)
        ->where('status', 'منتهية بتحديث النسخة')->count();
@endphp
<div class="card">
    <h3 class="cardtitle">🖊️ امتثال النسخة الحالية ({{ $row->ver ?: '—' }})</h3>
    @if ($pcAcks->isEmpty())
        <div class="sub">
            لا إقرارات على هذه النسخة بعد.
            @if ($row->ack_required)
                أنشئ إقراراً من وحدة «إقرارات السياسات» أو اطلب توقيعاً إلكترونياً — الإقرار الموقّع يُنشئ سجله موثقاً بالنسخة والوقت والجهاز.
            @else
                («الإقرار مطلوب» غير مفعّل على هذه السياسة.)
            @endif
        </div>
    @else
        <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:10px">
            <div><div class="sub">أقرّوا</div><b class="mono">{{ $pcDone->count() }} من {{ $pcAcks->count() }}</b></div>
            <div><div class="sub">نسبة الامتثال</div>
                <span class="bdg {{ $pcPct === 100 ? 'ok' : ($pcPct >= 50 ? 'wn' : 'bad') }}">{{ $pcPct }}٪</span></div>
            @if ($pcStale)
                <div><div class="sub">إقرارات نسخ سابقة</div><b class="mono">{{ $pcStale }}</b></div>
            @endif
        </div>
        <div style="background:var(--brd);border-radius:8px;height:10px;overflow:hidden;margin-bottom:10px">
            <div style="height:100%;width:{{ $pcPct }}%;border-radius:8px;background:{{ $pcPct === 100 ? 'var(--ok, #27ae60)' : 'var(--pr)' }}"></div>
        </div>
        @if ($pcPending->isNotEmpty())
            <div class="sub" style="margin-bottom:4px">بانتظار إقرارهم:</div>
            <div>
                @foreach ($pcPending as $a)
                    <span class="bdg wn">{{ $pcNames[$a->user_id] ?? ($a->title ?: '؟') }}</span>
                @endforeach
            </div>
        @else
            <div class="sub">لا متخلفين عن هذه النسخة ✓</div>
        @endif
    @endif
</div>
