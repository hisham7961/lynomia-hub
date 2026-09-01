{{-- أمرُ التغيير: مسارُه (اعتماد ← تطبيق) ومستندُه — يُعرَض فوق تفاصيل الوحدة --}}
@php
    $coStatus = $row->status ?? 'مسودة';
    $coCanE = hub_can(auth()->user(), 'changeorders', 'e');
    $coApplied = ! empty($row->applied_at);
@endphp
<div class="card">
    <h3 class="cardtitle">📋 مسارُ أمر التغيير <span class="bdg {{ hub_tone($coStatus) }}">{{ $coStatus }}</span></h3>
    <div class="crow">
        <a class="btn p sm" href="{{ route('changeorders.pdf', $row->id) }}" target="_blank" rel="noopener">📄 مستند التغيير PDF</a>
        @if (! empty($row->project_id))
            <a class="btn ghost sm" href="{{ route('m.show', ['projects', $row->project_id]) }}">🗂️ مشروعه ←</a>
        @endif
        @if (! empty($row->quote_id))
            <a class="btn ghost sm" href="{{ route('m.show', ['quotes', $row->quote_id]) }}">🧾 عرضه المصدر ←</a>
        @endif
        @if ($coCanE && ! $row->trashed())
            @if ($coStatus === 'معتمد' && ! $coApplied)
                <form method="POST" action="{{ route('changeorders.apply', $row->id) }}"
                      data-confirm="تطبيقُ أمر التغيير على المشروع؟ تتطوّر قيمتُه التعاقدية — والعرضُ المقبول لا يُمَسّ.">
                    @csrf<button class="btn p sm">✅ تطبيقٌ على المشروع</button>
                </form>
            @elseif ($coApplied)
                <span class="chip">✓ طُبِّق {{ \Illuminate\Support\Str::limit(str_replace('T', ' ', optional($row->applied_at)->toIso8601String()), 16, '') }}</span>
            @endif
        @endif
    </div>
    <div class="sub" style="margin-top:8px">مسودة ← قيد الاعتماد ← معتمد ← (تطبيقٌ) مطبَّق — التطبيقُ يمدّد خطَّ أساس المشروع، ولا يُعدّل العرضَ المقبول.</div>
</div>
