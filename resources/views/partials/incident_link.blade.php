{{-- ── Control Plane: Phase 6 (WP-6.2) ──
     **اربط بحادثة** (§8.2): زرٌّ واحدٌ تستعمله شاشاتُ المصادر الثلاث (الخطأ، قيدُ
     التدقيق، الحدثُ الأمنيّ) فلا يتكرّر نموذجٌ في ثلاثة ملفات.

     المعاملات: $ilKind (نوعُ الدليل) · $ilSummary (ملخّصٌ للبشر) · $ilRef (بصمة/
     معرّفُ طلب/رقمُ قيد — مفتاحُ «لا تكرار») · $ilModule/$ilRecord (اختياريان).

     — لا يظهر أصلاً لمن لا يملك تحريرَ الحوادث (نفسُ حارس المسار، فلا زرٌّ يقود
       إلى ٤٠٣). والقائمةُ **منطَّقةٌ** بـhub_scope كأيّ قارئٍ آخر.
     — نموذجٌ صغيرٌ لكل حادثة (بلا جافاسكربت): القائمةُ المنسدلة كانت تحتاج تعيينَ
       وجهةِ النموذج عند الاختيار، وبتعطُّل النصّ البرمجيّ يُربط الدليلُ بحادثةٍ
       أخرى صامتاً — وربطٌ خاطئٌ أسوأُ من نقرتين. --}}
@php
    $ilCan = hub_can(auth()->user(), 'incidents', 'e');
    $ilOpen = $ilCan
        ? hub_open_scope(hub_scope(\App\Models\Incident::whereNull('deleted_at'), 'incidents'), 'status')
            ->orderByDesc('started_at')->orderByDesc('id')->limit(8)
            ->get(['id', 'title', 'severity', 'status'])
        : collect();
@endphp
@if ($ilCan)
    <div class="card">
        <h3 class="cardtitle">🔗 اربط بحادثة</h3>
        @if ($ilOpen->isEmpty())
            @include('partials.empty', ['icon' => '🚨',
                'text' => 'لا حادثةَ مفتوحةً ضمن نطاقك الآن — افتح حادثةً أولاً ثم اربط هذا الدليلَ بها'])
        @else
            <div class="sub" style="margin-bottom:8px">
                الدليلُ يظهر في الخطّ الزمنيّ للحادثة وفي بطاقة أثرها. وإعادةُ الربط بالمرجع نفسه
                <b>تحدّث</b> الملخّص ولا تكرّر الصفّ.
            </div>
            @foreach ($ilOpen as $ilI)
                <form method="POST" action="{{ route('incidents.link', $ilI->id) }}"
                      style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px">
                    @csrf
                    <input type="hidden" name="kind" value="{{ $ilKind }}">
                    <input type="hidden" name="summary" value="{{ mb_substr($ilSummary, 0, 300) }}">
                    @if (! empty($ilRef))<input type="hidden" name="ref" value="{{ mb_substr((string) $ilRef, 0, 120) }}">@endif
                    @if (! empty($ilModule))<input type="hidden" name="module" value="{{ $ilModule }}">@endif
                    @if (! empty($ilRecord))<input type="hidden" name="record_id" value="{{ $ilRecord }}">@endif
                    <button class="btn ghost sm">🔗 اربط</button>
                    <span>{{ \Illuminate\Support\Str::limit($ilI->title, 70) }}</span>
                    @if ($ilI->severity)<span class="bdg {{ in_array($ilI->severity, ['حرج', 'عالي'], true) ? 'bad' : 'wn' }}">{{ $ilI->severity }}</span>@endif
                    @if ($ilI->status)<span class="bdg g">{{ $ilI->status }}</span>@endif
                </form>
            @endforeach
            <div class="sub">أحدثُ ثماني حوادثَ مفتوحةٍ ضمن نطاقك — والحوادثُ المغلقة لا تُربط.</div>
        @endif
    </div>
@endif
