@extends('layouts.app')
@section('title', 'تقارير حسب المشروع')
@section('content')
<div class="hero">
    <div>
        <h2>📁 تقارير حسب المشروع <span class="bdg i">🤖 ذكاء اصطناعي</span></h2>
        <div class="sub">ملخّصٌ لكلِّ مشروعٍ يكتبه الذكاءُ الاصطناعيّ من التقارير اليوميّة — الإنجازات والعوائق والمخاطر
            والخطوة التالية ومساهمات الفريق — ويتحدّث دوريّاً بما يُقدَّم من تقاريرَ جديدة.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_can(auth()->user(), 'hr', 'v'))<a class="btn ghost sm" href="{{ route('reports.index') }}">📊 مركز التقارير</a>@endif
        @if (\App\Support\Workforce\ReportReview::canReviewAny(auth()->user()))<a class="btn ghost sm" href="{{ route('reports.review') }}">📥 تقارير للمراجعة</a>@endif
    </div>
</div>

@if ($why)
    <div class="card"><span class="bdg wn">متوقّف</span> <span class="sub">{{ $why }}</span></div>
@endif

<form method="get" class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <label>المشروع<br><input type="text" name="q" value="{{ $q }}" placeholder="اسم المشروع" class="in"></label>
    <button class="btn sm">بحث</button>
    @if ($waiting > 0)<span class="sub">{{ $waiting }} مشروعاً له تقاريرُ حديثةٌ ينتظر أوّلَ ملخّص</span>@endif
</form>

<div class="card">
    @if ($masked)
        @include('partials.empty', ['text' => 'الملخّصاتُ محجوبةٌ عنك: بعضُ حقول التقارير اليوميّة محجوبةٌ في دورك.', 'icon' => '🔒'])
    @else
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>المشروع</th><th>الخلاصة</th><th>تقارير</th><th>آخر تحديث</th><th>الحالة</th><th></th></tr></thead>
        <tbody>
        @forelse ($digests as $dg)
            @php
                $pid = (string) $dg->project_id;
                $behind = isset($latest[$pid]) && $dg->covered_until && $latest[$pid] > $dg->covered_until->format('Y-m-d H:i:s');
                $ov = (string) (((array) $dg->sections)['overview'] ?? '');
                $ovNames = $ov === '' ? [] : \App\Support\Ai\Reports\DigestAccess::names((array) $dg->members);
            @endphp
            <tr>
                <td><b>{{ $names[$pid] ?? '—' }}</b></td>
                <td class="sub">{{ $ov === '' ? '—' : \Illuminate\Support\Str::limit(\App\Support\Ai\Reports\DigestAccess::text($ov, (array) $dg->members, $ovNames), 160) }}</td>
                <td class="mono">{{ (int) $dg->reports_count }}</td>
                <td class="sub mono">{{ optional($dg->generated_at)->format('Y-m-d H:i') ?: '—' }}</td>
                <td>
                    @if ($dg->status === 'failed')<span class="bdg bad">أخفق ({{ $dg->error_code }})</span>
                    @elseif ($behind || $dg->status === 'stale')<span class="bdg wn">بانتظار التحديث</span>
                    @else<span class="bdg ok">محدَّث</span>@endif
                </td>
                <td><a class="btn ghost xs" href="{{ route('reports.projects.show', $pid) }}">التفصيل ↗</a></td>
            </tr>
        @empty
            @include('partials.empty', ['text' => 'لا ملخّصاتَ بعد لمشاريعَ في نطاقك', 'icon' => '🤖', 'colspan' => 6])
        @endforelse
        </tbody>
    </table></div>
    @endif
</div>
@endsection
