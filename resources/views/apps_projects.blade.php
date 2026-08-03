@extends('layouts.app')
@section('title', 'التطبيقات والمشاريع')
@section('content')
<div class="hero">
    <div>
        <h2>🔗 التطبيقات والمشاريع</h2>
        <div class="sub">
            العلاقة محسومةٌ في السجل: <b>التطبيق يتبع مشروعاً</b>. لكن ثماني وحداتٍ تعرض الحقلين
            معاً بلا رابط — فهنا يُقال أين انفرط.
        </div>
    </div>
    @if (count($d['noProject']))
        <form method="POST" action="{{ route('appsprojects.fix') }}"
              data-confirm="ربط {{ count($d['noProject']) }} سجلاً بمشروع تطبيقه؟ لا يُمسّ أي سجلٍ كُتب مشروعه صراحةً.">
            @csrf<button class="btn p sm">🔗 اربط الناقص ({{ count($d['noProject']) }})</button>
        </form>
    @endif
</div>

{{-- التناقض أولاً: أحدهما خطأ ولا أحد يعلم أيّهما --}}
@if (count($d['conflict']))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">⚠️ مشروعٌ يخالف مشروع تطبيقه <span class="bdg bad">{{ count($d['conflict']) }}</span></h3>
    <div class="sub" style="margin-bottom:8px">
        هذه لا تُصلَح تلقائياً: أحد الحقلين خطأ، وتصحيحه قرارُ إنسانٍ لا استنتاجُ آلة.
    </div>
    <table class="mini">
        @foreach ($d['conflict'] as $c)
            <tr>
                <td><a href="{{ route('m.show', [$c['module'], $c['id']]) }}">{{ \Illuminate\Support\Str::limit($c['title'], 46) }}</a>
                    <div class="sub" style="font-size:12px">{{ $c['label'] }} · التطبيق: {{ $c['app'] }}</div></td>
                <td class="acts"><span class="bdg bad">مكتوب: {{ $c['have'] }}</span></td>
                <td class="acts"><span class="bdg ok">مشروع التطبيق: {{ $c['want'] }}</span></td>
            </tr>
        @endforeach
    </table>
</div>
@endif

{{-- الناقص: يُعرف مشروعه من تطبيقه وتُرك فارغاً --}}
@if (count($d['noProject']))
<div class="card" style="margin-bottom:12px">
    <h3 class="cardtitle">🕳️ سجلاتٌ لها تطبيق وبلا مشروع <span class="bdg wn">{{ count($d['noProject']) }}</span></h3>
    <div class="sub" style="margin-bottom:8px">
        مشروعها معروفٌ من تطبيقها، لكن <b>عدسة المشروع لا تراها ومجاميعه تنقص بصمت</b>.
        وما يُحفظ من الآن يرث المشروع تلقائياً — هذه بقايا ما قبل ذلك.
    </div>
    <table class="mini">
        @foreach (array_slice($d['noProject'], 0, 40) as $r)
            <tr>
                <td><a href="{{ route('m.show', [$r['module'], $r['id']]) }}">{{ \Illuminate\Support\Str::limit($r['title'], 46) }}</a>
                    <div class="sub" style="font-size:12px">{{ $r['label'] }} · التطبيق: {{ $r['app'] }}</div></td>
                <td class="acts"><span class="bdg">← {{ $r['want'] }}</span></td>
            </tr>
        @endforeach
    </table>
</div>
@endif

<div class="grid2">
    {{-- تطبيقٌ يتيم --}}
    @if (count($d['orphanApps']))
    <div class="card kid">
        <h3>👻 تطبيقاتٌ بلا مشروع <span class="bdg wn">{{ count($d['orphanApps']) }}</span></h3>
        <div class="sub" style="margin-bottom:6px">لا تصعد إلى أي مجموع، ولا تظهر في عدسة مشروع.</div>
        <table class="mini">
            @foreach ($d['orphanApps'] as $a)
                <tr>
                    <td><a href="{{ route('m.show', ['apps', $a['id']]) }}">{{ $a['name'] }}</a></td>
                    <td class="acts sub">{{ $a['platform'] }}</td>
                    <td class="acts">@if ($a['status'])<span class="bdg {{ hub_tone($a['status']) }}">{{ $a['status'] }}</span>@endif</td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- مشروعٌ منتَجُه تطبيق ولا ملفَّ تطبيقٍ له --}}
    @if (count($d['appless']))
    <div class="card kid">
        <h3>📱 مشاريعُ بلا ملفِّ تطبيق <span class="bdg wn">{{ count($d['appless']) }}</span></h3>
        <div class="sub" style="margin-bottom:6px">
            لها رابط إنتاجٍ أو متجر ولا سجلَّ تطبيقٍ لها — فلا نسخةَ ولا تقييمَ ولا تحميلاتٍ تُتابَع.
        </div>
        <table class="mini">
            @foreach ($d['appless'] as $p)
                <tr>
                    <td><a href="{{ route('m.show', ['projects', $p['id']]) }}">{{ $p['name'] }}</a>
                        <div class="sub mono" style="font-size:11px">{{ \Illuminate\Support\Str::limit($p['link'], 44) }}</div></td>
                    <td class="acts">
                        @if (hub_can(auth()->user(), 'apps', 'a'))
                            <a class="btn ghost xs" href="{{ route('m.create', ['apps', 'projectId' => $p['id']]) }}">＋ ملف تطبيق</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif
</div>

{{-- الخريطة: كل مشروعٍ وتطبيقاته --}}
@if (count($d['byProject']))
<div class="card" style="margin-top:12px">
    <h3 class="cardtitle">🗺️ كل مشروعٍ وتطبيقاته</h3>
    <table class="mini">
        @foreach ($d['byProject'] as $p)
            <tr>
                <td><a href="{{ route('m.show', ['projects', $p['id']]) }}"><b>{{ $p['project'] }}</b></a></td>
                <td>
                    @foreach ($p['apps'] as $a)
                        <a class="bdg" href="{{ route('m.show', ['apps', $a['id']]) }}">{{ $a['name'] }}{{ $a['platform'] ? ' · ' . $a['platform'] : '' }}</a>
                    @endforeach
                </td>
                <td class="acts mono sub">{{ count($p['apps']) }}</td>
            </tr>
        @endforeach
    </table>
</div>
@endif

@if (! count($d['conflict']) && ! count($d['noProject']) && ! count($d['orphanApps']) && ! count($d['appless']))
    <div class="card"><div class="empty" style="padding:30px 12px"><span class="big">✅</span>
        لا التباس: كل تطبيقٍ تحت مشروعه، وكل سجلٍّ مربوطٍ بتطبيقٍ يحمل مشروعه نفسه.</div></div>
@endif
@endsection
