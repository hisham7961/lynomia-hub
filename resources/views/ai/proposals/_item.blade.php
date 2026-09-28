{{-- بطاقةُ اقتراحٍ واحد — تُستعمل في الصندوق وفي صفحة السجلّ. المتغيّرات: $p، $ev (الدليلُ بعين القارئ)، $title (اختياريّ) --}}
@php $pDef = \App\Support\Ai\Proposals\ProposalService::fieldDef($p->kind); @endphp
<div class="card" style="border-inline-start:4px solid var(--i, #6366f1)">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span class="bdg i">🤖 اقتراح</span>
        <b>{{ \App\Support\Ai\Proposals\ProposalService::label($p->kind) }}</b>
        @if (! empty($title))
            <span class="sub">·</span> <a href="{{ route('m.show', [$p->module, $p->record_id]) }}">{{ $title }}</a>
        @endif
        @if ($p->confidence !== null)<span class="bdg">ثقة {{ $p->confidence }}٪</span>@endif
        <span class="sub">{{ $p->created_at?->diffForHumans() }}</span>
    </div>
    <div style="margin:8px 0;font-size:1.05em">
        <span class="sub">الحاليّ:</span> <b>{{ $p->current_value !== '' && $p->current_value !== null ? $p->current_value : '—' }}</b>
        <span aria-hidden="true">←</span>
        <span class="sub">المقترح:</span> <b>{{ $p->proposed_value }}</b>
    </div>
    @if ($p->rationale)<div class="sub" style="white-space:pre-line">{{ $p->rationale }}</div>@endif
    @if (! empty($ev))
        <ul class="sub" style="margin:6px 0">
            @foreach ($ev as $e)
                <li>
                    @if ($e['quote'] !== null)
                        «{{ $e['quote'] }}» — <a href="{{ $e['url'] }}">المصدر</a>
                    @else
                        دليلٌ من سجلٍّ لا تملك عرضه
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:6px">
        <form method="POST" action="{{ route('ai.proposals.apply', $p->id) }}" class="inline">@csrf
            <button class="btn p sm">✓ اعتماد</button>
        </form>
        <form method="POST" action="{{ route('ai.proposals.apply', $p->id) }}" class="inline" style="display:flex;gap:6px;align-items:end">@csrf
            <input class="inp" name="value" value="{{ $p->proposed_value }}" style="max-width:140px" aria-label="قيمةٌ معدّلة">
            <button class="btn ghost sm">✎ اعتماد بقيمة معدّلة</button>
        </form>
        <form method="POST" action="{{ route('ai.proposals.reject', $p->id) }}" class="inline" style="display:flex;gap:6px;align-items:end">@csrf
            <input class="inp" name="reason" placeholder="سبب الرفض (اختياريّ)" style="max-width:220px">
            <button class="btn ghost sm">✗ رفض</button>
        </form>
    </div>
</div>
