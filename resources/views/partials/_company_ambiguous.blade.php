{{--
    **انتماءٌ ملتبسٌ يُعلَن لا يُخفى** (L2-09 · المراجعةُ الشاملة · الطبقة ٢).

    عميلٌ تخدمه أكثرُ من شركةٍ من المجموعة لا يسعُه عمودُ `company_id` المفرد.
    والاشتقاقُ الآليّ يُحسم ما لا يلتبس وحدَه (أربعةٌ من ستّةٍ في قاعدةِ المحاكاة)،
    أمّا الملتبسُ **فيبقى بلا انتماءٍ فيُقرأ للجميع** — وهذا السطرُ يقول لماذا.

    والبديلُ الذي رُفض: أن يُحسَم بقرعةٍ (أوّلِ مشروعٍ مثلاً) فيُثبِت نصفَ
    الحقيقةِ ويُخفي نصفَها، **ويحجب العميلَ عن الشركةِ الأخرى التي تخدمه فعلاً**.
    فالإعلانُ أصدق، والحسمُ قرارُ مالكٍ لا استنتاجُ محرّك.
--}}
@php
    $ambCol = hub_company_col('clients');
    $ambCos = ($ambCol && blank($row->{$ambCol} ?? null))
        ? \Illuminate\Support\Facades\DB::table('projects')->whereNull('deleted_at')
            ->where('client_id', $row->id)->whereNotNull('company_id')
            ->distinct()->pluck('company_id')->all()
        : [];
@endphp
@if (count($ambCos) > 1)
    @php $ambNames = hub_ref_labels('companies', $ambCos); @endphp
    <div class="card wn">
        <h3 class="cardtitle">🏳️ انتماءُ هذا العميلِ غيرُ محسوم</h3>
        <p class="sub" style="margin:0 0 8px">
            تخدمه <b>{{ count($ambCos) }}</b> من شركات المجموعة —
            {{ implode(' و', array_map(fn ($id) => $ambNames[$id] ?? '—', $ambCos)) }} —
            فلا تسعُه خانةُ شركةٍ واحدة. ولأنّه لا يُعلن انتماءً، <b>يظهر لموظّفي
            الشركات كلِّها</b> بدل أن يُحجَب عن إحداها وهي تخدمه فعلاً.
        </p>
        @if (hub_can(auth()->user(), 'clients', 'e'))
            <a class="btn ghost sm" href="{{ route('m.edit', ['clients', $row->id]) }}">
                ✍️ احسِم الشركةَ يدويّاً</a>
            <span class="sub" style="margin-inline-start:8px">
                وحين تُحسَم، يُحجَب عمّن سواها.</span>
        @else
            <span class="sub">حسمُ الانتماءِ لمن يملك تحريرَ العملاء.</span>
        @endif
    </div>
@endif
