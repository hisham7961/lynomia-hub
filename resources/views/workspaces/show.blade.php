@extends('layouts.app')
@section('title', $ws['label'])
@section('content')
{{-- صفحة مساحة العمل المركزية (CTO م2): المجال كله في شاشة واحدة --}}
@component('partials.pagehead', ['icon' => $ws['icon'], 'title' => $ws['label'],
    'crumb' => 'مساحات العمل', 'sub' => $ws['desc']])
    @foreach (collect($ws['modules'])->take(1) as $mk)
        @if (hub_can(auth()->user(), $mk, 'a'))
            <a class="btn p sm" href="{{ route('m.create', $mk) }}">＋ {{ hub_mod($mk)['label'] }}</a>
        @endif
    @endforeach
@endcomponent

{{-- شريط تنقل بين المساحات — سياقٌ دائم بلا عودة للقائمة --}}
<div class="toolbar" style="gap:6px">
    @foreach ($all as $k => $w)
        <a class="btn {{ $k === $ws['key'] ? 'p' : 'ghost' }} xs" href="{{ route('workspace', $k) }}">{{ $w['icon'] }} {{ $w['label'] }}</a>
    @endforeach
    {{-- (الطور H · WP-H.3 · §37–38) توسيعُ مساحة التقنية: بابُ مساحةِ العمل التقنية —
         الرابطُ يظهر لمن يبلغها فعلاً (داخليٌّ غيرُ معزولٍ بعملاءَ ولا بشركات، مالكٌ
         أو مراقب)، والحرسُ الحقيقيُّ في متحكّمها لا في إخفاء الرابط. --}}
    @if ($ws['key'] === 'digital'
         && ! hub_is_client(auth()->user()) && hub_client_ids() === null
         && (hub_is_owner(auth()->user()) || hub_monitor(auth()->user()))
         && hub_company_ids() === null)
        <a class="btn ghost xs" style="border-color:var(--p)" href="{{ route('tech.workspace') }}">🛠️ مساحة العمل التقنية</a>
    @endif
</div>

{{-- مؤشرات حقيقية: مجاميع محسوبة فعلاً لا نسب مزعومة --}}
@php
    $total = collect($cards)->sum('count');
    $week = collect($cards)->sum('week');
@endphp
<div class="cards">
    <div class="stat"><span class="ico" aria-hidden="true">{{ $ws['icon'] }}</span><b>{{ number_format($total) }}</b><span>سجلاً في المساحة</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">🆕</span><b>{{ number_format($week) }}</b><span>أُضيف آخر ٧ أيام</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">⏳</span><b class="{{ $expiry->count() ? 'txt-bad' : '' }}">{{ $expiry->count() }}</b><span>يستحق أو ينتهي قريباً</span></div>
    <div class="stat"><span class="ico" aria-hidden="true">🗂</span><b>{{ count($ws['modules']) }}</b><span>وحدة نشطة</span></div>
</div>

<div class="kids">
    {{-- بطاقات الوحدات مُقسَّمةً حسب IA (P3): نفسُ الوحدات والمراكز، مجموعةً في أقسام
         مجالها بدل قائمةٍ مسطّحة — بطاقاتٌ ومراكزُ الترميزِ نفسِها (لا تغييرَ بصريّ). --}}
    <div class="card kid wide">
        <h3>🗂 وحدات المساحة</h3>
        @foreach ($layout['sections'] as $sec)
            <h4 id="sec-{{ $sec['key'] }}" style="margin:14px 0 6px;font-size:13px;opacity:.75">{{ $sec['label'] }}</h4>
            @if (count($sec['modules']))
                <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(210px,100%),1fr))">
                    @foreach ($sec['modules'] as $mk)
                        @include('workspaces._modcard', ['mk' => $mk, 'cards' => $cards, 'attention' => $attention])
                    @endforeach
                </div>
            @endif
            {{-- مراكزُ هذا القسم (مجموعةً تحت قسمها — WP-3.3) --}}
            @if (count($sec['centers']))
                <div class="crow" style="margin-top:8px">
                    @foreach ($sec['centers'] as $c)
                        <a class="btn ghost xs" href="{{ route($c['route']) }}">{{ $c['label'] }}</a>
                    @endforeach
                </div>
            @endif
        @endforeach

        {{-- وحداتٌ لم يُصنّفها IA في قسمٍ — تبقى معروضةً (صفر فقدان) --}}
        @if (count($layout['ungrouped_modules']))
            <h4 style="margin:14px 0 6px;font-size:13px;opacity:.75">أخرى</h4>
            <div class="cards" style="grid-template-columns:repeat(auto-fill,minmax(min(210px,100%),1fr))">
                @foreach ($layout['ungrouped_modules'] as $mk)
                    @include('workspaces._modcard', ['mk' => $mk, 'cards' => $cards, 'attention' => $attention])
                @endforeach
            </div>
        @endif

        <div class="crow" style="margin-top:10px">
            @foreach ($ws['modules'] as $mk)
                @if (hub_can(auth()->user(), $mk, 'a'))
                    <a class="btn ghost xs" href="{{ route('m.create', $mk) }}">＋ {{ hub_mod($mk)['label'] }}</a>
                @endif
            @endforeach
        </div>
    </div>

    {{-- مراكزُ المساحة غيرُ المنتمية لقسمٍ (بيتُها مجالٌ آخر) — تبقى معروضةً هنا (صفر فقدان) --}}
    @if (count($layout['ungrouped_centers']))
        <div class="card kid">
            <h3>📊 مراكز المساحة</h3>
            @foreach ($layout['ungrouped_centers'] as $c)
                <a class="btn ghost sm" style="display:flex;margin-bottom:6px;justify-content:flex-start" href="{{ route($c['route']) }}">{{ $c['label'] }}</a>
            @endforeach
        </div>
    @endif

    {{-- يستحق قريباً — من رادار الانتهاء الحقيقي --}}
    <div class="card kid">
        <h3>⏳ يستحق أو ينتهي قريباً</h3>
        <table class="mini">
            @forelse ($expiry as $i)
                <tr>
                    <td><a href="{{ route('m.show', [$i['module'], $i['id']]) }}">{{ \Illuminate\Support\Str::limit($i['name'], 34) }}</a>
                        <div class="sub">{{ $i['mlabel'] }} · {{ $i['flabel'] }}</div></td>
                    <td class="acts"><span class="bdg {{ $i['days'] < 0 ? 'bad' : ($i['days'] <= 7 ? 'bad' : 'wn') }}">{{ $i['days'] < 0 ? 'متأخر ' . abs($i['days']) : $i['days'] }} يوم</span></td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا استحقاقات قريبة في هذه المساحة</td></tr>
            @endforelse
        </table>
    </div>

    {{-- آخر النشاط في وحدات المساحة --}}
    <div class="card kid wide">
        <h3>🕘 آخر النشاط</h3>
        <table class="mini">
            @forelse ($activity as $a)
                <tr>
                    <td class="acts mono sub">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</td>
                    <td>{{ $actors[$a->user_id] ?? '—' }} · <b>{{ $a->action }}</b>
                        <span class="sub">{{ hub_mod($a->module)['label'] ?? $a->module }}{{ $a->name ? ' — ' . $a->name : '' }}</span></td>
                    <td class="acts">
                        @if ($a->record_id && hub_mod($a->module))
                            <a class="btn ghost xs" href="{{ route('m.show', [$a->module, $a->record_id]) }}">فتح</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا نشاط مسجلاً بعد في وحدات هذه المساحة</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
