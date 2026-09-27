{{-- بطاقةُ «ملخّص التقارير (ذكاء اصطناعي)» في صفحة المشروع — لمن يرى المشروعَ (الصفحةُ نفسُها
     حرستْه) ويملك updates:v وليس عميلاً (DigestAccess::canUse). الخلاصةُ هنا، والأقسامُ في التفصيل. --}}
@php
    $dgU = auth()->user();
    $dgRow = \App\Support\Ai\Reports\DigestAccess::canUse($dgU)
        ? \App\Models\ReportDigest::query()->where('project_id', $projectId)->orderBy('id')->first() : null;
@endphp
@if (\App\Support\Ai\Reports\DigestAccess::canUse($dgU) && ($dgRow !== null || \App\Support\Ai\Reports\ProjectReportDigest::enabled()))
    <div class="card" data-report-digest>
        <h3 class="cardtitle">🤖 ملخّص التقارير (ذكاء اصطناعي)
            <a class="btn ghost xs" href="{{ route('reports.projects.show', $projectId) }}">التفصيل وأحدثُ التقارير ↗</a>
        </h3>
        @include('reports._digest_body', ['digest' => $dgRow, 'masked' => \App\Support\Ai\Reports\DigestAccess::masked($dgU), 'full' => true])
    </div>
@endif
