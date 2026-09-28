{{-- تحليلُ الذكاء لمؤشّرٍ واحد: التفسيرُ والإجراءات والهدفُ المقترح (يُعتمد أو يُرفض). المتغيّرات: $ins (صفّ kpi_insights أو null)، $kpiId، $onlyTarget --}}
@if ($ins)
    @php $insT = $ins->target ? (array) json_decode((string) $ins->target, true) : null; $insD = $ins->target_decision ? (array) json_decode((string) $ins->target_decision, true) : null; @endphp
    <div class="sub" style="margin-top:6px;border-inline-start:3px solid var(--i,#6366f1);padding-inline-start:8px">
        @if (empty($onlyTarget))
            🤖 {{ $ins->explanation }}
            @php $insA = (array) json_decode((string) $ins->actions, true); @endphp
            @if ($insA)<ul style="margin:4px 0">@foreach ($insA as $a)<li>{{ $a }}</li>@endforeach</ul>@endif
        @endif
        @if ($insT && ! $insD)
            <div>🎯 هدفٌ مقترح: <b class="mono">{{ $insT['value'] }}</b> — {{ $insT['why'] ?? '' }}
                <form method="POST" action="{{ route('kpis.aiTarget', $kpiId) }}" class="inline">@csrf
                    <input type="hidden" name="action" value="applied"><button class="btn p xs">اعتمد الهدف</button></form>
                <form method="POST" action="{{ route('kpis.aiTarget', $kpiId) }}" class="inline">@csrf
                    <input type="hidden" name="action" value="rejected"><button class="btn ghost xs">رفض</button></form>
            </div>
        @elseif ($insD)
            <div>🎯 الهدفُ المقترح ({{ $insD['value'] ?? '' }}): {{ ($insD['action'] ?? '') === 'applied' ? 'اعتُمد' : 'رُفض' }}</div>
        @endif
    </div>
@endif
