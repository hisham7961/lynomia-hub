@extends('layouts.app')
@section('title', 'تقارير للمراجعة')
@section('content')
<div class="hero">
    <div>
        <h2>📥 تقارير للمراجعة</h2>
        <div class="sub">راجع ما كتبه فريقك — اقبل أو اطلب تنقيحاً. القبولُ لا يمسّ الساعاتِ ولا يعيد
            عدَّها (§73)، والتنقيحُ يُشعر الموظفَ بملاحظتك.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.index') }}">📊 مركز التقارير</a>
    </div>
</div>

<div class="card" style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn {{ $status==='pending'?'':'ghost' }} sm" href="{{ route('reports.review', ['status'=>'pending','scope'=>request('scope')]) }}">بانتظار المراجعة</a>
    <a class="btn {{ $status==='needs_revision'?'':'ghost' }} sm" href="{{ route('reports.review', ['status'=>'needs_revision','scope'=>request('scope')]) }}">يحتاج تنقيحاً</a>
    <a class="btn {{ $status==='accepted'?'':'ghost' }} sm" href="{{ route('reports.review', ['status'=>'accepted','scope'=>request('scope')]) }}">مقبول</a>
    <span style="flex:1"></span>
    {{-- نطاق الطابور (الجولة 1 · F7): مديرُ المشروع يفتح على «مشاريعي» لا على ضجيج النطاق كلّه --}}
    <a class="btn {{ ($mineOnly ?? false)?'':'ghost' }} sm" href="{{ route('reports.review', ['status'=>$status,'scope'=>'mine']) }}">مشاريعي</a>
    <a class="btn {{ ($mineOnly ?? false)?'ghost':'' }} sm" href="{{ route('reports.review', ['status'=>$status,'scope'=>'all']) }}">كل نطاقي</a>
</div>

<div class="card">
    <div class="tblwrap"><table class="tbl">
        <thead><tr>
            <th>الموظف</th><th>اليوم</th><th>المشروع</th><th>المهمّة</th>
            <th>ساعات</th><th>تقدّم</th><th>مقتطف</th><th>قُدِّم</th><th></th>
        </tr></thead>
        <tbody>
        @forelse ($items as $w)
            <tr>
                <td><b>{{ $names[$w->created_by] ?? '—' }}</b></td>
                <td class="mono">{{ optional($w->work_date)->format('Y-m-d') }}</td>
                <td class="sub">{{ $w->project?->name ?: 'داخليّ' }}</td>
                <td class="sub">{{ $w->task?->title ?: '—' }}</td>
                <td class="mono">{{ $w->hours ? number_format((float)$w->hours,1) : '—' }}</td>
                <td class="mono">{{ $w->progress !== null ? (float)$w->progress.'٪' : '—' }}</td>
                <td class="sub">{{ \Illuminate\Support\Str::limit($w->done, 48) }}</td>
                <td class="sub mono">{{ optional($w->submitted_at)->format('m-d H:i') }}</td>
                <td style="white-space:nowrap">
                    @php
                        $emp = \App\Support\ReportReview::employeeOf($w);
                        $canAct = \App\Support\ReportReview::canReview(auth()->user(), $w);
                        $isPending = $w->review_status === null || $w->review_status === \App\Support\ReportReview::PENDING;
                    @endphp
                    {{-- القرارُ من الطابور نفسِه (الجولة 1 · F7): مديرُ المشروع بلا hr:v كان
                         بلا أيّ سبيلِ فعلٍ — الرابطُ الوحيدُ «مراجعة ↗» خلفَ بوّابةِ HR فيعطيه 403 --}}
                    @if ($canAct && $isPending)
                        <form method="post" action="{{ route('reports.review.act', $w->id) }}" style="display:inline-flex;gap:4px">
                            @csrf
                            <button class="btn xs" name="action" value="accept">✅ قبول</button>
                            <button class="btn ghost xs" name="action" value="needs_revision"
                                    onclick="var f=prompt('ما المطلوب تحسينُه؟ (يصل الموظفَ نصاً)');if(!f)return false;this.form.feedback.value=f;">✏️ تنقيح</button>
                            <input type="hidden" name="feedback" value="">
                        </form>
                    @endif
                    @if ($emp && hub_can(auth()->user(), 'hr', 'v'))
                        <a class="btn ghost xs" href="{{ route('reports.day', ['emp'=>$emp->id,'date'=>optional($w->work_date)->format('Y-m-d')]) }}">اليوم كاملاً ↗</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="9" class="sub">لا تقارير في هذه الحالة ضمن نطاقك.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    <div style="margin-top:8px">{{ $items->links() }}</div>
</div>
@endsection
