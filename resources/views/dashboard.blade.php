@extends('layouts.app')
@section('title', 'لوحة التحكم')
@section('content')
<div class="hero">
    <div>
        <h2>أهلاً {{ auth()->user()->name }} 👋</h2>
        <div class="sub">{{ now()->translatedFormat('l · j F Y') }}</div>
    </div>
</div>
@php $icons = ['projects' => '🚀', 'clients' => '🤝', 'tasks' => '✅', 'tickets' => '🎫', 'fin' => '💵', 'contracts' => '📜']; @endphp
<div class="cards">
    @foreach ($cards as $c)
        <a class="stat" href="{{ route('m.index', $c['key']) }}">
            <span class="ico">{{ $icons[$c['key']] ?? '📁' }}</span>
            <b>{{ number_format($c['count']) }}</b><span>{{ $c['label'] }}</span>
        </a>
    @endforeach
</div>
<div class="kids">
    @if ($expiry->count())
    <div class="card kid">
        <h3>🔔 ينتهي قريباً <a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('alerts') }}">الكل ←</a></h3>
        <table class="mini">
            @foreach ($expiry as $i)
                <tr>
                    <td><a href="{{ route('m.show', [$i['module'], $i['id']]) }}">{{ \Illuminate\Support\Str::limit($i['name'], 26) }}</a><div class="sub">{{ $i['mlabel'] }} · {{ $i['flabel'] }}</div></td>
                    <td style="width:1%"><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'wn' : 'g') }}">{{ $i['days'] < 0 ? 'متأخر' : ($i['days'] === 0 ? 'اليوم' : $i['days'] . ' يوم') }}</span></td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif
    @if ($apps->count())
    <div class="card kid">
        <h3>📱 تقدم التطبيقات</h3>
        <table class="mini">
            @foreach ($apps as $a)
                <tr>
                    <td><a href="{{ route('apps.center', $a->id) }}">{{ \Illuminate\Support\Str::limit($a->name, 24) }}</a>
                        <div class="pbar sm"><span style="width:{{ (int) ($a->progress ?? 0) }}%"></span></div>
                        <div class="sub">{{ $a->ver ? 'v' . $a->ver . ' · ' : '' }}{{ $a->status }}</div></td>
                    <td style="width:1%"><b>{{ $a->progress !== null ? $a->progress . '٪' : '—' }}</b></td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif
    <div class="card kid" id="recentbox" hidden>
        <h3>📌 آخر ما فتحت</h3>
        <div id="recentlist" class="rl"></div>
    </div>
    @if ($due->count())
    <div class="card kid">
        <h3>⏰ مهام تقترب مواعيدها</h3>
        <table class="mini">
            @foreach ($due as $t)
                <tr>
                    <td><a href="{{ route('m.show', ['tasks', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->{$disp}, 38) }}</a></td>
                    <td class="mono" style="width:1%;white-space:nowrap">{{ substr($t->{$dueCol}, 0, 10) }}</td>
                    @if ($stCol)<td style="width:1%"><span class="bdg {{ hub_tone($t->{$stCol}) }}">{{ $t->{$stCol} }}</span></td>@endif
                </tr>
            @endforeach
        </table>
    </div>
    @endif
    <div class="card kid" style="grid-column:span 1">
        <h3>🕘 آخر النشاطات</h3>
        <table class="mini">
            @forelse ($audits as $a)
                <tr>
                    <td style="width:1%;white-space:nowrap"><span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : 'g') }}">{{ $a->action }}</span></td>
                    <td>{{ hub_mod($a->module)['label'] ?? $a->module }}: {{ \Illuminate\Support\Str::limit($a->name, 30) }}</td>
                    <td class="mono sub" style="width:1%;white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
                </tr>
            @empty
                <tr><td class="empty">لا نشاط بعد — ابدأ بإضافة أول سجل</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
