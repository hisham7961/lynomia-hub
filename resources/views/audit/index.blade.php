@extends('layouts.app')
@section('title', 'سجل التدقيق')
@section('content')
@include('partials.pagehead', ['icon' => '🧾', 'title' => 'سجل التدقيق', 'crumb' => 'النظام',
    'sub' => 'من فعل ماذا، بأي سجل، من أي عنوان — ومختومٌ ببصمةٍ متشابكة تكشف أي عبثٍ مباشر بالقاعدة'])

<div class="cards" style="margin-bottom:12px">
    <div class="kpi {{ $chain['ok'] ? '' : 'bad' }}">
        <div class="lbl">🔗 سلامة السلسلة</div>
        <div class="val" style="font-size:16px">{{ $chain['ok'] ? 'سلسلة سليمة' : '⚠️ عبث' }}</div>
        <div class="sub">{{ $chain['ok'] ? $chain['label'] : $chain['why'] }}</div>
    </div>
    {{-- (WP-5.5 · §1.6) آخرُ فحصٍ كامل من تاريخ audit_verifications — الصفحةُ تقرأ
         التاريخَ ولا تُشغّل الفحص: verifyTail (ذيلٌ محدود) وحده على التحميل --}}
    @php $lv = $verifs->first(); @endphp
    <div class="kpi {{ $lv && $lv->result === 'fail' ? 'bad' : '' }}">
        <div class="lbl">🕰️ آخر فحص كامل للسلسلة</div>
        @if ($lv)
            <div class="val" style="font-size:16px">{{ ['ok' => '✅ سليمة', 'warn' => '⚠️ سليمة بملاحظات', 'fail' => '❌ فشل'][$lv->result] ?? $lv->result }}</div>
            <div class="sub">{{ \Illuminate\Support\Carbon::parse($lv->started_at)->format('Y-m-d H:i') }}
                · {{ $lv->mode === 'manual' ? 'يدويّ' : 'آليّ' }}
                · {{ number_format((int) $lv->checked_rows) }} قيد متحقق
                · <span class="mono ltr">{{ (int) $lv->duration_ms }}ms</span>@if ($lv->first_bad_id) · أول قيد متأثر <span class="mono ltr">#{{ $lv->first_bad_id }}</span>@endif</div>
        @else
            <div class="val" style="font-size:16px">لم يُشغَّل بعد</div>
            <div class="sub">يجري أسبوعياً آلياً، وفوراً من مركز التشغيل ⚙️ — وكلُّ تشغيلٍ يؤرَّخ هنا</div>
        @endif
    </div>
    {{-- (WP-5.5 · §1.8 · ق٦) الاحتفاظ وصفٌ يُقرأ لا مقصٌّ يُنفَّذ — لا كودَ تقليمٍ لسجل التدقيق --}}
    <div class="kpi">
        <div class="lbl">🗄️ سياسة الاحتفاظ</div>
        <div class="val" style="font-size:16px">{{ $retention['days'] > 0 ? $retention['days'] . ' يوماً (وصفاً)' : 'للأبد' }}</div>
        <div class="sub">{{ $retention['policy'] !== '' ? $retention['policy'] : 'وصفٌ معلَن لا مقصّ — لا تقليمَ آلياً لسجل التدقيق' }}
            @if (hub_is_owner())· <a href="{{ route('audit.coverage') }}">🧭 محلّل التغطية</a>@endif</div>
    </div>
</div>

@if ($verifs->count() > 1)
    {{-- تاريخُ الفحوص الأخيرة — سؤالُ §45: هل تُفحص السلسلة فعلاً أم يُوعد بفحصها؟ --}}
    <details style="margin-bottom:12px">
        <summary class="btn ghost sm" style="display:inline-block;cursor:pointer">🕰️ آخر {{ $verifs->count() }} فحوص ▾</summary>
        <div class="card pad0" style="margin-top:8px"><div class="tblwrap"><table class="tbl">
            <thead><tr>
                <th scope="col">متى</th><th scope="col">الوضع</th><th scope="col">النتيجة</th>
                <th scope="col">قيود متحققة</th><th scope="col">المدة</th><th scope="col">الخلاصة</th>
            </tr></thead>
            <tbody>
            @foreach ($verifs as $v)
                <tr>
                    <td class="mono">{{ \Illuminate\Support\Carbon::parse($v->started_at)->format('m-d H:i') }}</td>
                    <td>{{ $v->mode === 'manual' ? 'يدويّ' : 'آليّ' }}</td>
                    <td><span class="bdg {{ ['ok' => 'ok', 'warn' => 'wn', 'fail' => 'bad'][$v->result] ?? '' }}">{{ ['ok' => 'سليمة', 'warn' => 'بملاحظات', 'fail' => 'فشل'][$v->result] ?? $v->result }}</span></td>
                    <td class="mono ltr">{{ number_format((int) $v->checked_rows) }}</td>
                    <td class="mono ltr">{{ (int) $v->duration_ms }}ms</td>
                    <td class="sub">{{ \Illuminate\Support\Str::limit((string) $v->message, 90) }}@if ($v->first_bad_id) · <span class="mono ltr">#{{ $v->first_bad_id }}</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div></div>
    </details>
@endif

@if (! $chain['ok'])
    <div class="card" style="border-color:var(--bad);margin-bottom:12px">
        <b>⚠️ سلسلة التدقيق مكسورة:</b> {{ $chain['why'] }}
        {{-- كان الإرشادُ سطرَ طرفيةٍ لا يملكها صاحبُ استضافةٍ مشتركة — فالفاحصُ
             الوحيدُ لهذا الضمان لا يُشغَّل أبداً. الآن زرٌّ في مركز التشغيل. --}}
        <div class="sub" style="margin-top:6px">الشاشة تفحص الذيل فقط لأن فحص الجدول كاملاً لا يُحتمل مع كل فتحة —
            @if (hub_is_owner())
                <form method="POST" action="{{ route('ops.verifyaudit') }}" class="inline">@csrf
                    <button class="btn ghost xs">🔗 افحص السلسلة كاملةً الآن</button></form>
                لتحديد أول قيدٍ متأثّر ومدى الضرر.
            @else
                اطلب من مالك النظام فحصَ السلسلة كاملةً من مركز التشغيل ⚙️ لتحديد أول قيدٍ متأثّر ومدى الضرر.
            @endif
        </div>
    </div>
@endif

{{-- (WP-5.3) النظرةُ التنفيذية: ١٢ عدّاداً تجميعياً على المدى المختار —
     كلُّها فوق Audit::scopedQuery فلا يَعُدّ القارئُ ما لا يراه --}}
@include('partials.cc.kpis', ['items' => $kpis])

{{-- المدى الزمنيّ الموحّد — بدل حقلَي whereDate غير السارغابل --}}
@include('partials.timerange', ['range' => $range])

@php
    $activeView = $views->firstWhere('id', request('view'));
    $advOpen = collect(['severity', 'category', 'role', 'company', 'project', 'client',
        'request_id', 'sensitive', 'failed', 'changed', 'emergency'])
        ->contains(fn ($k) => request()->filled($k));
@endphp
<div class="toolbar">
    {{-- التحقيقاتُ المحفوظة (saved_views بوحدة 'audit') — احفظ السؤال لا الإجابة --}}
    <details class="ddwrap">
        <summary class="btn ghost sm ddsum">📑 {{ $activeView?->name ?? 'تحقيقاتي المحفوظة' }} ▾</summary>
        <div class="card ddpanel">
            @forelse ($views as $v)
                <div style="display:flex;gap:6px;align-items:center;padding:4px 0">
                    <a href="{{ $v->url() }}" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $v->name }}</a>
                    <form method="POST" action="{{ route('views.destroy', $v->id) }}" class="inline" data-confirm="حذف هذا التحقيق؟">
                        @csrf @method('DELETE')<button class="btn ghost xs" aria-label="حذف التحقيق {{ $v->name }}">✕</button></form>
                </div>
            @empty
                <div class="sub" style="padding:4px 0">لا تحقيقات محفوظة — رشّح واضبط المدى ثم احفظ السؤال باسم</div>
            @endforelse
            {{-- حقل الاستعلام يُملأ من العنوان لحظة الإرسال ليواكب آخرَ ما طُبِّق --}}
            <form method="POST" action="{{ route('views.store') }}" onsubmit="this.query.value=location.search.replace(/^\?/,'')"
                  style="display:flex;gap:6px;align-items:center;margin-top:8px;border-top:1px solid var(--brd);padding-top:8px;flex-wrap:wrap">
                @csrf
                <input type="hidden" name="module" value="audit">
                <input type="hidden" name="query" value="{{ request()->getQueryString() }}">
                <label class="vh" for="inv-name">اسم التحقيق</label>
                <input class="inp" id="inv-name" name="name" maxlength="60" required placeholder="اسم التحقيق الحالي…" style="flex:1;min-width:130px">
                <button class="btn sm">💾 حفظ</button>
            </form>
        </div>
    </details>

    <form class="filters" method="GET" style="flex:1">
        {{-- المدى المختار يبقى مع كل بحث — كبسولاتُه فوق هذا النموذج --}}
        @foreach ($range->toQuery() as $trK => $trV)
            <input type="hidden" name="{{ $trK }}" value="{{ $trV }}">
        @endforeach
        @if (request('sort'))
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="dir" value="{{ request('dir') }}">
        @endif
        <select class="inp" name="user">
            <option value="">كل المستخدمين</option>
            @foreach ($users as $uid => $un)<option value="{{ $uid }}" @selected(request('user') === (string) $uid)>{{ $un }}</option>@endforeach
        </select>
        <select class="inp" name="action">
            <option value="">كل الإجراءات</option>
            @foreach ($actions as $act)<option value="{{ $act }}" @selected(request('action') === $act)>{{ $act }}</option>@endforeach
        </select>
        <select class="inp" name="module">
            <option value="">كل الوحدات</option>
            {{-- الوحدات المرئية للقارئ وحدها (WP-5.1) — الوحدة المحجوبة لا تُسمّى في المنسدلة --}}
            @foreach ($modules as $mk => $md)<option value="{{ $mk }}" @selected(request('module') === $mk)>{{ $md['label'] }}</option>@endforeach
        </select>
        <input class="inp ltr" name="ip" value="{{ request('ip') }}" placeholder="IP" style="max-width:130px">
        <input class="inp" type="search" name="q" value="{{ request('q') }}" placeholder="اسم السجل أو السبب…">
        <button class="btn sm">بحث</button>
        @if (request()->query())<a class="btn ghost sm" href="{{ route('audit.index') }}">✕ مسح</a>@endif

        {{-- (WP-5.3) التحقيق المتقدّم — داخل details فلا يزدحم الجوّال --}}
        <details style="flex-basis:100%" @if ($advOpen) open @endif>
            <summary class="btn ghost sm" style="display:inline-block;cursor:pointer">🔎 تحقيق متقدّم ▾</summary>
            <div class="filters" style="margin-top:8px">
                <select class="inp" name="severity">
                    <option value="">كل الشدّات</option>
                    @foreach (['info' => 'معلومة', 'notice' => 'ملحوظ', 'warning' => 'تحذير', 'high' => 'خطِر'] as $sk => $sl)
                        <option value="{{ $sk }}" @selected(request('severity') === $sk)>{{ $sl }}</option>
                    @endforeach
                </select>
                <select class="inp" name="category">
                    <option value="">كل الفئات</option>
                    @foreach ($categories as $ck => $cl)
                        <option value="{{ $ck }}" @selected(request('category') === $ck)>{{ $cl }}</option>
                    @endforeach
                </select>
                <select class="inp" name="role">
                    <option value="">كل الأدوار</option>
                    @foreach ($roles as $rid => $rn)<option value="{{ $rid }}" @selected(request('role') === (string) $rid)>{{ $rn }}</option>@endforeach
                </select>
                <select class="inp" name="company">
                    <option value="">كل الشركات</option>
                    @foreach ($companies as $cid => $cn)<option value="{{ $cid }}" @selected(request('company') === (string) $cid)>{{ $cn }}</option>@endforeach
                </select>
                <select class="inp" name="project">
                    <option value="">كل المشاريع</option>
                    @foreach ($projects as $pid => $pn)<option value="{{ $pid }}" @selected(request('project') === (string) $pid)>{{ $pn }}</option>@endforeach
                </select>
                <select class="inp" name="client">
                    <option value="">كل العملاء</option>
                    @foreach ($clients as $kid => $kn)<option value="{{ $kid }}" @selected(request('client') === (string) $kid)>{{ $kn }}</option>@endforeach
                </select>
                <input class="inp ltr mono" name="request_id" value="{{ request('request_id') }}" placeholder="معرّف الطلب rid" style="max-width:160px">
                <label class="chk sub"><input type="checkbox" name="sensitive" value="1" @checked(request()->boolean('sensitive'))> حسّاس فقط</label>
                <label class="chk sub"><input type="checkbox" name="failed" value="1" @checked(request()->boolean('failed'))> فاشل أو مرفوض</label>
                <label class="chk sub"><input type="checkbox" name="changed" value="1" @checked(request()->boolean('changed'))> بيانات تغيّرت</label>
                <label class="chk sub"><input type="checkbox" name="emergency" value="1" @checked(request()->boolean('emergency'))> طوارئ</label>
            </div>
        </details>
    </form>
</div>

<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        {{-- رؤوسٌ قابلة للفرز عبر cc/th: scope="col" دائماً وaria-sort صادق (critic #13) --}}
        <thead><tr>
            @include('partials.cc.th', ['col' => 'time', 'label' => 'الوقت', 'default' => 'time'])
            @include('partials.cc.th', ['col' => 'user', 'label' => 'المستخدم', 'default' => 'time'])
            @include('partials.cc.th', ['col' => 'action', 'label' => 'الإجراء', 'default' => 'time'])
            @include('partials.cc.th', ['col' => 'module', 'label' => 'الوحدة', 'default' => 'time'])
            <th scope="col">السجل والتغيير</th>
            @include('partials.cc.th', ['col' => 'ip', 'label' => 'من أين', 'default' => 'time'])
        </tr></thead>
        <tbody>
        @forelse ($rows as $a)
            @php
                $diff = \App\Support\Audit::diff($a->module, $a->before ?? null, $a->after ?? null);
                // التصنيف: المخزّنُ للجديد، ومُترجِمُ القراءة (WP-5.2) للصفوف الأقدم من التطبيع
                $ac = ($a->severity ?? null)
                    ? ['category' => $a->category, 'severity' => $a->severity]
                    : hub_audit_class($a->action, $a->module, $a->before ?? null, $a->after ?? null, $a->name);
            @endphp
            <tr>
                {{-- (WP-5.4) الوقتُ رابطُ صفحة التفصيل — القيدُ كاملاً: الفرقُ والطلبُ والنزاهة --}}
                <td class="mono" title="{{ $a->created_at }}"><a href="{{ route('audit.show', $a->id) }}">{{ \Illuminate\Support\Carbon::parse($a->created_at)->format('m-d H:i') }}</a></td>
                <td>{{ $a->user_name ?? '—' }}</td>
                <td>
                    <span class="bdg {{ $a->action === 'حذف' ? 'bad' : ($a->action === 'إضافة' ? 'ok' : (str_contains($a->action, 'تصدير') || str_contains($a->action, 'عرض سرّ') ? 'wn' : '')) }}">{{ $a->action }}</span>
                    <div style="margin-top:2px"><span class="bdg {{ ['warning' => 'wn', 'high' => 'bad'][$ac['severity']] ?? '' }}" style="font-size:10px" title="الفئة والشدّة">{{ $categories[$ac['category']] ?? $ac['category'] }}</span></div>
                </td>
                <td>@if ($a->module)<a href="{{ route('m.index', $a->module) }}">{{ hub_mod($a->module)['label'] ?? $a->module }}</a>@else <span class="sub">—</span> @endif</td>
                <td>
                    @if ($a->module && $a->record_id)
                        <a href="{{ route('m.show', [$a->module, $a->record_id]) }}"><b>{{ \Illuminate\Support\Str::limit($a->name, 44) ?: 'سجل' }}</b></a>
                    @else
                        <b>{{ \Illuminate\Support\Str::limit($a->name, 44) ?: '—' }}</b>
                    @endif
                    @if ($a->reason)<div class="sub">💬 السبب: {{ \Illuminate\Support\Str::limit($a->reason, 70) }}</div>@endif

                    @if (count($diff))
                        <div style="margin-top:6px;display:flex;flex-direction:column;gap:3px">
                            @foreach (array_slice($diff, 0, 6) as $d)
                                <div class="sub" style="font-size:12px">
                                    <b>{{ $d['label'] }}:</b>
                                    <span style="text-decoration:line-through;opacity:.65">{{ $d['from'] }}</span>
                                    <span style="opacity:.5">←</span>
                                    <span class="bdg ok">{{ $d['to'] }}</span>
                                </div>
                            @endforeach
                            @if (count($diff) > 6)
                                <details><summary class="sub pointer">و{{ count($diff) - 6 }} حقلاً آخر ▾</summary>
                                    @foreach (array_slice($diff, 6) as $d)
                                        <div class="sub" style="font-size:12px"><b>{{ $d['label'] }}:</b>
                                            <span style="opacity:.65">{{ $d['from'] }}</span> ← {{ $d['to'] }}</div>
                                    @endforeach
                                </details>
                            @endif
                        </div>
                    @endif
                </td>
                <td class="mono ltr" style="font-size:11px">
                    {{ $a->ip ?: '—' }}
                    @if ($a->device)<div class="sub" title="{{ $a->device }}">{{ \Illuminate\Support\Str::limit($a->device, 26) }}</div>@endif
                    @if (empty($a->hash))<div class="bdg wn" title="قيدٌ بلا بصمة">بلا ختم</div>@endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🧾</span>لا سجلات تطابق المرشِّحات في هذا المدى</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
{{ $rows->links('partials.pagination_simple') }}

<div class="card" style="margin-top:12px">
    <h3>كيف يُقرأ هذا السجل؟</h3>
    <div class="sub" style="line-height:2">
        كل قيدٍ مختومٌ ببصمة <span class="mono ltr">sha256(البصمة السابقة | محتوى القيد)</span> — فتعديل صفٍّ واحدٍ مباشرةً في القاعدة يكسر كل ما بعده.
        السجل <b>لا يُعدَّل ولا يُحذف من الواجهة</b>، وتشمل السلسلة عمليات التصدير و<b>عرض الأسرار</b>.<br>
        الفرق يعرض <b>الحقول المتغيّرة وحدها</b> بأسمائها من سجل الوحدات — وقيم الأسرار وبصمات كلمات المرور تُخفى ولو ظهرت في القيد.
    </div>
</div>
@endsection
