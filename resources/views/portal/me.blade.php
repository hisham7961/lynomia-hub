@extends('layouts.app')
@section('title', 'بوابتي — الصندوق الموحد')
@section('content')
@php
    $filter = hub_str(request()->query('k', ''));
    $kinds = collect($inbox)->groupBy('kind')->map->count();
    $shown = $filter !== '' ? collect($inbox)->where('kind', $filter)->values()->all() : $inbox;
    $kindLabels = collect($inbox)->pluck('label', 'kind')->all();
    $kindIcons = collect($inbox)->pluck('icon', 'kind')->all();
    $lastBucket = null;
@endphp

<div class="hero">
    <div>
        <h2>👤 {{ auth()->user()->name }}</h2>
        <div class="sub">
            @if ($emp){{ $emp->title ?? '' }}{{ $emp->dept ? ' · ' . $emp->dept : '' }}{{ $emp->hired ? ' · معيّن منذ ' . substr($emp->hired, 0, 10) : '' }}
            @else لا ملف وظيفي مرتبط بحسابك بعد — يضيفه قسم الموارد البشرية @endif
        </div>
    </div>
    <div style="display:flex;gap:8px">
        <a class="btn ghost sm" href="{{ route('notifications.index') }}">🔔 إشعاراتي</a>
        <a class="btn ghost sm" href="{{ route('profile.edit') }}">⚙️ حسابي</a>
    </div>
</div>

{{-- ── أوّل أسبوع (الجولة 1 · F29): دليلُ الأيام الأولى بدل «يوم هادئ» وستةِ أصفار.
     البياناتُ من Staff::firstWeek — استعلاماتٌ خفيفةٌ لا تجري إلا داخل نافذة 14 يوماً،
     وكلُّ رابطٍ يُعرض فقط إن كان بابُه مفتوحاً لدور صاحبه (لا رابطَ يقود إلى 403). ── --}}
@php $fw = \App\Support\Staff::firstWeek(auth()->user(), $emp); @endphp
@if ($fw)
    <div class="card" style="margin-bottom:12px;border-inline-start:4px solid var(--ok,#27ae60)">
        <h3 style="margin-bottom:4px">🌱 أوّل أسبوع؟ خطواتك الأولى هنا</h3>
        {{-- **العدُّ يُحصي ما يُعرض** (الجولة 2 · G19): كان النصّ يَعِد بـ«أربعِ خطوات»
             ثابتاً بينما البطاقةُ تعرض ثلاثاً في الحالة الغالبة (رابطُ دليلِ الموظف
             الجديد لا يظهر إن غابت وثيقتُه، وخطوةُ الفريق تسقط إن كان الدليلُ
             فارغاً) — فأوّلُ ما يقرؤه الموظفُ الجديد في النظام عددٌ لا يصدق. --}}
        @php
            $fwN = count($fw['items']);
            $fwWord = [1 => 'خطوةٌ واحدة', 2 => 'خطوتان', 3 => 'ثلاثُ خطواتٍ',
                       4 => 'أربعُ خطواتٍ', 5 => 'خمسُ خطواتٍ'][$fwN] ?? ($fwN . ' خطواتٍ');
        @endphp
        <div class="sub" style="margin-bottom:8px">أهلاً بك — {{ $fwWord }} تجعل أيامك الأولى واضحة،
            وتختفي هذه البطاقة وحدها بعد أسبوعين.</div>
        @foreach ($fw['items'] as $it)
            <div style="display:flex;gap:9px;align-items:center;padding:5px 0;border-bottom:1px solid var(--ln)">
                <span style="flex:none">{{ $it['icon'] }}</span>
                <span style="flex:1;min-width:0;{{ $it['done'] === true ? 'opacity:.55' : '' }}">{{ $it['label'] }}</span>
                @if ($it['done'] === true)
                    <span class="bdg ok">تمّ ✓</span>
                @elseif ($it['url'])
                    <a class="btn ghost xs" href="{{ $it['url'] }}">افتح ↗</a>
                @endif
            </div>
        @endforeach
    </div>
@endif

{{-- ── الصندوق الموحّد: صفٌّ واحد مرتّب بالإلحاح، لا أحد عشر جدولاً ── --}}
<div class="card" style="margin-bottom:12px">
    <h3 style="margin-bottom:8px">📥 ينتظر تصرّفي
        @if (count($inbox))<span class="bdg {{ collect($inbox)->where('bucket', 0)->count() ? 'bad' : 'wn' }}">{{ count($inbox) }}</span>@endif
    </h3>

    @if (count($inbox))
        {{-- الحاويات: كم متأخر وكم اليوم — قبل أي تفصيل --}}
        <div class="filters" style="gap:12px;margin-bottom:10px">
            @foreach ($buckets as $b)
                <span class="sub">{{ $b['icon'] }} {{ $b['label'] }} <b>{{ $b['n'] }}</b></span>
            @endforeach
        </div>

        {{-- تصفية بالنوع — لمن يريد أن يُنهي الموافقات كلها دفعةً --}}
        <div class="filters" style="margin-bottom:10px">
            <a class="btn xs {{ $filter === '' ? 'p' : 'ghost' }}" href="{{ route('portal.me') }}">الكل {{ count($inbox) }}</a>
            @foreach ($kinds as $k => $n)
                <a class="btn xs {{ $filter === $k ? 'p' : 'ghost' }}" href="{{ route('portal.me') }}?k={{ $k }}">
                    {{ $kindIcons[$k] ?? '•' }} {{ $kindLabels[$k] ?? $k }} {{ $n }}
                </a>
            @endforeach
        </div>
    @endif

    @forelse ($shown as $it)
        @if ($lastBucket !== $it['bucket'])
            @php $lastBucket = $it['bucket']; $bk = \App\Support\Inbox::BUCKETS[$it['bucket']]; @endphp
            <div class="sub" style="margin:12px 0 4px;font-weight:600">{{ $bk['icon'] }} {{ $bk['label'] }}</div>
        @endif
        <div class="inbrow">
            <span class="inbico" title="{{ $it['label'] }}">{{ $it['icon'] }}</span>
            <div style="min-width:0;flex:1">
                <a href="{{ $it['url'] }}"><b>{{ \Illuminate\Support\Str::limit($it['title'], 78) }}</b></a>
                <div class="sub" style="font-size:12px">
                    <span class="bdg">{{ $it['label'] }}</span>
                    {{ $it['why'] }}
                    @if ($it['days'] !== null)
                        · <b class="{{ $it['days'] < 0 ? 'txt-bad' : '' }}">
                            {{ $it['days'] < 0 ? 'متأخر ' . abs($it['days']) . ' يوماً'
                                               : ($it['days'] === 0 ? 'يستحق اليوم' : 'بعد ' . $it['days'] . ' يوماً') }}
                        </b>
                    @endif
                </div>
            </div>
            @if ($it['act'])
                {{-- الحسم من هنا: القرار الذي ينتظرك لا يستحق ثلاث شاشات --}}
                <div style="display:flex;gap:5px;flex:none">
                    <form method="POST" action="{{ $it['act']['ok'] }}" data-confirm="اعتماد «{{ $it['title'] }}» وتنفيذه؟">
                        @csrf<button class="btn ok xs">✔ اعتماد</button>
                    </form>
                    <form method="POST" action="{{ $it['act']['no'] }}" data-confirm="رفض «{{ $it['title'] }}»؟">
                        @csrf<button class="btn ghost xs">✕ رفض</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <div class="empty" style="padding:26px 12px"><span class="big">🎉</span>
            @if ($filter !== '')
                لا شيء من هذا النوع ينتظرك — <a class="lnk" href="{{ route('portal.me') }}">اعرض الكل</a>
            @else
                صندوقك خالٍ — لا موافقة معلّقة ولا إقرار ولا التزامٌ متأخر.
            @endif
        </div>
    @endforelse
</div>

@include('portal._hr')

<style>
.inbrow { display:flex; gap:10px; align-items:center; padding:9px 4px; border-bottom:1px solid var(--ln) }
.inbrow:last-child { border-bottom:0 }
.inbico { flex:none; font-size:18px; width:26px; text-align:center }
</style>
@endsection
