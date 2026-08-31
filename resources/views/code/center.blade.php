@extends('layouts.app')
@section('title', 'مركز الكود المصدري')
@section('content')
@php
    // اصطلاحُ المستودع: كلُّ قارئٍ جديد يمرّ بـhub_field_mode — الدورُ الذي
    // أُخفي عنه حقلٌ في وحدة code (من مصفوفة الأدوار) لا يراه هنا أيضاً،
    // في البطاقات والإحصاءات والنموذج سواء. صفحةُ الوحدة تحترمه أصلاً،
    // والمركزُ واجهةٌ ثانية للبيانات نفسها لا باباً خلفياً عليها.
    $cHide = collect(hub_mod('code')['fields'] ?? [])->pluck('key')
        ->filter(fn ($k) => hub_field_mode(auth()->user(), 'code', (string) $k) === 'hide')
        ->flip()->all();
@endphp
<div class="hero">
    <div>
        <h2>🌿 مركز الكود المصدري</h2>
        <div class="sub">
            الإصداراتُ كما تُقرأ: الأحدثُ متصدّراً بوسمه، وسجلُّ تغييراتٍ منسّق، وحِزَمٌ تُنزَّل — لا صفوفَ جدول.
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @if ($repo && ! isset($cHide['repo']))
            <a class="btn ghost sm" href="{{ hub_safe_url($repo) }}" target="_blank" rel="noopener">🌿 المستودع ↗</a>
        @endif
        <a class="btn ghost sm" href="{{ route('m.index', 'code') }}">📋 الجدول الكامل</a>
        @if (hub_can(auth()->user(), 'code', 'a'))
            <a class="btn p sm" href="#newrel">＋ إصدار جديد</a>
        @endif
    </div>
</div>

<div class="cards">
    <div class="stat"><span class="ico">🏷️</span><b>{{ number_format($cadence['n']) }}</b><span>إصدار مسجَّل</span></div>
    <div class="stat"><span class="ico">🕐</span>
        <b>{{ $cadence['age'] === null ? '—' : number_format($cadence['age']) }}</b>
        <span>يوماً منذ آخر إصدار</span></div>
    <div class="stat"><span class="ico">📅</span>
        <b>{{ $cadence['avg'] === null ? '—' : number_format($cadence['avg']) }}</b>
        <span>متوسط الأيام بين إصدارين</span></div>
    @unless (isset($cHide['branch']))
        <div class="stat"><span class="ico">🌿</span><b>{{ count($branches) ?: '—' }}</b><span>فرعاً مستعملاً</span></div>
    @endunless
</div>

{{-- مرشّحات: تطبيقٌ أو مشروع — كما تُبدَّل المستودعات في GitHub --}}
<div class="card">
    <form method="GET" class="fg">
        <div class="fld">
            <label for="c-app">التطبيق</label>
            <select class="inp" id="c-app" name="app">
                <option value="">كل التطبيقات</option>
                @foreach ($apps as $id => $name)
                    <option value="{{ $id }}" @selected($appId === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld">
            <label for="c-proj">المشروع</label>
            <select class="inp" id="c-proj" name="project">
                <option value="">كل المشاريع</option>
                @foreach ($projects as $id => $name)
                    <option value="{{ $id }}" @selected($projId === (string) $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld">
            <label>&nbsp;</label>
            <div style="display:flex;gap:8px">
                <button class="btn p">تصفية</button>
                <a class="btn ghost" href="{{ route('code.center') }}">مسح</a>
            </div>
        </div>
    </form>
    @php
        $cBranches = isset($cHide['branch']) ? [] : $branches;
        $cTags = isset($cHide['tags']) ? [] : $tags;
    @endphp
    @if ($cBranches || $cTags)
        <div class="crow" style="margin-top:8px">
            @foreach ($cBranches as $b)<span class="chip mono">🌿 {{ $b }}</span>@endforeach
            @foreach ($cTags as $t => $n)<span class="chip">{{ $t }} <b>{{ $n }}</b></span>@endforeach
        </div>
    @endif
</div>

<div class="rels">
    <div class="relmain">
        @forelse ($releases as $rel)
            <article class="rel {{ $rel['latest'] ? 'top' : '' }}" id="rel-{{ $rel['id'] }}">
                <div class="reltag">
                    <span class="tagv mono">{{ $rel['ver'] }}</span>
                    @if ($rel['latest'])<span class="bdg ok">الأحدث</span>@endif
                    @if ($rel['bump'])<span class="bdg g" title="حجم القفزة عن سابقتها">{{ $rel['bump'] }}</span>@endif
                </div>

                <div class="relbody">
                    <div class="chead">
                        @if ($rel['row']->type && ! isset($cHide['type']))<span class="bdg g">{{ $rel['row']->type }}</span>@endif
                        @if ($rel['row']->status && ! isset($cHide['status']))<span class="bdg {{ hub_tone($rel['row']->status) }}">{{ $rel['row']->status }}</span>@endif
                        @if ($rel['app'] && ! isset($cHide['appId']))<span class="chip">📱 {{ $rel['app'] }}</span>@endif
                        <span class="sub">{{ $rel['by'] }}</span>
                        @if ($rel['date'] && ! isset($cHide['date']))<span class="sub mono" title="{{ $rel['ago'] }}">{{ $rel['date'] }}</span>@endif
                        <span class="spacer"></span>
                        <a class="btn ghost xs" href="{{ route('m.show', ['code', $rel['id']]) }}">عرض</a>
                        @if (hub_can(auth()->user(), 'code', 'e'))
                            <a class="btn ghost xs" href="{{ route('m.edit', ['code', $rel['id']]) }}">تعديل</a>
                        @endif
                    </div>

                    @php
                        $rBranch = isset($cHide['branch']) ? null : $rel['row']->branch;
                        $rCommit = isset($cHide['commit']) ? null : $rel['row']->commit;
                    @endphp
                    @if ($rBranch || $rCommit)
                        <div class="sub mono ltr" style="margin-top:4px">
                            @if ($rBranch)🌿 {{ $rBranch }}@endif
                            @if ($rCommit) · ⎇ {{ \Illuminate\Support\Str::limit($rCommit, 12, '') }}@endif
                        </div>
                    @endif

                    @if ($rel['tags'] && ! isset($cHide['tags']))
                        <div class="crow" style="margin-top:6px">
                            @foreach ($rel['tags'] as $t)<span class="chip">{{ $t }}</span>@endforeach
                        </div>
                    @endif

                    @unless (isset($cHide['notes']))
                        @if (trim($rel['notes']) !== '')
                            <div class="relnotes">{!! \App\Support\CodeHub::notesHtml($rel['notes']) !!}</div>
                        @else
                            <div class="sub" style="margin-top:8px">لا سجلَّ تغييراتٍ لهذا الإصدار — وما لم يُكتب لا يُتذكَّر.</div>
                        @endif
                    @endunless

                    @if ($rel['assets'] && ! isset($cHide['file']))
                        <div class="relassets">
                            <div class="sub" style="margin-bottom:5px">📦 أصول التنزيل</div>
                            @foreach ($rel['assets'] as $as)
                                <a class="asset" href="{{ $as['url'] }}">
                                    <span class="an">{{ \Illuminate\Support\Str::limit($as['name'], 46) }}</span>
                                    <span class="sub mono">
                                        {{ $as['size'] !== null ? hub_bytes($as['size']) : '' }}
                                        @if ($as['n']) · ⬇ {{ $as['n'] }}@endif
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </article>
        @empty
            @include('partials.empty', [
                'icon' => '🌿',
                'text' => 'لا إصدارات بعد — سجّل أول إصدارٍ برقمه وسجلّ تغييراته وحزمته.',
            ])
        @endforelse
    </div>

    {{-- إصدارٌ جديد: يُكتب من الصفحة نفسها كـ«Draft a new release» --}}
    @if (hub_can(auth()->user(), 'code', 'a'))
        <aside class="relside">
            <div class="card" id="newrel">
                <h3 class="cardtitle">🏷️ إصدار جديد</h3>
                <form method="POST" action="{{ route('m.store', 'code') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="fld">
                        <label for="n-ver">رقم النسخة <b class="req">*</b></label>
                        <input class="inp mono ltr" id="n-ver" name="ver" required placeholder="v1.4.0"
                               value="{{ old('ver') }}">
                    </div>
                    <div class="fld">
                        <label for="n-proj">المشروع <b class="req">*</b></label>
                        <select class="inp" id="n-proj" name="projectId" required>
                            <option value=""></option>
                            @foreach ($projects as $id => $name)
                                <option value="{{ $id }}" @selected($projId === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fld">
                        <label for="n-app">التطبيق</label>
                        <select class="inp" id="n-app" name="appId">
                            <option value=""></option>
                            @foreach ($apps as $id => $name)
                                <option value="{{ $id }}" @selected($appId === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                    {{-- الحقولُ المخفيّة عن الدور لا تُعرض في النموذج أيضاً —
                         ومسارُ الكتابة (fill) يتجاهلها أصلاً فلا وعدَ كاذباً --}}
                    @unless (isset($cHide['type']))
                        <div class="fld">
                            <label for="n-type">نوع الإصدار</label>
                            <select class="inp" id="n-type" name="type">
                                @foreach (collect(hub_mod('code')['fields'])->firstWhere('key', 'type')['options'] as $o)
                                    <option value="{{ $o }}">{{ $o }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                    @unless (isset($cHide['status']))
                        <div class="fld">
                            <label for="n-status">الحالة</label>
                            <select class="inp" id="n-status" name="status">
                                @foreach (collect(hub_mod('code')['fields'])->firstWhere('key', 'status')['options'] as $o)
                                    <option value="{{ $o }}" @selected($o === 'مسودة')>{{ $o }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endunless
                    @unless (isset($cHide['date']))
                        <div class="fld">
                            <label for="n-date">تاريخ الإصدار</label>
                            <input class="inp" id="n-date" type="date" name="date" value="{{ now()->toDateString() }}">
                        </div>
                    @endunless
                    @unless (isset($cHide['branch']))
                        <div class="fld">
                            <label for="n-branch">الفرع</label>
                            <input class="inp mono ltr" id="n-branch" name="branch" placeholder="main">
                        </div>
                    @endunless
                    @unless (isset($cHide['notes']))
                        <div class="fld">
                            <label for="n-notes">سجل التغييرات</label>
                            <textarea class="inp" id="n-notes" name="notes" rows="6"
                                      placeholder="- أضيف كذا&#10;- أُصلح كذا&#10;**ملاحظة:** …"></textarea>
                            <span class="sub fhint">يُنسَّق بـMarkdown خفيف: قوائم، عناوين، **غامق**، `شيفرة`.</span>
                        </div>
                    @endunless
                    @unless (isset($cHide['file']))
                        <div class="fld">
                            <label for="n-file">حزمة الإصدار</label>
                            <input class="inp" id="n-file" type="file" name="file">
                            <span class="sub fhint">حتى {{ hub_upload_cap()['label'] }} — بعدّاد تقدّمٍ أثناء الرفع</span>
                        </div>
                    @endunless
                    <button class="btn p" style="margin-top:10px">🏷️ إصدار</button>
                </form>
            </div>

            @if ($cadence['last'])
                <div class="card">
                    <h3 class="cardtitle">📈 وتيرة الإصدار</h3>
                    <div class="sub" style="line-height:2">
                        آخر إصدار: <b class="mono">{{ $cadence['last'] }}</b>
                        <span class="bdg {{ $cadence['tone'] }}">منذ {{ $cadence['age'] }} يوماً</span><br>
                        @if ($cadence['avg'] !== null)
                            متوسط الفترة بين إصدارين: <b>{{ $cadence['avg'] }}</b> يوماً.
                        @endif
                        @if (($cadence['age'] ?? 0) > 180)
                            <div style="margin-top:6px">⚠️ مضى على آخر إصدارٍ أكثرُ من ستة أشهر — مشروعٌ بلا إصدارٍ يبدو متوقفاً لمن يقرأ سجلّه.</div>
                        @endif
                    </div>
                </div>
            @endif
        </aside>
    @endif
</div>

<style>
.rels{display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start}
.relmain{min-width:0;display:flex;flex-direction:column;gap:12px}
.relside{position:sticky;top:14px;display:flex;flex-direction:column;gap:12px}
.rel{display:grid;grid-template-columns:150px 1fr;gap:14px;background:var(--cd);border:1px solid var(--ln);
    border-radius:var(--r);padding:16px 18px}
.rel.top{border-color:color-mix(in srgb,var(--ok) 45%,var(--ln));box-shadow:var(--sh)}
.reltag{display:flex;flex-direction:column;gap:6px;align-items:flex-start}
.tagv{font-size:17px;font-weight:700;background:var(--pss);color:var(--pd);border-radius:10px;padding:3px 10px;
    direction:ltr}
.relbody{min-width:0}
.relnotes{margin-top:8px;line-height:1.95;font-size:13.5px}
.relnotes h4{font-size:13.5px;margin:8px 0 4px;color:var(--pd)}
.relnotes ul{margin:4px 0;padding-inline-start:20px}
.relnotes li{margin:2px 0}
.relnotes p{margin:4px 0}
.relnotes code{background:var(--bg2);border:1px solid var(--ln);border-radius:6px;padding:1px 5px;
    font-family:ui-monospace,Consolas,monospace;font-size:.9em;direction:ltr;display:inline-block}
.relassets{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.asset{display:flex;gap:10px;align-items:center;justify-content:space-between;background:var(--bg2);
    border:1px solid var(--ln);border-radius:10px;padding:7px 11px;font-size:13px}
.asset:hover{border-color:var(--p);background:color-mix(in srgb,var(--p) 5%,var(--bg2))}
.asset .an{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
@media (max-width:1000px){
    .rels{grid-template-columns:1fr}
    .relside{position:static}
    .rel{grid-template-columns:1fr;gap:8px}
}
</style>
@endsection
