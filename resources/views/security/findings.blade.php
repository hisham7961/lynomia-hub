@extends('layouts.app')
@section('title', 'النتائج الأمنية')
@section('content')
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل"><span>النظام</span><span aria-hidden="true">‹</span>@include('security.parts.root_crumb')<span aria-hidden="true">‹</span><b>النتائج والتوصيات</b></nav>
        <h2>🔎 النتائج الأمنية</h2>
        <div class="sub">صفٌّ واحد لكل مشكلة: منذ متى تُرصَد، ومن أقرّ بها، وكيف تُصلَح — يُغلَق تلقائياً ما زال شرطُه ولا يُحذف تاريخُه</div>
    </div>
</div>

@include('partials.cc.kpis', ['items' => [
    ['label' => 'حرجة غير محلولة', 'value' => $kpi['critical'], 'tone' => $kpi['critical'] ? 'bad' : 'ok'],
    ['label' => 'مرتفعة غير محلولة', 'value' => $kpi['high'], 'tone' => $kpi['high'] ? 'wn' : 'ok'],
    ['label' => 'غير محلولة إجمالاً', 'value' => $kpi['unresolved'], 'tone' => $kpi['unresolved'] ? 'wn' : 'ok'],
    ['label' => 'حُلّت خلال ٣٠ يوماً', 'value' => $kpi['resolved30'], 'tone' => 'ok'],
    ['label' => 'درجة الوضعية', 'value' => $score === null ? '—' : ((int) $score) . '٪',
     'sub' => $score === null ? 'لا قياس بعد — تبدأ مع أول لقطة يومية' : 'من آخر لقطة يومية (23:40)'],
]])

{{-- (WP-4.2) اتجاهُ الوضعية من اللقطة اليومية — «لا قياس» ليس صفراً --}}
<div class="card" style="margin-bottom:12px">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">📈 اتجاه الوضعية <span class="sub">(نسبة الفحوص السليمة ٪)</span></h3>
        <div class="crow" style="gap:6px">
            @foreach ([7, 30, 90] as $sfD)
                <a class="btn ghost xs" @if ($days === $sfD) style="border-color:var(--p);color:var(--p)" aria-current="true" @endif
                   href="{{ route('security.findings', array_filter(['st' => $st, 'sev' => $sev]) + ['d' => $sfD]) }}">{{ $sfD }} يوماً</a>
            @endforeach
        </div>
    </div>
    <div style="margin-top:8px">
        @include('partials.cc.trend', ['series' => $series, 'unit' => '٪',
                 'empty' => 'سيبدأ القياس من الآن — اللقطةُ اليومية (23:40) تكتب أول نقطة، ولا يُرسَم صفرٌ مُختلَق'])
    </div>
</div>

<div class="card">
    <div class="crow" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
        <h3 style="margin:0">🧾 النتائج</h3>
        <form method="GET" class="crow" style="gap:6px;flex-wrap:wrap">
            <input type="hidden" name="d" value="{{ $days }}">
            <label class="vh" for="st">تصفية بالحالة</label>
            <select class="inp" id="st" name="st" onchange="this.form.submit()">
                <option value="">كل الحالات</option>
                @foreach (\App\Support\SecurityFindings::STATUSES as $sfK => $sfL)
                    <option value="{{ $sfK }}" @selected($st === $sfK)>{{ $sfL }}</option>
                @endforeach
            </select>
            <label class="vh" for="sev">تصفية بالشدّة</label>
            <select class="inp" id="sev" name="sev" onchange="this.form.submit()">
                <option value="">كل الشدّات</option>
                @foreach (\App\Support\Severity::LEVELS as $sfLv)
                    <option value="{{ $sfLv }}" @selected($sev === $sfLv)>{{ \App\Support\Severity::LABELS[$sfLv] }}</option>
                @endforeach
            </select>
        </form>
    </div>
    <div class="sub" style="margin:4px 0 10px">العنوانُ يفتح التفصيلَ والدليل؛ والتوصيةُ من الفحص نفسِه لا نصٌّ مُخترَع. الأحدثُ رصداً أولاً.</div>
    @php
        /* الصفوفُ بشكل cc/findings الواحد — وشارةُ الحالة وأزرارُ المالك في عمود الأفعال */
        $sfRows = [];
        foreach ($rows as $sfF) {
            $sfEv = json_decode((string) $sfF->evidence, true) ?: [];
            $sfActs = '<span class="bdg ' . (['open' => 'bad', 'acknowledged' => 'wn', 'resolved' => 'ok', 'ignored' => 'g'][$sfF->status] ?? 'g') . '">'
                . e(\App\Support\SecurityFindings::STATUSES[$sfF->status] ?? $sfF->status) . '</span>';
            if (! empty($isOwner)) $sfActs .= ' ' . view('security.parts.finding_actions', ['f' => $sfF])->render();
            $sfRows[] = [
                'sev' => $sfF->severity, 'title' => $sfF->title, 'why' => $sfF->description,
                'fix' => $sfF->remediation ?: '—', 'url' => route('security.finding', $sfF->id),
                'n' => (int) ($sfEv['n'] ?? 0),
                'at' => \Illuminate\Support\Carbon::parse($sfF->last_seen_at),
                'actions' => $sfActs,
            ];
        }
    @endphp
    @include('partials.cc.findings', ['rows' => $sfRows,
             'empty' => ($st !== '' || $sev !== '') ? 'لا نتائج بهذا التصفية' : 'لا نتائج مرصودة بعد — تُكتب مع أول لقطةٍ يومية أو تسوية'])
    {{ $rows->links('partials.pagination') }}
</div>
@endsection
