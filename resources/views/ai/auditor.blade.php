@extends('layouts.app')
@section('title', 'المدقّق — دقّتُه وحالتُه')
@section('content')

{{-- ═══ المدقّق (docs/ai-hub/46-ai-roadmap.md §٣.٥ · A4) — أعدادٌ لا محتوى ═══
     النتائجُ نفسُها في مركز الفعل مُعادةَ التنطيق لكلِّ مشاهد؛ هنا دقّةُ كلِّ كاشف: ماذا فعل
     المديرون بإشاراته فعلاً — إقرارٌ وتأجيلٌ (صحيحٌ وأتابعه) أو رفضٌ (ليس صحيحاً). --}}

<div class="hero">
    <div>
        <h2>🔎 المدقّق</h2>
        <div class="sub">
            يقرأ عملَ الفريق يوميّاً ويقترح ولا يكتب. وكاشفٌ يرفض المديرون
            <b>{{ (int) round($maxRate * 100) }}٪</b> فأكثر من إشاراته على <b>{{ $minN }}</b> تصرّفاً فأكثر
            خلال <b>{{ $window }}</b> يوماً يُطفأ آليّاً ويُبلَّغ المالك.
        </div>
    </div>
</div>

@include('ai._sections')

<div class="card">
    <h3>الحالة</h3>
    <div class="sub">
        المدقّق: <b>{{ $enabled ? 'يعمل' : 'مطفأ (auditor.enabled)' }}</b>
        · كواشفُ الذكاء: <b>{{ $aiWhyNot === null ? 'جاهزة' : $aiWhyNot }}</b>
        · الغرض: <span class="mono">{{ $profile }}</span>
        · سقفُ النداءات في الجولة: <b>{{ $maxCalls }}</b>
    </div>
</div>

<div class="card">
    <h3>الكواشف ودقّتُها <span class="sub">— آخرُ {{ $window }} يوماً</span></h3>
    <table class="tbl" data-auditor-accuracy>
        <thead><tr>
            <th>الكاشف</th><th>النوع</th><th>مفتوحة</th><th>زال شرطُها</th>
            <th>أقرّها المديرون</th><th>أجّلوها</th><th>رفضوها</th><th>نسبةُ الرفض</th><th>الحالة</th>
        </tr></thead>
        <tbody>
        @foreach ($rows as $row)
            @php $total = $row['ack'] + $row['snoozed'] + $row['dismissed']; @endphp
            <tr data-detector="{{ $row['key'] }}">
                <td><b>{{ $row['label'] }}</b> <span class="sub mono">{{ $row['key'] }}</span></td>
                <td>{{ $row['source'] === 'ai' ? 'بالذكاء' : 'حسابيّ' }}</td>
                <td>{{ $row['open'] }}</td>
                <td>{{ $row['resolved'] }}</td>
                <td>{{ $row['ack'] }}</td>
                <td>{{ $row['snoozed'] }}</td>
                <td>{{ $row['dismissed'] }}</td>
                <td>
                    @if ($row['rate'] === null) <span class="sub">لا تصرّفَ بعد</span>
                    @else
                        <span class="bdg {{ $row['rate'] >= $maxRate ? '' : 'ok' }}">{{ (int) round($row['rate'] * 100) }}٪</span>
                        @if ($total < $minN)<span class="sub">({{ $total }} من {{ $minN }} قبل الحكم)</span>@endif
                    @endif
                </td>
                <td>
                    <span class="bdg {{ $row['disabled'] ? 'wn' : 'ok' }}">{{ $row['disabled'] ? 'مطفأ' : 'يعمل' }}</span>
                    @if ($manage)
                        <form method="post" action="{{ route('ai.auditor.toggle', $row['key']) }}" style="display:inline">
                            @csrf
                            <button class="btn ghost xs">{{ $row['disabled'] ? 'أعِد تشغيله' : 'أطفئه' }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

@endsection
