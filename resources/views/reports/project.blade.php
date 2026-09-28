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

@php $uPU = \App\Support\Ai\Understanding\ProjectUnderstanding::class; @endphp
@if ($understanding || ($canUnderstand && $uPU::enabled()))
    {{-- الطبقةُ الأولى: ملفُّ فهم المشروع (docs/ai-hub/47 §العمود ج) — يتغيّر ببطء، وأقسامُه نصوصٌ مهرَّبة --}}
    @php $uSec = $understanding ? (array) json_decode((string) $understanding->sections, true) : []; @endphp
    <div class="card" data-understanding>
        <h3 class="cardtitle">🧭 فهم المشروع <span class="bdg i">🤖 ذكاء اصطناعي</span>
            @if ($canUnderstand)
                <form method="post" action="{{ route('reports.projects.understand', $project->id) }}" style="display:inline">@csrf
                    <button class="btn ghost xs" title="يُعاد البناءُ من كلِّ المصادر: التقارير والمهام والملفّات والموقع">🔄 تحديث الفهم</button>
                </form>
            @endif
        </h3>
        @if (! $understanding)
            <div class="sub">لم يُبنَ بعد — يُبنى في الجولة اليوميّة، أو بزرّ «تحديث الفهم».</div>
        @else
            @if ($understanding->status !== 'ok')<div class="sub"><span class="bdg wn">آخرُ محاولةٍ تعذّرت</span> {{ $understanding->error_code }}</div>@endif
            @foreach ($uPU::TEXTS as $k => $label)
                @if (! empty($uSec[$k]))<p><b>{{ $label }}:</b> {{ $uSec[$k] }}</p>@endif
            @endforeach
            @foreach ($uPU::LISTS as $k => $label)
                @if (! empty($uSec[$k]))
                    <div><b>{{ $label }}</b><ul>@foreach ($uSec[$k] as $item)<li>{{ $item }}</li>@endforeach</ul></div>
                @endif
            @endforeach
            <div class="sub">بُني {{ $understanding->generated_at }} @if ($understanding->uses_docs) · يشمل وثائقَ المشروع @endif</div>
        @endif
    </div>
@endif

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

@if ($understanding && ($uSug = $uPU::openSuggestions($understanding)) !== [])
    {{-- الطبقةُ الثالثة: اقتراحاتٌ تحت الملخّص — تتحوّل مهمّةً (مسودةٌ يحفظها المستخدم) أو تُتجاهَل بسبب --}}
    <div class="card" data-suggestions>
        <h3 class="cardtitle">💡 اقتراحات <span class="bdg g">{{ count($uSug) }}</span></h3>
        @foreach ($uSug as $sg)
            <div style="margin:8px 0;border-inline-start:3px solid var(--i,#6366f1);padding-inline-start:10px">
                <span class="bdg">{{ $uPU::SUGGESTION_TYPES[$sg['type']] ?? $sg['type'] }}</span> {{ $sg['text'] }}
                @if (! empty($sg['basis']))<span class="sub">— من: {{ collect($sg['basis'])->map(fn ($b) => $uPU::BASES[$b] ?? $b)->implode('، ') }}</span>@endif
                <div style="display:flex;gap:6px;margin-top:4px;flex-wrap:wrap">
                    @if (hub_can(auth()->user(), 'tasks', 'a'))
                        <form method="post" action="{{ route('reports.projects.suggestion', [$project->id, $sg['id']]) }}" class="inline">@csrf
                            <input type="hidden" name="action" value="task"><button class="btn p xs">➕ حوّلها مهمّة</button>
                        </form>
                    @endif
                    <form method="post" action="{{ route('reports.projects.suggestion', [$project->id, $sg['id']]) }}" class="inline" style="display:flex;gap:4px">@csrf
                        <input type="hidden" name="action" value="dismiss">
                        <input class="inp" name="reason" placeholder="لماذا؟ (اختياريّ)" style="max-width:180px">
                        <button class="btn ghost xs">✗ تجاهل</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif

@if (! empty($sources) && (! empty($sources['files']) || ! empty($sources['site'])))
    {{-- مصادرُ الفهم (docs/ai-hub/47 §العمود ج): ما قرأه النظامُ من ملفّات المشروع وموقعه — أسماءٌ وحالاتٌ لا نصوص،
         والملفُّ يُسمّى لمن يرى وحدتَه وحدَه --}}
    @php
        $srcLabels = ['ok' => 'قُرئ', 'pending' => 'بانتظار الجولة', 'empty' => 'بلا نصّ', 'scanned' => 'ممسوحٌ ضوئياً',
            'no_reader' => 'لا قارئ PDF', 'too_large' => 'أكبر من الحدّ', 'unsupported' => 'صيغةٌ لا تُقرأ', 'failed' => 'تعذّرت القراءة'];
        $srcFiles = collect($sources['files'])->filter(fn ($f) => hub_can(auth()->user(), $f['module'], 'v'));
    @endphp
    <div class="card">
        <h3 class="cardtitle">📎 مصادر فهم المشروع</h3>
        @if ($srcFiles->isNotEmpty())
            <div class="tblwrap"><table class="tbl">
                <thead><tr><th>الملف</th><th>الحالة</th><th>حروف</th></tr></thead>
                <tbody>
                @foreach ($srcFiles as $f)
                    <tr><td>{{ $f['name'] }}</td>
                        <td><span class="bdg {{ $f['status'] === 'ok' ? 'ok' : ($f['status'] === 'pending' ? '' : 'wn') }}">{{ $srcLabels[$f['status']] ?? $f['status'] }}</span></td>
                        <td class="mono">{{ number_format($f['chars']) }}</td></tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        @if ($site = $sources['site'])
            <div style="margin-top:8px">
                🌐 <bdi class="mono ltr">{{ $site['url'] }}</bdi>
                <span class="bdg {{ $site['status'] === 'ok' ? 'ok' : 'wn' }}">{{ ['ok' => 'قُرئ', 'failed' => 'تعذّر', 'blocked' => 'robots.txt يمنع'][$site['status']] ?? $site['status'] }}</span>
                @if ($site['changed'])<span class="bdg i">تغيّر منذ الزيارة السابقة</span>@endif
                <span class="sub">{{ count($site['pages']) }} صفحة · {{ $site['fetched_at'] }}</span>
                @if (! empty($site['meta']['title']))<div class="sub">{{ $site['meta']['title'] }} — {{ $site['meta']['description'] ?? '' }}</div>@endif
                @if (! empty($site['meta']['broken']))
                    <div class="sub" style="color:var(--bad,#c0392b)">صفحاتٌ معطوبة:
                        @foreach ($site['meta']['broken'] as $b)<bdi class="mono ltr">{{ $b['url'] }} ({{ $b['status'] }})</bdi> @endforeach</div>
                @endif
            </div>
        @endif
    </div>
@endif

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
