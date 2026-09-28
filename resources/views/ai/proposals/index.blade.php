@extends('layouts.app')
@section('title', 'اقتراحات الذكاء')
@section('content')
<div class="hero">
    <div>
        <h2>💡 اقتراحات الذكاء <span class="bdg i">🤖 ذكاء اصطناعي</span></h2>
        <div class="sub">تغييراتٌ يقترحها الذكاءُ على سجلّاتٍ تملك تعديلها — تقدّمُ مهمةٍ أو مشروع، حالةٌ، أولويةُ طلب —
            كلٌّ منها بسببه ودليلٍ مقتبسٍ تحقّق النظامُ من وجوده في مصدره. لا يتغيّر شيءٌ حتى تعتمده أنت.</div>
    </div>
</div>

@if (! $enabled)
    <div class="card"><span class="bdg wn">متوقّف</span> <span class="sub">طابورُ الاقتراحات مطفأٌ من الإعدادات (ai.proposals).</span></div>
@endif

@forelse ($items as $p)
    @include('ai.proposals._item', ['p' => $p, 'ev' => $evidence[$p->id] ?? [], 'title' => $titles[$p->module . ':' . $p->record_id] ?? null])
@empty
    <div class="card">@include('partials.empty', ['text' => 'لا اقتراحاتٍ تنتظر قرارك الآن.', 'icon' => '💡'])</div>
@endforelse

@if ($accuracy)
    <div class="card">
        <h3>دقّةُ الاقتراحات (آخر {{ \App\Support\Ai\Proposals\ProposalService::ACCURACY_WINDOW_DAYS }} يوماً)</h3>
        <div class="sub">نوعٌ يُرفض {{ (int) (\App\Support\Ai\Proposals\ProposalService::DISABLE_RATIO * 100) }}٪ من قراراته أو أكثر
            (بعد {{ \App\Support\Ai\Proposals\ProposalService::DISABLE_MIN_DECISIONS }} قراراتٍ على الأقل) يتوقّف عن الاقتراح آلياً.</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>النوع</th><th>اعتُمد</th><th>رُفض</th><th>الحالة</th></tr></thead>
            <tbody>
            @foreach ($accuracy as $kind => $a)
                <tr><td>{{ \App\Support\Ai\Proposals\ProposalService::label($kind) }}</td><td>{{ $a['applied'] }}</td><td>{{ $a['rejected'] }}</td>
                    <td>@if ($a['disabled'])<span class="bdg bad">موقوفٌ آلياً</span>@else<span class="bdg ok">يعمل</span>@endif</td></tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
@endsection
