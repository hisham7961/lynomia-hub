{{-- بطاقة الميدان لمقدم الرعاية: أين يعمل، ومن يغطيه — قبل جدول الحقول --}}
@php
    $hcFacs = $row->facilities();
    $hcTerr = $row->territory_id ? \App\Models\Territory::whereNull('deleted_at')->find($row->territory_id) : null;
    $hcReps = $hcTerr
        ? hub_scope(\App\Models\TerritoryAssignment::whereNull('deleted_at')
            ->where('territory_id', $hcTerr->id)->where('status', 'ساري'), 'terrassigns')
            ->orderBy('date_start')->orderBy('id')->get()
        : collect();
    $hcRepNames = hub_ref_labels('hr', $hcReps->pluck('emp_id')->all());
@endphp
<div class="card">
    <h3 class="cardtitle">🩺 بطاقة الميدان</h3>
    <div class="crow">
        @if ($hcTerr)
            <a class="chip" href="{{ route('m.show', ['territories', $hcTerr->id]) }}">🗺️ {{ $hcTerr->name }}</a>
        @else
            <span class="chip">🗺️ بلا منطقة — أسندها ليدخل خطط الزيارات</span>
        @endif
        @forelse ($hcFacs as $f)
            <a class="chip" href="{{ route('m.show', ['facilities', $f->id]) }}">🏥 {{ $f->name }}</a>
        @empty
            <span class="sub">لا منشآت مربوطة — حقل «منشآت العمل» يقبل أكثر من واحدة.</span>
        @endforelse
    </div>
    @if ($hcReps->isNotEmpty())
        <div class="sub" style="margin-top:6px">التغطية السارية:
            @foreach ($hcReps as $a)
                <b>{{ $hcRepNames[$a->emp_id] ?? '؟' }}</b>{{ $a->role ? ' (' . $a->role . ')' : '' }}{{ $loop->last ? '' : ' · ' }}
            @endforeach
        </div>
    @endif
    {{-- قاعدةُ المنتج معلنةٌ في مكان قراءتها: هذا دليلُ معرفةٍ مهنيّ لا طرفُ بيع --}}
    <div class="sub" style="margin-top:6px">سجلٌّ مهنيّ للتخطيط والزيارات فقط — لا يُربط بمبيعاتٍ ولا عمولاتٍ ولا أكواد إحالة.</div>
</div>
