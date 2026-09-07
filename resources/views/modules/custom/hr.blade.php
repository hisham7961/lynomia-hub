{{-- أفكار هذا الموظف — الاقتراح إسهامٌ يُذكر في ملفه لا سطرٌ في قائمةٍ عامة --}}
@php
    $ideaUser = $row->user_id ?? null;
    $myIdeas = $ideaUser ? \App\Support\Innovation::byPerson($ideaUser) : [];
    $myScore = $ideaUser ? collect(\App\Support\Innovation::contributors(999))->firstWhere('id', $ideaUser) : null;
@endphp
@if ($ideaUser && hub_can(auth()->user(), 'ideas', 'v'))
    <div class="card">
        <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">💡 أفكاره ومساهمته في الابتكار</h3>
            @if ($myScore)<span class="bdg ok">درجة الإسهام {{ $myScore['score'] }}</span>@endif
        </div>
        @if (count($myIdeas))
            <div class="sub" style="margin:4px 0 8px">
                {{ count($myIdeas) }} فكرة
                @if ($myScore && $myScore['promoted']) · <b>{{ $myScore['promoted'] }} صارت مشاريع</b>@endif
                @if ($myScore && $myScore['ice']) · متوسط ICE {{ $myScore['ice'] }}@endif
            </div>
            <table class="mini">
                @foreach ($myIdeas as $i)
                    <tr>
                        <td><a href="{{ route('m.show', ['ideas', $i['id']]) }}">{{ \Illuminate\Support\Str::limit($i['title'], 46) }}</a>
                            <div class="sub">{{ $i['cat'] ?: '—' }}{{ $i['status'] ? ' · ' . $i['status'] : '' }}</div></td>
                        <td class="acts">
                            @if ($i['ice'] !== null)<span class="bdg {{ $i['ice'] >= 500 ? 'ok' : ($i['ice'] >= 200 ? 'wn' : '') }}">{{ $i['ice'] }}</span>@endif
                            @if ($i['promoted'])<span class="bdg ok">🚀</span>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <div class="sub" style="margin-top:6px">لم يقترح فكرةً بعد — والاقتراح يُحسب إسهاماً في ملفه.</div>
        @endif
        <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('innovation') }}">مركز الابتكار ←</a></div>
    </div>
@endif

{{-- تبويبُ العهدة المالية في الموظف 360 (Work OS · الطور E · WP-E.3 · §19/§28) —
     رصيدٌ مشتقٌّ (لا عمودَ رصيدٍ يُحرَّر) وأحدثُ الحركات، بحجبِ المبلغ عبر
     `custody.amount`؛ الإدارةُ الكاملةُ في مركز العهدة. يظهر لمن يملك عرضَ العهدة. --}}
@if (hub_can(auth()->user(), 'custody', 'v'))
    @php
        $cAmt = hub_field_mode(auth()->user(), 'custody', 'amount');
        $cBal = $row->custody_balance;
        $cMoves = \App\Models\EmployeeCustodyMove::where('employee_id', $row->id)
            ->orderByDesc('at')->orderByDesc('id')->limit(6)->get();
        $cCur = setting('app.currency', 'د.ك');
        $cKinds = ['advance' => 'سلفة', 'charge' => 'شحن', 'expense' => 'مصروف', 'repayment' => 'سداد',
            'transfer_in' => 'تحويل وارد', 'transfer_out' => 'تحويل صادر', 'deduction' => 'خصم راتب',
            'settlement' => 'تسوية', 'correction' => 'تصحيح', 'reversal' => 'عكس'];
    @endphp
    <div class="card" data-cctab="custody">
        <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
            <h3 style="margin:0">💰 العهدة المالية</h3>
            <span class="bdg {{ (float) $cBal > 0 ? 'wn' : 'ok' }}">{{ $cAmt === 'hide' ? '••• محجوب' : number_format((float) $cBal, 3, '.', '') }} {{ $cCur }}</span>
        </div>
        @if ($cMoves->isEmpty())
            <div class="sub" style="margin-top:6px">لا حركاتِ عهدةٍ بعد.</div>
        @else
            <table class="mini" style="margin-top:6px">
                @foreach ($cMoves as $cm)
                    <tr>
                        <td>{{ $cKinds[$cm->kind] ?? $cm->kind }}</td>
                        <td>{{ $cAmt === 'hide' ? '••• محجوب' : number_format((float) $cm->amount, 3, '.', '') }}</td>
                        <td>{{ (int) $cm->sign > 0 ? '➕' : '➖' }}</td>
                        <td class="sub">{{ optional($cm->at)->format('Y-m-d') }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
        <div style="margin-top:8px"><a class="btn ghost xs" href="{{ route('custody.wallet.employee', $row->id) }}">كشفُ العهدة وإدارتها ←</a></div>
    </div>
@endif
