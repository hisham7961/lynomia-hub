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
