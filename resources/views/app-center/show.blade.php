@extends('layouts.app')
@section('title', 'مركز التطبيق — ' . $app->name)
@section('content')
{{-- ترويسةٌ بوجه التطبيق: أيقونتُه كما تُرى في المتجر لا سطرَ نصٍّ عنها --}}
<div class="hero">
    <div style="display:flex;gap:14px;align-items:center;min-width:0">
        @if ($app->logo_id)
            <img class="appico" src="{{ route('file.show', $app->logo_id) }}" alt="أيقونة {{ $app->name }}">
        @else
            <span class="appico ph" aria-hidden="true">📱</span>
        @endif
        <div style="min-width:0">
            <h2>{{ $app->name }}
                @if ($app->status)<span class="bdg {{ hub_tone($app->status) }}">{{ $app->status }}</span>@endif
            </h2>
            <div class="sub">
                {{ $app->platform }}{{ $app->ver ? ' · النسخة ' . $app->ver : '' }}{{ $app->build ? ' (' . $app->build . ')' : '' }}
                {{ $app->last_up ? ' · آخر تحديث ' . substr($app->last_up, 0, 10) : '' }}
                @if ($project) · مشروع: <a href="{{ route('m.show', ['projects', $project->id]) }}">{{ $project->name }}</a>@endif
            </div>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if (hub_can(auth()->user(), 'apps', 'e'))<a class="btn ghost sm" href="{{ route('m.edit', ['apps', $app->id]) }}">✏️ تعديل</a>@endif
        <a class="btn ghost sm" href="#shots">🖼️ اللقطات</a>
        <a class="btn ghost sm" href="{{ route('m.show', ['apps', $app->id]) }}">📄 كل الحقول</a>
    </div>
</div>
<style>
.appico{width:64px;height:64px;flex:none;border-radius:16px;object-fit:cover;border:1px solid var(--ln);
    background:var(--cd);box-shadow:0 4px 14px rgba(0,0,0,.12)}
.appico.ph{display:flex;align-items:center;justify-content:center;font-size:30px;background:var(--bg2)}
</style>

{{-- ما يراه المستخدمُ في المتجر: لقطاتُه ووصفُه — قبل الأرقام والإصدارات --}}
@include('partials.app_gallery')

{{-- وصفُ المتجر: نصٌّ يُقرأ بحدوده لا سطرٌ في جدول حقول --}}
<div class="card" id="storedesc">
    <h3>📝 وصف المتجر
        <span class="bdg {{ $desc['tone'] }}">{{ number_format($desc['len']) }} / {{ number_format($desc['max']) }} حرف</span>
        @if ($desc['text'])
            <button class="btn ghost xs msauto" type="button" data-copydesc
                    onclick="(function(b){var t=document.getElementById('descbody').innerText;
                        (navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject())
                        .then(function(){b.textContent='✓ نُسخ'},function(){b.textContent='انسخه يدوياً'})})(this)">
                ⧉ نسخ الوصف</button>
        @endif
    </h3>
    @if ($desc['text'])
        <div id="descbody" class="descbody">{!! nl2br(e($desc['text'])) !!}</div>
    @endif
    <div class="sub" style="margin-top:8px">
        {{ $desc['tone'] === 'ok' ? '✅ ' : '⚠️ ' }}{{ $desc['hint'] }}
        @if (hub_can(auth()->user(), 'apps', 'e'))
            <a href="{{ route('m.edit', ['apps', $app->id]) }}">تحريره ←</a>
        @endif
    </div>
</div>
<style>
.descbody{white-space:normal;line-height:2;font-size:14px;background:var(--bg2);border:1px solid var(--ln);
    border-radius:12px;padding:14px 16px;max-height:340px;overflow:auto}
</style>

{{-- جاهزية النشر: ما ينقص قبل الرفع — ودورةُ مراجعةٍ ضائعة أغلى من فحصٍ هنا --}}
<div class="card" id="ready">
    <h3>🚦 جاهزية النشر
        <span class="bdg {{ $ready['tone'] }}">{{ $ready['pct'] }}٪</span>
        <span class="sub">{{ $ready['done'] }}/{{ $ready['need'] }} من الإلزامي</span>
    </h3>
    <div class="pbar sm" style="margin-bottom:12px"><span style="width:{{ $ready['pct'] }}%"></span></div>
    <div class="rdgrid">
        @foreach ($ready['items'] as $it)
            <div class="rdrow {{ $it['ok'] ? 'on' : ($it['req'] ? 'off' : 'opt') }}">
                <span class="rdi" aria-hidden="true">{{ $it['ok'] ? '✅' : ($it['req'] ? '❌' : '➖') }}</span>
                <div style="min-width:0">
                    <b>{{ $it['label'] }}</b>
                    @if (! $it['req'])<span class="bdg g">مستحسن</span>@endif
                    <div class="sub">{{ $it['why'] }}</div>
                    @if (! $it['ok'])<div class="sub">↳ {{ $it['fix'] }}</div>@endif
                </div>
            </div>
        @endforeach
    </div>
</div>
<style>
.rdgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px}
.rdrow{display:flex;gap:10px;align-items:flex-start;background:var(--bg2);border:1px solid var(--ln);
    border-radius:12px;padding:11px 13px}
.rdrow.off{border-color:color-mix(in srgb,var(--bad) 40%,var(--ln))}
.rdrow.on{border-color:color-mix(in srgb,var(--ok) 35%,var(--ln))}
.rdrow .rdi{font-size:15px;line-height:1.6}
</style>

{{-- نسبة الإنجاز الحية --}}
<div class="card">
    <h3>🎯 نسبة الإنجاز
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

{{-- أداء المتجر: التحميلات والتقييم — أَحيٌّ التطبيق أم ميت؟ --}}
@php
    $store = hub_app_store($app);
    $sNum = fn ($v) => $v === null ? '—' : number_format((float) $v, ((float) $v == (int) $v) ? 0 : 1);
@endphp
<div class="card">
    <h3>📊 أداء المتجر <span class="sub">· آخر {{ $store['days'] }} يوماً</span></h3>
    <div class="cards" style="margin-bottom:10px">
        <div class="stat"><span class="ico">⬇️</span><b>{{ $sNum($store['downloads']) }}</b><span>التحميلات</span></div>
        <div class="stat"><span class="ico">{{ ($store['growth']['delta'] ?? 0) >= 0 ? '📈' : '📉' }}</span>
            <b class="{{ ($store['growth']['delta'] ?? 0) < 0 ? 'txt-bad' : '' }}">
                {{ $store['growth']['delta'] === null ? '—' : (($store['growth']['delta'] >= 0 ? '+' : '') . number_format($store['growth']['delta'])) }}</b>
            <span>نمو التحميلات{{ $store['growth']['pct'] !== null ? ' · ' . $store['growth']['pct'] . '٪' : '' }}</span></div>
        <div class="stat"><span class="ico">⭐</span>
            <b class="{{ $store['ratingTone'] === 'bad' ? 'txt-bad' : '' }}">{{ $store['rating'] === null ? '—' : number_format($store['rating'], 1) }}</b>
            <span>تقييم المتجر{{ $store['reviews'] !== null ? ' · ' . $sNum($store['reviews']) . ' مراجعة' : '' }}</span></div>
        <div class="stat"><span class="ico">🔌</span>
            <b class="{{ $store['feed']['tone'] === 'bad' ? 'txt-bad' : '' }}">{{ $store['feed']['label'] }}</b>
            <span>{{ $store['feed']['at'] ? 'آخر قياس ' . $store['feed']['at']->diffForHumans() : 'لا قياس مسجّل' }}</span></div>
    </div>

    @if (count($store['spark']) > 1)
        <div class="sub" style="margin-bottom:4px">منحنى التحميلات:</div>
        <div style="display:flex;align-items:flex-end;gap:3px;height:70px">
            @foreach ($store['spark'] as $s)
                <div title="{{ $s['at']->format('Y-m-d') }} — {{ number_format($s['value']) }}"
                     style="flex:1;min-width:4px;height:100%;display:flex;align-items:flex-end">
                    <span style="display:block;width:100%;border-radius:3px 3px 0 0;background:var(--p);height:{{ max(6, $s['pct']) }}%"></span>
                </div>
            @endforeach
        </div>
    @endif

    @if ($store['rating'] !== null && $store['ratingTone'] === 'bad')
        <div class="sub" style="margin-top:10px;color:var(--bad)">
            ⚠️ تقييمٌ دون ٣٫٥ يخنق التحميل في المتاجر قبل أي تسويق — عالج المراجعات أولاً.
        </div>
    @endif

    @if (! $store['has'] || $store['feed']['mode'] === 'none')
        <div class="sub" style="margin-top:10px;line-height:2">
            <b>{{ $store['feed']['label'] }}</b> — أرقام هذا التطبيق لا تصل من نفسها.
            @if ($store['auto']) («مزامنة المتجر تلقائياً» مؤشَّرة لكن لا نقاط تصل بعد.) @endif
            دع n8n (أو أي مصدر يقرأ Play Console / App Store Connect) يدفعها إلى
            <span class="mono ltr">POST /api/v1/metrics</span> بمقاييس
            <span class="mono ltr">downloads, rating, reviews</span> —
            التفصيل في <a href="{{ route('integrations.guide') }}">دليل الربط</a>.
            وحتى ذلك، كل حفظٍ للحقول يسجّل نقطةً في السلسلة من نفسه.
        </div>
    @endif
</div>

{{-- المتاجر والمراجعات والروابط --}}
<div class="card">
    <h3>🏪 المتاجر والروابط</h3>
    <div class="crow">
        @if ($app->apple_rev && $app->apple_rev !== '—')<span class="chip">🍎 مراجعة آبل: <span class="bdg {{ hub_tone($app->apple_rev) }}">{{ $app->apple_rev }}</span></span>@endif
        @if ($app->google_rev && $app->google_rev !== '—')<span class="chip">🤖 مراجعة جوجل: <span class="bdg {{ hub_tone($app->google_rev) }}">{{ $app->google_rev }}</span></span>@endif
        @foreach (['play' => '▶ Google Play', 'appstore' => '🍏 App Store', 'huawei' => '📕 AppGallery', 'admin_url' => '🛠 لوحة الإدارة', 'test_url' => '🧪 نسخة الاختبار', 'git' => '🌿 المستودع', 'firebase' => '🔥 Firebase'] as $col => $label)
            @if ($app->{$col})<a class="btn ghost xs" href="{{ hub_safe_url($app->{$col}) }}" target="_blank" rel="noopener">{{ $label }}</a>@endif
        @endforeach
    </div>
</div>

<div class="kids">
    {{-- خط الإصدارات الزمني --}}
    <div class="card kid wide">
        <h3>🚀 الإصدارات والتحديثات <span class="bdg g">{{ $releases->count() }}</span>
            @if (hub_can(auth()->user(), 'code', 'a'))
                <a class="btn ghost xs msauto" href="{{ route('m.create', 'code') }}">＋ إصدار جديد</a>
            @endif
        </h3>
        @forelse ($releases as $rel)
            <div class="reltl">
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
                    <td class="acts"><b>{{ (int) ($f->progress ?? 0) }}٪</b></td>
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
                    <td class="acts">@if ($i->status)<span class="bdg {{ hub_tone($i->status) }}">{{ $i->status }}</span>@endif</td>
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
                    <td class="acts">@if ($t->status)<span class="bdg {{ hub_tone($t->status) }}">{{ $t->status }}</span>@endif</td>
                </tr>
            @empty
                <tr><td class="sub" style="padding:12px;text-align:center">لا تذاكر مفتوحة 🎉</td></tr>
            @endforelse
        </table>
    </div>
</div>
@endsection
