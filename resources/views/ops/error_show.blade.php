@extends('layouts.app')
@section('title', 'خطأ — ' . \Illuminate\Support\Str::limit($e->message, 40))
@section('content')
@php
    $kindLabel = ['php' => 'استثناء PHP', 'api' => 'خطأ API', 'js' => 'خطأ متصفح', 'slow' => 'طلب بطيء'][$e->kind] ?? $e->kind;
    $kindTone = $e->kind === 'slow' ? 'wn' : ($e->kind === 'js' ? 'g' : 'bad');
    $taskId = ($e->meta['task_id'] ?? null);
    $lifecycle = hub_has_col('error_events', 'ignored_reason');   // (WP-3.3) قبل الترحيل تبقى الأزرار الثلاثة القديمة
@endphp
<div class="hero">
    <div>
        <nav class="crumbs" aria-label="مسار التنقل">
            <a href="{{ route('errors.index') }}">مركز الأخطاء</a><span aria-hidden="true">‹</span><b>تفاصيل الخطأ</b>
        </nav>
        <h2>🐞 {{ $kindLabel }}</h2>
        <div class="sub">
            <span class="bdg {{ $kindTone }}">{{ $kindLabel }}</span>
            @php $stLbl = \App\Support\IssueState::label($e->status); @endphp
            <span class="bdg {{ $stLbl === 'محلول' ? 'ok' : ($stLbl === 'جديد' ? 'bad' : ($stLbl === 'متجاهَل' ? 'g' : 'wn')) }}">{{ $stLbl }}</span>
            تكرّر <b>{{ number_format($e->count) }}</b> مرة ·
            أول ظهور {{ $e->first_seen?->diffForHumans() }} · آخر ظهور {{ $e->last_seen?->diffForHumans() }}
        </div>
    </div>
    <a class="btn ghost sm" href="{{ route('errors.index') }}">← القائمة</a>
</div>

{{-- ما هو: الرسالة كاملةً بلا بتر --}}
<div class="card" style="border-inline-start:4px solid var(--bad, #c0392b)">
    <h3 class="cardtitle">❓ ما هو الخطأ؟</h3>
    <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left;white-space:pre-wrap;font-size:12.5px">{{ $e->message }}</pre>
</div>

{{-- أين: الموضع والمقتطف --}}
<div class="card">
    <h3 class="cardtitle">📍 أين وقع؟</h3>
    <table class="mini" style="margin-bottom:10px">
        <tr><td class="sub" style="width:130px">الملف والسطر</td>
            <td class="mono ltr">{{ $relPath ? $relPath . ($e->line ? ':' . $e->line : '') : '— غير مسجَّل —' }}</td></tr>
        <tr><td class="sub">الرابط</td>
            <td class="mono ltr">{{ $e->method ? $e->method . ' ' : '' }}{{ $e->url ?: '— (أمر طرفية أو مجدول) —' }}</td></tr>
        <tr><td class="sub">المستخدم المتأثر</td>
            <td>{{ $e->user_id ? ($users[$e->user_id] ?? 'مستخدم محذوف') : 'زائر / نظام' }}</td></tr>
        <tr><td class="sub">معرّف الطلب</td>
            <td class="mono ltr">{{ $e->request_id ?: '—' }}</td></tr>
        @if (isset($e->category))
        <tr><td class="sub">الصنف والشدّة</td>
            <td>{{ \App\Support\ErrorTaxonomy::LABELS[$e->category] ?? ($e->category ?: '—') }} ·
                <b>{{ \App\Support\ErrorTaxonomy::LABELS[$e->severity] ?? ($e->severity ?: '—') }}</b>
                <span class="sub">({{ $e->category ?: '—' }} / {{ $e->severity ?: '—' }})</span></td></tr>
        <tr><td class="sub">الإصدار والبيئة</td>
            <td class="mono ltr">{{ $e->release ?: '—' }} · {{ $e->env ?: '—' }}</td></tr>
        <tr><td class="sub">المسار</td>
            <td class="mono ltr">{{ $e->route ?: '—' }}</td></tr>
        <tr><td class="sub">مستخدمون متأثرون</td>
            <td>{{ (int) $e->users }}@if ((int) $e->users >= 50)<span class="sub"> (٥٠ فأكثر — عدٌّ تقريبيّ)</span>@endif</td></tr>
        @endif
    </table>

    @if ($snippet)
        <div class="sub" style="margin-bottom:6px">الشيفرة عند موضع الخطأ:</div>
        <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:10px;overflow:auto;direction:ltr;text-align:left;font-size:12px">@foreach ($snippet as $s)<div style="{{ $s['hot'] ? 'background:rgba(192,57,43,.16);font-weight:700' : '' }}"><span class="sub" style="display:inline-block;width:46px">{{ $s['n'] }}</span>{{ $s['code'] }}</div>@endforeach</pre>
    @elseif ($e->file)
        <div class="sub">تعذّرت قراءة الملف لعرض المقتطف (قد يكون خارج المشروع أو غير قابل للقراءة).</div>
    @endif
</div>

{{-- أثر النداء --}}
@if ($e->trace)
    <div class="card">
        <h3 class="cardtitle">🧵 أثر النداء (Stack trace)</h3>
        <pre class="mono ltr" style="background:var(--cd);border:1px solid var(--brd);border-radius:10px;padding:12px;overflow:auto;direction:ltr;text-align:left;max-height:420px;font-size:11.5px">{{ $e->trace }}</pre>
    </div>
@endif

{{-- (WP-3.4) خطُّ الإصدار: بأيّ نسخةٍ ظهر، وبأيّها حُلّ، وبأيّها عاد --}}
<div class="card">
    <h3 class="cardtitle">🧬 خطّ الإصدار</h3>
    <table class="mini">
        <tr><td class="sub" style="width:130px">أول ظهور</td>
            <td>{{ $e->first_seen?->format('Y-m-d H:i') ?? '—' }}
                @if ($e->release ?? null) — النسخة <bdi class="mono ltr">{{ $e->release }}</bdi>@endif</td></tr>
        @if ($lifecycle && $e->resolved_at)
            <tr><td class="sub">الحل</td>
                <td>{{ \Illuminate\Support\Carbon::parse($e->resolved_at)->format('Y-m-d H:i') }}
                    @if ($e->resolved_by) — بواسطة {{ $users[$e->resolved_by] ?? 'مستخدم محذوف' }}@endif
                    @if ($e->resolved_release) — في النسخة <bdi class="mono ltr">{{ $e->resolved_release }}</bdi>@endif</td></tr>
        @endif
        @if ($lifecycle && $e->regressed_at)
            <tr><td class="sub">الانحدار</td>
                <td><span class="bdg bad">عاد بعد الحل</span>
                    {{ \Illuminate\Support\Carbon::parse($e->regressed_at)->format('Y-m-d H:i') }}
                    @if ($e->regression_release) — في النسخة <bdi class="mono ltr">{{ $e->regression_release }}</bdi>@endif</td></tr>
        @elseif ($lifecycle && ! $e->resolved_at)
            <tr><td class="sub">الحل</td><td class="sub">لم يُحل بعد</td></tr>
        @endif
    </table>
</div>

{{-- (WP-3.4) عيّنات الوقوع: «أيُّ طلبٍ سبّبه بالضبط؟» — كل صفٍّ طلبٌ حقيقيّ
     بمعرّفه (رابط صفحة الأثر) ومستخدمه ومساره ومدّته. عيّناتٌ محدودة لا أرشيف:
     يُحفظ أحدثُ ما يسمح به السقف والعدُّ الكامل في «تكرّر N مرة» أعلاه. --}}
<div class="card pad0">
    <h3 class="cardtitle" style="padding:12px 14px 0">📋 عيّنات الوقوع</h3>
    @if (is_null($occ))
        <div style="padding:0 14px 14px">@include('partials.empty', ['text' => 'جدول عيّنات الوقوع غير متاح بعد — يبدأ الحفظ بعد ترحيله', 'icon' => '📋'])</div>
    @elseif ($occ->isEmpty())
        <div style="padding:0 14px 14px">@include('partials.empty', ['text' => 'لا عيّنات محفوظة لهذا الخطأ — تُلتقط من لحظة تفعيل العيّنات فصاعداً', 'icon' => '📋'])</div>
    @else
        <div class="tblwrap"><table class="tbl">
            <thead><tr>
                @include('partials.cc.th', ['col' => 'occurred_at', 'label' => 'الوقت', 'default' => 'occurred_at'])
                <th scope="col">معرّف الطلب</th>
                <th scope="col">المستخدم</th>
                <th scope="col">المسار</th>
                @include('partials.cc.th', ['col' => 'duration_ms', 'label' => 'المدة', 'default' => 'occurred_at'])
            </tr></thead>
            <tbody>
            @foreach ($occ as $o)
                <tr>
                    <td class="sub" title="{{ $o->occurred_at }}">{{ \Illuminate\Support\Carbon::parse($o->occurred_at)->format('Y-m-d H:i:s') }}</td>
                    <td>@if ($o->request_id)
                            <a href="{{ route('system.trace', $o->request_id) }}" title="أثر الطلب عبر الطبقات"><bdi class="mono ltr">{{ $o->request_id }}</bdi></a>
                        @else — @endif</td>
                    <td>{{ $o->user_id ? ($users[$o->user_id] ?? 'مستخدم محذوف') : 'زائر / نظام' }}</td>
                    <td><bdi class="mono ltr">{{ $o->route ?: ($o->url ?: '—') }}</bdi></td>
                    <td class="sub">{{ is_null($o->duration_ms) ? '—' : number_format($o->duration_ms) . ' م‌ث' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</div>
@if (! is_null($occ) && $occ->isNotEmpty()){{ $occ->links('partials.pagination') }}@endif

{{-- آخر المتأثرين — أسماءٌ بـwhereIn على المعروض فقط، لا جلبَ لجدول المستخدمين كلّه --}}
@php $affected = collect($occ?->items() ?? [])->pluck('user_id')->filter()->unique()->values(); @endphp
@if ($affected->isNotEmpty())
    <div class="card">
        <h3 class="cardtitle">👥 آخر المتأثرين</h3>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
            @foreach ($affected as $uid)<span class="bdg g">👤 {{ $users[$uid] ?? 'مستخدم محذوف' }}</span>@endforeach
            @if (isset($e->users) && (int) $e->users > $affected->count())
                <span class="sub">— والعدّ الكلي التقريبي {{ (int) $e->users }}@if ((int) $e->users >= 50) (٥٠ فأكثر)@endif</span>
            @endif
        </div>
    </div>
@endif

{{-- (WP-3.4) الفتات الآمنة: تنقّل المستخدم المتأثر **وحدَه** قبل لحظة الوقوع،
     وقيود التدقيق **بنفس معرّف الطلب** — من الملتقط القائم، لا التقاطَ جديداً. --}}
<div class="card">
    <h3 class="cardtitle">🧭 الفتات — ماذا جرى قبل هذا الوقوع؟</h3>
    @if ($visits->isEmpty() && $reqAudits->isEmpty())
        @include('partials.empty', ['text' => 'لا فتات لهذا الخطأ: لا مستخدمَ معروفاً لوقوعه ولا قيودَ تدقيقٍ بمعرّف طلبه', 'icon' => '🧭'])
    @else
        @if ($visits->isNotEmpty())
            <div class="sub" style="margin-bottom:6px">تنقّل {{ $users[$crumbUser] ?? 'مستخدم محذوف' }} قبل الوقوع (الأحدث أولاً):</div>
            <div class="tblwrap"><table class="mini">
                @foreach ($visits as $v)
                    <tr><td class="sub" style="width:150px">{{ \Illuminate\Support\Carbon::parse($v->at)->format('Y-m-d H:i:s') }}</td>
                        <td><bdi class="mono ltr">{{ $v->path }}</bdi>@if ($v->route) <span class="sub">({{ $v->route }})</span>@endif</td></tr>
                @endforeach
            </table></div>
        @endif
        @if ($reqAudits->isNotEmpty())
            <div class="sub" style="margin:10px 0 6px">قيود التدقيق بنفس معرّف الطلب <bdi class="mono ltr">{{ $crumbRid }}</bdi>:</div>
            <div class="tblwrap"><table class="mini">
                @foreach ($reqAudits as $a)
                    <tr><td class="sub" style="width:150px">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $a->action }}@if ($a->name) — {{ \App\Support\Redactor::text($a->name) }}@endif
                            @if ($a->user_id) <span class="sub">· {{ $users[$a->user_id] ?? 'مستخدم محذوف' }}</span>@endif</td></tr>
                @endforeach
            </table></div>
        @endif
    @endif
</div>

{{-- ما العمل --}}
<div class="card">
    <h3 class="cardtitle">🛠️ ما العمل؟</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        {{-- (WP-3.3) الحالاتُ الخمس من خريطة IssueState — «متجاهَل» له نموذجُه لأنه يشترط سبباً --}}
        @foreach (($lifecycle ? array_values(\App\Support\IssueState::MAP) : ['قيد المعالجة', 'محلول', 'جديد']) as $to)
            @continue($to === \App\Support\IssueState::label($e->status) || $to === 'متجاهَل')
            <form class="inline" method="POST" action="{{ route('errors.status', $e->id) }}">
                @csrf<input type="hidden" name="to" value="{{ $to }}">
                <button class="btn ghost sm">علّمه «{{ $to }}»</button>
            </form>
        @endforeach

        @if ($taskId)
            <a class="btn ghost sm" href="{{ route('m.show', ['tasks', $taskId]) }}">✅ مهمة الإصلاح ↗</a>
        @endif
        @if ($lifecycle && ($e->incident_id ?? null))
            {{-- (WP-3.4) ربطُ الحادثة: الخطأ الذي صار حادثةً يقود لصفحتها --}}
            <a class="btn ghost sm" href="{{ route('m.show', ['incidents', $e->incident_id]) }}">🚨 الحادثة المرتبطة ↗</a>
        @endif
    </div>

    @if ($lifecycle && \App\Support\IssueState::label($e->status) !== 'متجاهَل')
        {{-- التجاهل قرارٌ مسبَّب: بلا سببٍ يُرفض ٤٢٢ --}}
        <form method="POST" action="{{ route('errors.status', $e->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
            @csrf<input type="hidden" name="to" value="متجاهَل">
            <label class="vh" for="igr">سبب التجاهل</label>
            <input class="inp" id="igr" name="reason" maxlength="300" required placeholder="سبب التجاهل (إلزامي)" style="max-width:300px" value="{{ old('reason') }}">
            <button class="btn ghost sm">🙈 تجاهَله مع السبب</button>
            @error('reason')<span class="bdg bad">{{ $message }}</span>@enderror
        </form>
    @elseif ($lifecycle && $e->ignored_reason)
        <div class="sub" style="margin-top:8px">🙈 سبب التجاهل: {{ $e->ignored_reason }}</div>
    @endif

    @if ($lifecycle)
        {{-- كتمُ إشعارات هذه البصمة مؤقتاً (يُحترم في الإشعار — حزمة 3.5) --}}
        <form method="POST" action="{{ route('errors.status', $e->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
            @csrf<input type="hidden" name="to" value="{{ \App\Support\IssueState::label($e->status) }}">
            <label class="vh" for="mtd">مدة الكتم</label>
            <select class="inp" id="mtd" name="mute_days" style="max-width:160px">
                <option value="1">كتم يوماً</option><option value="7" selected>كتم أسبوعاً</option><option value="30">كتم شهراً</option>
            </select>
            <button class="btn ghost sm">🔕 اكتم الإشعار</button>
            @if ($e->muted_until && now()->lt($e->muted_until))<span class="bdg wn">مكتوم حتى {{ \Illuminate\Support\Carbon::parse($e->muted_until)->format('Y-m-d') }}</span>@endif
        </form>
    @endif

    @if (! $taskId && hub_can(auth()->user(), 'tasks', 'a'))
        {{-- الإسناد يمرّ بمهمة الإصلاح الواحدة: مسؤولٌ وأولويةٌ وموعدٌ يُختمان عليها وعلى الخطأ --}}
        <form method="POST" action="{{ route('errors.task', $e->id) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px">
            @csrf
            @if ($lifecycle)
                <label class="vh" for="tas">المسؤول</label>
                <select class="inp" id="tas" name="assignee_id" style="max-width:180px">
                    <option value="">بلا مسؤول</option>
                    {{-- (WP-3.4) المرشَّحون النشطون فقط — $users صارت أسماءَ المعروضين لا الجدولَ كلَّه --}}
                    @foreach ($assignees as $uid => $uname)<option value="{{ $uid }}">{{ $uname }}</option>@endforeach
                </select>
                <label class="vh" for="tpr">الأولوية</label>
                <select class="inp" id="tpr" name="priority" style="max-width:130px">
                    <option value="">أولوية تلقائية</option>
                    @foreach (['عاجلة', 'عالية', 'متوسطة', 'منخفضة'] as $p)<option>{{ $p }}</option>@endforeach
                </select>
                <label class="vh" for="tdu">الموعد</label>
                <input class="inp" id="tdu" type="date" name="due_at" style="max-width:150px">
            @endif
            <button class="btn">🛠️ حوّله لمهمة إصلاح</button>
        </form>
    @endif

    <div class="sub" style="margin-top:8px">
        المهمة ترث الرسالة والموضع والرابط ومعرّف الطلب — وأولويتها «عاجلة» إن تكرّر الخطأ ١٠ مرات فأكثر ما لم تُحدَّد أولوية.
    </div>
</div>

{{-- (WP-6.2) اربط هذا الخطأ بحادثةٍ مفتوحة — المرجعُ بصمتُه، فإعادةُ الربط تحديث --}}
@include('partials.incident_link', [
    'ilKind' => 'error',
    'ilRef' => $e->hash,
    'ilSummary' => 'خطأ (' . $kindLabel . '): ' . \Illuminate\Support\Str::limit($e->message, 200),
])

{{-- أخطاء شقيقة --}}
@if ($siblings->isNotEmpty())
    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">🔗 أخطاء من الملف/الرابط نفسه</h3>
        <div class="sub" style="padding:0 14px 8px">قد تكون أعراضاً لعطلٍ جذريٍّ واحد.</div>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>الرسالة</th><th>النوع</th><th>التكرار</th><th>آخر ظهور</th></tr></thead>
            <tbody>
            @foreach ($siblings as $s)
                <tr>
                    <td><a href="{{ route('errors.show', $s->id) }}">{{ \Illuminate\Support\Str::limit($s->message, 80) }}</a></td>
                    <td><span class="bdg g">{{ $s->kind }}</span></td>
                    <td class="mono">{{ $s->count }}</td>
                    <td class="sub">{{ $s->last_seen?->diffForHumans() }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@endif
@endsection
