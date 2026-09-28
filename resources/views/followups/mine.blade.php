@extends('layouts.app')
@section('title', 'متابعاتي')
@section('content')
<div class="hero">
    <div>
        <h2>🔁 متابعاتي <span class="bdg i">🤖 مساعد Hub</span></h2>
        <div class="sub">ما ذكرتَه في تقاريرك أنّك ستعمل عليه. يُغلق وحدَه حين تُغلق المهمّةُ المرتبطة أو يذكر تقريرٌ لاحقٌ إنجازَه —
            وإلا يسألك المساعدُ بعد الموعد: ماذا حدث؟ ردّ بنقرة.</div>
    </div>
    @if (\App\Support\Ai\FollowUp\FollowUp::teamUserIds(auth()->user()) !== [])
        <a class="btn ghost sm" href="{{ route('followups.team') }}">👥 متابعات فريقي</a>
    @endif
</div>

@if (! $enabled)
    <div class="card"><span class="bdg wn">متوقّف</span> <span class="sub">المتابِع مطفأٌ من الإعدادات (followup.enabled).</span></div>
@endif
@if ($muted)
    <div class="card"><span class="bdg">مكتوم</span> <span class="sub">كتمتَ إشعاراتِ المتابعة من تفضيلاتك — الالتزاماتُ تظهر هنا، ولا تصلك أسئلة.</span></div>
@endif

@forelse ($open as $c)
    <div class="card">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <b>{{ $c->what }}</b>
            <span class="bdg {{ $c->status === 'escalated' ? 'bad' : '' }}">{{ \App\Support\Ai\FollowUp\FollowUp::STATUS_LABELS[$c->status] ?? $c->status }}</span>
            <span class="sub">قلتَه في {{ $c->said_on?->toDateString() }} · الموعد {{ $c->due_on?->toDateString() }}
                @if ($c->asked_count) · سُئلتَ {{ $c->asked_count }} مرّة @endif</span>
        </div>
        @if ($c->quote)<div class="sub" style="margin:6px 0">«{{ $c->quote }}» — <a href="{{ route('m.show', ['updates', $c->source_id]) }}">التقرير</a></div>@endif
        <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:end">
            @foreach (['done' => '✓ أُنجز', 'working' => '⏳ ما زال جارياً', 'dropped' => '✗ أُلغي', 'wrong' => '🚫 لم يكن التزاماً'] as $a => $label)
                <form method="POST" action="{{ route('followups.answer', $c->id) }}" class="inline">@csrf
                    <input type="hidden" name="answer" value="{{ $a }}">
                    <button class="btn {{ $a === 'done' ? 'p' : 'ghost' }} sm">{{ $label }}</button>
                </form>
            @endforeach
            <form method="POST" action="{{ route('followups.answer', $c->id) }}" class="inline" style="display:flex;gap:6px;align-items:end">@csrf
                <input type="hidden" name="answer" value="postponed">
                <input class="inp" type="date" name="date" min="{{ today()->toDateString() }}" required aria-label="التاريخ الجديد">
                <button class="btn ghost sm">📅 تأجّل إلى</button>
            </form>
        </div>
    </div>
@empty
    <div class="card">@include('partials.empty', ['text' => 'لا التزاماتٍ مفتوحة تنتظر ردّك.', 'icon' => '🔁'])</div>
@endforelse

@if ($closed->isNotEmpty())
    <div class="card">
        <h3>أُغلقت مؤخّراً</h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الالتزام</th><th>الحالة</th><th>أغلقه</th><th>متى</th></tr></thead>
            <tbody>
            @foreach ($closed->take(20) as $c)
                <tr><td>{{ $c->what }}</td><td>{{ \App\Support\Ai\FollowUp\FollowUp::STATUS_LABELS[$c->status] ?? $c->status }}</td>
                    <td>{{ ['ai' => '🤖 بالدليل', 'user' => 'أنت', 'manager' => 'مديرك'][$c->closed_by] ?? '—' }}</td>
                    <td class="sub">{{ $c->closed_at?->diffForHumans() }}</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
@endsection
