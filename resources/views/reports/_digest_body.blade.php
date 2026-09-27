{{-- جسمُ ملخّص تقارير المشروع (ذكاء اصطناعي) — يُضمَّن في التفصيل وبطاقة المشروع.
     المدخل: $digest (ReportDigest|null) · $masked (حقلٌ من التقرير محجوبٌ عن القارئ ⇒ لا ملخّص)
     · $full (الأقسامُ كلُّها أم الخلاصةُ وحدَها). النصُّ مولَّدٌ فيُهرَّب — لا HTML من النموذج،
     ورموزُ الأعضاء [P1] تُستبدل بالأسماء هنا وحدَه (الاسمُ لم يغادر الخادم). --}}
@php
    $dgFull = $full ?? true;
    $dgSections = (array) ($digest?->sections ?? []);
    $dgMembers = (array) ($digest?->members ?? []);
    $dgNames = $dgSections === [] || $masked ? [] : \App\Support\Ai\Reports\DigestAccess::names($dgMembers);
    $dgText = fn ($s) => \App\Support\Ai\Reports\DigestAccess::text((string) $s, $dgMembers, $dgNames);
@endphp
@if ($masked)
    @include('partials.empty', ['text' => 'الملخّصُ محجوبٌ عنك: بعضُ حقول التقارير اليوميّة محجوبةٌ في دورك، والملخّصُ مبنيٌّ منها.', 'icon' => '🔒'])
@elseif ($dgSections === [])
    @include('partials.empty', ['text' => $digest?->status === 'failed'
        ? 'تعذّر توليدُ أوّل ملخّصٍ لهذا المشروع (' . ($digest->error_code ?: '—') . ') — يُعاد في الجولة التالية.'
        : 'لم يُلخَّص هذا المشروع بعد — يُلخَّص في الجولة اليوميّة حين تصله تقارير.', 'icon' => '🤖'])
@else
    <div class="sub" style="margin-bottom:6px">
        <span class="bdg i">🤖 مولَّدٌ بالذكاء الاصطناعي</span>
        · آخرُ توليد <span class="mono">{{ optional($digest->generated_at)->format('Y-m-d H:i') }}</span>
        @if ($digest->model) · النموذج <span class="mono">{{ $digest->model }}</span>@endif
        · {{ (int) $digest->reports_count }} تقريراً
        @if ($digest->covered_until) · حتى <span class="mono">{{ $digest->covered_until->format('Y-m-d H:i') }}</span>@endif
        @if ($digest->status === 'failed')<span class="bdg bad" title="الملخّصُ المعروض هو آخرُ ما نجح">آخرُ تحديثٍ أخفق ({{ $digest->error_code }})</span>@endif
        @if ($digest->status === 'stale')<span class="bdg wn">بقيت تقاريرُ تُطوى في الجولة التالية</span>@endif
    </div>
    @if (($dgSections['overview'] ?? '') !== '')
        <p style="margin:6px 0 10px">{{ $dgText($dgSections['overview']) }}</p>
    @endif
    @if ($dgFull)
        @foreach (\App\Support\Ai\Reports\ProjectReportDigest::SECTIONS as $dgKey => $dgLabel)
            @php $dgItems = (array) ($dgSections[$dgKey] ?? []); @endphp
            <div style="margin:8px 0">
                <b>{{ $dgLabel }}</b>
                @if ($dgItems === [])
                    <div class="sub">— لا شيء</div>
                @else
                    <ul style="margin:4px 0;padding-inline-start:20px">
                        @foreach ($dgItems as $dgItem)<li>{{ $dgText($dgItem) }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif
    <div class="sub" style="margin-top:6px">ملخّصٌ آليٌّ من التقارير اليوميّة — قد يخطئ؛ التقاريرُ نفسُها هي المرجع.</div>
@endif
