@extends('layouts.app')
@section('title', 'تشغيل اليوم')
@section('content')
<div class="hero">
    <div>
        <h2>☀️ مركز التشغيل اليومي</h2>
        <div class="sub">
            كل ما يحتاج قراراً أو تدخلاً منك اليوم — البطاقات الفارغة لا تظهر أصلاً،
            فما تراه هنا هو ما يستحق وقتك. آخر تحديث {{ $when->format('Y-m-d H:i') }}
        </div>
    </div>
</div>

@if (empty($cards))
    <div class="card"><div class="empty"><span class="big">🎉</span>
        لا شيء يحتاج تدخلك الآن — لا قرارات معلقة ولا تجاوزات ولا مستحقات قريبة
    </div></div>
@else
    <div class="kids">
        @foreach ($cards as $c)
            <div class="card kid">
                <h3>{{ $c['ico'] }} {{ $c['title'] }} <span class="bdg">{{ $c['rows']->count() }}</span></h3>
                <div class="sub" style="margin-bottom:8px">{{ $c['why'] }}</div>
                <table class="mini">
                    @foreach ($c['rows'] as $r)
                        <tr>
                            <td>
                                @if ($r['u'])<a href="{{ $r['u'] }}">{{ \Illuminate\Support\Str::limit($r['t'], 60) }}</a>
                                @else {{ \Illuminate\Support\Str::limit($r['t'], 60) }} @endif
                                @if ($r['s'])<div class="sub">{{ $r['s'] }}</div>@endif
                            </td>
                            <td class="acts">@if ($r['tone'])<span class="bdg {{ $r['tone'] }}">{{ $r['tone'] === 'bad' ? 'عاجل' : 'انتبه' }}</span>@endif</td>
                        </tr>
                    @endforeach
                </table>
                @if ($c['link'])<div style="margin-top:8px"><a class="btn ghost xs" href="{{ $c['link'] }}">عرض الكل ←</a></div>@endif
            </div>
        @endforeach
    </div>
@endif
@endsection
