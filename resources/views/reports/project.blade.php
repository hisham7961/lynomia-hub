@extends('layouts.app')
@section('title', 'تقارير المشروع — ' . $project->name)
@section('content')
<div class="hero">
    <div>
        <h2>📁 {{ $project->name }} <span class="sub">— تقارير المشروع</span></h2>
        <div class="sub">ملخّصُ الذكاء الاصطناعيّ للتقارير اليوميّة، ثمّ أحدثُ التقارير نفسِها.</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn ghost sm" href="{{ route('reports.projects') }}">📁 كلُّ المشاريع</a>
        <a class="btn ghost sm" href="{{ route('m.show', ['projects', $project->id]) }}">🗂️ صفحة المشروع</a>
    </div>
</div>

<div class="card">
    <h3 class="cardtitle">🤖 ملخّص التقارير (ذكاء اصطناعي)
        @if ($canRefresh && ! $masked)
            <form method="post" action="{{ route('reports.projects.refresh', $project->id) }}" style="display:inline">
                @csrf
                <button class="btn ghost xs" title="يُطوى فيه ما قُدِّم بعد آخر ملخّص — ولا نداءَ إن لم يكن جديد">🔄 تحديث الآن</button>
            </form>
        @endif
    </h3>
    @if ($why && ! $masked)<div class="sub" style="margin-bottom:6px"><span class="bdg wn">متوقّف</span> {{ $why }}</div>@endif
    @include('reports._digest_body', ['digest' => $digest, 'masked' => $masked, 'full' => true])
</div>

<div class="card">
    <h3 class="cardtitle">📋 أحدث التقارير <span class="bdg g">{{ $reports->count() }}</span></h3>
    @forelse ($reports as $w)
        @php $rs = $w->review_status ?: 'pending_review'; @endphp
        <div style="margin:8px 0;border-inline-start:2px solid var(--line,#eee);padding-inline-start:10px">
            <span class="mono">{{ substr((string) $w->work_date, 0, 10) }}</span> · <b>{{ $authors[$w->created_by] ?? '—' }}</b>
            @if ($see['hours'] && $w->hours !== null)<span class="sub">· {{ number_format((float) $w->hours, 1) }} ساعة</span>@endif
            @if ($see['progress'] && $w->progress !== null)<span class="bdg g">{{ (float) $w->progress }}٪</span>@endif
            <span class="bdg {{ $rs==='accepted'?'ok':($rs==='needs_revision'?'wn':'') }}">{{ ['pending_review'=>'بانتظار','accepted'=>'مقبول','needs_revision'=>'تنقيح'][$rs] ?? 'بانتظار' }}</span>
            @if ($see['done'])<div>✅ {{ \Illuminate\Support\Str::limit((string) $w->done, 300) }}</div>@endif
            @if ($see['doing'] && trim((string) $w->doing) !== '')<div class="sub">⏳ {{ \Illuminate\Support\Str::limit((string) $w->doing, 200) }}</div>@endif
            @if ($see['problems'] && trim((string) $w->problems) !== '')<div class="sub" style="color:var(--bad,#c0392b)">🚧 {{ \Illuminate\Support\Str::limit((string) $w->problems, 200) }}</div>@endif
            @if ($see['needs'] && trim((string) $w->needs) !== '')<div class="sub">🙋 {{ \Illuminate\Support\Str::limit((string) $w->needs, 200) }}</div>@endif
            @if ($see['next'] && trim((string) $w->next) !== '')<div class="sub">➡️ {{ \Illuminate\Support\Str::limit((string) $w->next, 200) }}</div>@endif
        </div>
    @empty
        @include('partials.empty', ['text' => 'لا تقاريرَ لهذا المشروع في نطاقك', 'icon' => '📋'])
    @endforelse
</div>
@endsection
