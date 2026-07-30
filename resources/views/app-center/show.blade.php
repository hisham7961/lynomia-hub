@extends('layouts.app')
@section('title', 'مركز التطبيق — ' . $app->name)
@section('content')
<div class="hero">
    <div>
        <h2>📱 {{ $app->name }}
            @if ($app->status)<span class="bdg {{ hub_tone($app->status) }}">{{ $app->status }}</span>@endif
        </h2>
        <div class="sub">
            {{ $app->platform }}{{ $app->ver ? ' · النسخة ' . $app->ver : '' }}{{ $app->build ? ' (' . $app->build . ')' : '' }}
            {{ $app->last_up ? ' · آخر تحديث ' . substr($app->last_up, 0, 10) : '' }}
            @if ($project) · مشروع: <a href="{{ route('m.show', ['projects', $project->id]) }}">{{ $project->name }}</a>@endif
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_can(auth()->user(), 'apps', 'e'))<a class="btn ghost sm" href="{{ route('m.edit', ['apps', $app->id]) }}" onclick="return Hub.modal(this.href)">✏️ تعديل</a>@endif
        <a class="btn ghost sm" href="{{ route('m.show', ['apps', $app->id]) }}">📄 كل الحقول</a>
    </div>
</div>

{{-- نسبة الإنجاز الحية --}}
<div class="card">
    <h3 style="margin-bottom:10px">🎯 نسبة الإنجاز
        @if ($progress && $progress['pct'] !== null)<b style="font-size:22px;margin-inline-start:6px">{{ $progress['pct'] }}٪</b>@endif
    </h3>
    @if ($progress && $progress['pct'] !== null)
        <div class="pbar big"><span style="width:{{ $progress['pct'] }}%"></span></div>
        <div class="crow" style="margin-top:10px">
            @if ($progress['feats']['pct'] !== null)<span class="chip">📋 خطة العمل: <b>{{ $progress['feats']['pct'] }}٪</b> ({{ $progress['feats']['n'] }} بند × وزنه)</span>@endif
            @if ($progress['tasks']['pct'] !== null)<span class="chip">✅ المهام: <b>{{ $progress['tasks']['pct'] }}٪</b> ({{ $progress['tasks']['done'] }}/{{ $progress['tasks']['total'] }} منجزة)</span>@endif
            @if ($progress['tests']['pct'] !== null)<span class="chip">🧪 الاختبارات: <b>{{ $progress['tests']['pct'] }}٪</b> ({{ $progress['tests']['pass'] }}/{{ $progress['tests']['n'] }} ناجح)</span>@endif
        </div>
        <div class="sub" style="margin-top:6px">النسبة = خطة العمل ٥٠٪ + المهام ٣٠٪ + الاختبارات ٢٠٪ — تتحرك تلقائياً مع كل حفظ</div>
    @else
        <div class="sub">لا مشروع مرتبط أو لا بنود/مهام بعد — أضف بنوداً في «خطة العمل والمزايا» بمشروع التطبيق لتبدأ النسبة بالتحرك</div>
    @endif
</div>

{{-- المتاجر والمراجعات والروابط --}}
<div class="card">
    <h3 style="margin-bottom:8px">🏪 المتاجر والروابط</h3>
    <div class="crow">
        @if ($app->apple_rev && $app->apple_rev !== '—')<span class="chip">🍎 مراجعة آبل: <span class="bdg {{ hub_tone($app->apple_rev) }}">{{ $app->apple_rev }}</span></span>@endif
        @if ($app->google_rev && $app->google_rev !== '—')<span class="chip">🤖 مراجعة جوجل: <span class="bdg {{ hub_tone($app->google_rev) }}">{{ $app->google_rev }}</span></span>@endif
        @foreach (['play' => '▶ Google Play', 'appstore' => '🍏 App Store', 'huawei' => '📕 AppGallery', 'admin_url' => '🛠 لوحة الإدارة', 'test_url' => '🧪 نسخة الاختبار', 'git' => '🌿 المستودع', 'firebase' => '🔥 Firebase'] as $col => $label)
            @if ($app->{$col})<a class="btn ghost xs" href="{{ $app->{$col} }}" target="_blank" rel="noopener">{{ $label }}</a>@endif
        @endforeach
    </div>
</div>

<div class="kids">
    {{-- خط الإصدارات الزمني --}}
    <div class="card kid wide">
        <h3>🚀 الإصدارات والتحديثات <span class="bdg g">{{ $releases->count() }}</span>
            @if (hub_can(auth()->user(), 'code', 'a'))
                <a class="btn ghost xs" style="margin-inline-start:auto" href="{{ route('m.create', 'code') }}" onclick="return Hub.modal(this.href)">＋ إصدار جديد</a>
            @endif
        </h3>
        @forelse ($releases as $rel)
            <div class="tl">
                <div class="tlv">{{ $rel->ver ?: '—' }}</div>
                <div class="tlb">
                    <div class="chead">
                        @if ($rel->type)<span class="bdg g">{{ $rel->type }}</span>@endif
                        @if ($rel->status)<span class="bdg {{ hub_tone($rel->status) }}">{{ $rel->status }}</span>@endif
                        <span class="sub">{{ $rel->date ? substr($rel->date, 0, 10) : '' }}</span>
                        <span class="spacer"></span>
                        @if (hub_can(auth()->user(), 'code', 'v'))<a class="sub" href="{{ route('m.show', ['code', $rel->id]) }}">فتح ←</a>@endif
                    </div>
                    @php $tg = is_array($rel->tags ?? null) ? $rel->tags : (json_decode($rel->tags ?? '[]', true) ?: []); @endphp
                    @if ($tg)<div class="crow" style="margin-top:4px">@foreach ($tg as $t)<span class="chip">{{ $t }}</span>@endforeach</div>@endif
                    @if ($rel->notes)<div class="cbody sub">{{ \Illuminate\Support\Str::limit($rel->notes, 220) }}</div>@endif
                </div>
            </div>
        @empty
            <div class="sub" style="padding:14px;text-align:center">لا إصدارات مسجلة — سجّل أول تحديث بمزاياه ورقمه وحالة رفعه</div>
        @endforelse
    </div>

    {{-- بنود الخطة --}}
    @if ($feats->count())
    <div class="card kid">
        <h3>📋 أثقل بنود الخطة</h3>
        <table class="mini">
            @foreach ($feats as $f)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit($f->title, 30) }}
                        <div class="pbar sm"><span style="width:{{ min(100, max(0, (int) ($f->progress ?? 0))) }}%"></span></div>
                        <div class="sub">{{ $f->type }} · وزن {{ $f->weight ?: 1 }}{{ $f->test && $f->test !== '—' ? ' · اختبار: ' . $f->test : '' }}</div></td>
                    <td style="width:1%"><b>{{ (int) ($f->progress ?? 0) }}٪</b></td>
                </tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- الأعطال والتذاكر --}}
    <div class="card kid">
        <h3>🐞 أعطال مفتوحة <span class="bdg {{ $issuesN ? 'bad' : 'ok' }}">{{ $issuesN }}</span></h3>
        <table class="mini">
            @forelse ($issues as $i)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'issues', 'v'))<a href="{{ route('m.show', ['issues', $i->id]) }}">{{ \Illuminate\Support\Str::limit($i->title, 34) }}</a>@else {{ \Illuminate\Support\Str::limit($i->title, 34) }} @endif
                        @if ($i->severity)<div class="sub">{{ $i->severity }}</div>@endif</td>
                    <td style="width:1%">@if ($i->status)<span class="bdg {{ hub_tone($i->status) }}">{{ $i->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا أعطال مفتوحة 🎉</td></tr>
            @endforelse
        </table>
    </div>

    <div class="card kid">
        <h3>🎫 تذاكر مفتوحة <span class="bdg {{ $ticketsN ? 'wn' : 'ok' }}">{{ $ticketsN }}</span></h3>
        <table class="mini">
            @forelse ($tickets as $t)
                <tr>
                    <td>@if (hub_can(auth()->user(), 'tickets', 'v'))<a href="{{ route('m.show', ['tickets', $t->id]) }}">{{ \Illuminate\Support\Str::limit($t->subject, 34) }}</a>@else {{ \Illuminate\Support\Str::limit($t->subject, 34) }} @endif</td>
                    <td style="width:1%">@if ($t->status)<span class="bdg {{ hub_tone($t->status) }}">{{ $t->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا تذاكر مفتوحة 🎉</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
