@extends('layouts.app')
@section('title', 'متابعات فريقي')
@section('content')
<div class="hero">
    <div>
        <h2>👥 متابعات فريقي <span class="bdg i">🤖 مساعد Hub</span></h2>
        <div class="sub">التزاماتُ فريقك المباشر المفتوحة — «المُصعَّد» منها لم يُردّ عليه بعد سؤالين. تستطيع إغلاقَ ما تعرف مآله.</div>
    </div>
    <a class="btn ghost sm" href="{{ route('followups.mine') }}">🔁 متابعاتي</a>
</div>

@if ($accuracy['disabled'])
    <div class="card"><span class="bdg bad">الاستخراجُ موقوفٌ آلياً</span>
        <span class="sub">{{ $accuracy['wrong'] }} من {{ $accuracy['answered'] }} ردّاً قالت «لم يكن التزاماً» — لن تُستخرج التزاماتٌ جديدة حتى تتحسّن الدقّة.</span></div>
@endif

<div class="card">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>الموظّف</th><th>الالتزام</th><th>قيل في</th><th>الموعد</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $c)
            <tr>
                <td>{{ $names[$c->user_id] ?? '—' }} @if (in_array((string) $c->user_id, $muted, true))<span class="bdg" title="كتم إشعاراتِ المتابعة">🔕 مكتوم</span>@endif</td>
                <td>{{ $c->what }} <a class="sub" href="{{ route('m.show', ['updates', $c->source_id]) }}">التقرير</a></td>
                <td class="sub">{{ $c->said_on?->toDateString() }}</td>
                <td class="sub">{{ $c->due_on?->toDateString() }}</td>
                <td><span class="bdg {{ $c->status === 'escalated' ? 'bad' : '' }}">{{ \App\Support\Ai\FollowUp\FollowUp::STATUS_LABELS[$c->status] ?? $c->status }}</span>
                    @if ($c->asked_count)<span class="sub">سُئل {{ $c->asked_count }}</span>@endif</td>
                <td style="display:flex;gap:6px">
                    @foreach (['done' => '✓ أُنجز', 'dropped' => '✗ أُلغي'] as $st => $label)
                        <form method="POST" action="{{ route('followups.close', $c->id) }}" class="inline">@csrf
                            <input type="hidden" name="status" value="{{ $st }}"><button class="btn ghost sm">{{ $label }}</button>
                        </form>
                    @endforeach
                </td>
            </tr>
        @empty
            <tr><td colspan="6">@include('partials.empty', ['text' => 'لا التزاماتٍ مفتوحة في فريقك.', 'icon' => '👥'])</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
