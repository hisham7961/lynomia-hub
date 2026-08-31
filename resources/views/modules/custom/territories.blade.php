{{-- شجرة المنطقة وتغطيتها — من فوقها ومن تحتها ومن يغطيها الآن --}}
@php
    $trParent = $row->parent_id ? \App\Models\Territory::whereNull('deleted_at')->find($row->parent_id) : null;
    $trKids = hub_scope(\App\Models\Territory::whereNull('deleted_at')
        ->where('parent_id', $row->id), 'territories')->orderBy('name')->get(['id', 'name', 'kind']);
    $trSub = $row->subtreeIds();
    $trAssigns = hub_scope(\App\Models\TerritoryAssignment::whereNull('deleted_at')
        ->whereIn('territory_id', $trSub)->where('status', 'ساري'), 'terrassigns')
        ->orderBy('date_start')->orderBy('id')->get();
    $trRepNames = hub_ref_labels('hr', $trAssigns->pluck('emp_id')->unique()->values()->all());
    $trHcps = hub_scope(\App\Models\Hcp::whereNull('deleted_at')->whereIn('territory_id', $trSub), 'hcps')->count();
    $trFacs = hub_scope(\App\Models\Facility::whereNull('deleted_at')->whereIn('territory_id', $trSub), 'facilities')->count();
@endphp
<div class="card">
    <h3 class="cardtitle">🗺️ موقعها في الشجرة</h3>
    <div class="crow">
        @if ($trParent)
            <a class="chip" href="{{ route('m.show', ['territories', $trParent->id]) }}">⬆️ {{ $trParent->name }}</a>
            <span class="sub">/</span>
        @endif
        <span class="chip"><b>{{ $row->name }}</b>@if ($row->kind) — {{ $row->kind }}@endif</span>
        @foreach ($trKids as $k)
            <a class="chip" href="{{ route('m.show', ['territories', $k->id]) }}">⬇️ {{ $k->name }}</a>
        @endforeach
    </div>
    <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:8px">
        <div><div class="sub">مقدمو رعاية (مع الفروع)</div><b class="mono">{{ $trHcps }}</b></div>
        <div><div class="sub">منشآت (مع الفروع)</div><b class="mono">{{ $trFacs }}</b></div>
        <div><div class="sub">إسنادات سارية</div><b class="mono">{{ $trAssigns->count() }}</b></div>
    </div>
</div>
<div class="card">
    <h3 class="cardtitle">🧭 من يغطيها الآن</h3>
    @forelse ($trAssigns as $a)
        <div class="crow" style="margin-bottom:4px">
            <a class="chip" href="{{ route('m.show', ['terrassigns', $a->id]) }}">{{ $trRepNames[$a->emp_id] ?? '؟' }}</a>
            @if ($a->role)<span class="bdg">{{ $a->role }}</span>@endif
            <span class="sub">منذ {{ optional($a->date_start)->format('Y-m-d') }}@if ($a->territory_id !== $row->id) — في منطقةٍ فرعية@endif</span>
        </div>
    @empty
        <div class="sub">لا مندوبَ يغطيها — يُفتح الإسناد من وحدة «إسناد المناطق»، والنقلُ إنهاءُ إسنادٍ وفتحُ غيرِه لا تعديلُ التاريخ.</div>
    @endforelse
</div>
