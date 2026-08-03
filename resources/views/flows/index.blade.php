@extends('layouts.app')
@section('title', 'باني مسارات العمل')
@section('content')
<div class="hero">
    <div>
        <h2>🪄 مسارات العمل</h2>
        <div class="sub">
            «عندما يحدث كذا ← افعل كذا» — بلا كود.
            <b>{{ $onCount }}</b> مفعّل من <b>{{ $total }}</b> مسار.
            القوالب: {{ '{' }}مفتاح_الحقل{{ '}' }} و{{ '{' }}_display{{ '}' }} و{{ '{' }}_module{{ '}' }} و{{ '{' }}_by{{ '}' }}
        </div>
    </div>
    <a class="btn ghost sm" href="#builder">＋ مسار جديد</a>
</div>

{{-- التغطية: أي جانبٍ محروس وأيّه مكشوف --}}
<div class="card">
    <h3 class="cardtitle">🗺️ تغطية النظام</h3>
    <div class="sub" style="margin-bottom:8px">الوحدات التي لها مسارٌ واحد على الأقل — وما لا مسار له.</div>
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>المجموعة</th><th>مغطّاة</th><th>بلا مسار</th><th class="acts">تفعيل جماعي</th></tr></thead>
        <tbody>
        @foreach ($coverage as $gname => $c)
            <tr>
                <td><b>{{ $c['icon'] }} {{ $gname }}</b></td>
                <td><span class="bdg ok">{{ count($c['has']) }}</span></td>
                <td>
                    @if ($c['missing'])
                        <span class="bdg wn">{{ count($c['missing']) }}</span>
                        <div class="sub">{{ collect($c['missing'])->map(fn ($m) => hub_mod($m)['label'] ?? $m)->take(6)->implode('، ') }}@if (count($c['missing']) > 6) …@endif</div>
                    @else
                        <span class="sub">لا شيء ✓</span>
                    @endif
                </td>
                <td class="acts">
                    @if (count($c['has']))
                        <form class="inline" method="POST" action="{{ route('flows.bulk') }}">@csrf
                            <input type="hidden" name="g" value="{{ $gname }}"><input type="hidden" name="do" value="on">
                            <button class="btn ghost xs">فعّل الكل</button></form>
                        <form class="inline" method="POST" action="{{ route('flows.bulk') }}">@csrf
                            <input type="hidden" name="g" value="{{ $gname }}"><input type="hidden" name="do" value="off">
                            <button class="btn ghost xs">عطّل الكل</button></form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>

{{-- بحث وفلترة --}}
<div class="card">
    <form method="GET" class="crow">
        <input class="inp" name="q" value="{{ $q }}" placeholder="🔎 ابحث باسم المسار أو الوحدة أو الحالة" style="max-width:280px">
        <select class="inp" name="only" style="max-width:160px">
            <option value="">الكل</option>
            <option value="on" @selected($only === 'on')>المفعّلة فقط</option>
            <option value="off" @selected($only === 'off')>المعطّلة فقط</option>
        </select>
        <select class="inp" name="g" style="max-width:200px">
            <option value="">كل المجموعات</option>
            @foreach (array_keys($coverage) as $gname)
                <option value="{{ $gname }}" @selected($group === $gname)>{{ $gname }}</option>
            @endforeach
        </select>
        <button class="btn sm">تصفية</button>
        @if ($q !== '' || $only !== '' || $group !== '')
            <a class="btn ghost sm" href="{{ route('flows.index') }}">مسح</a>
        @endif
    </form>
</div>

{{-- المسارات مجمّعة --}}
@forelse ($grouped as $gname => $items)
    <div class="card pad0">
        <h3 class="cardtitle" style="padding:12px 14px 0">
            {{ $coverage[$gname]['icon'] ?? '🗂' }} {{ $gname }}
            <span class="sub">({{ $items->count() }})</span>
        </h3>
        <div class="tblwrap"><table class="tbl">
            <thead><tr><th>المسار</th><th>متى</th><th>الإجراءات</th><th>التشغيلات</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
            <tbody>
            @foreach ($items as $f)
                @php $fdef = hub_mod($f->module); @endphp
                <tr style="{{ $f->enabled ? '' : 'opacity:.55' }}">
                    <td><b>{{ $f->name }}</b><div class="sub">{{ $fdef['label'] ?? $f->module }}</div></td>
                    <td class="sub">{{ \App\Support\HubEvents::label($f->event) }}{{ $f->status_to ? ' إلى «' . $f->status_to . '»' : '' }}
                        @if ($f->cond_field)<div>+ شرط: {{ collect($fdef['fields'] ?? [])->firstWhere('key', $f->cond_field)['label'] ?? $f->cond_field }} {{ ['eq' => '=', 'has' => 'يحتوي', 'gt' => '>', 'lt' => '<'][$f->cond_op] ?? '=' }} {{ $f->cond_value }}</div>@endif</td>
                    <td>@foreach ((array) $f->actions as $a)<span class="bdg g">{{ ['notify' => '🔔 إشعار', 'tg' => '📨 تلجرام', 'mail' => '✉️ بريد', 'task' => '✅ مهمة', 'set' => '✏️ تعيين حقل'][$a['type']] ?? $a['type'] }}</span> @endforeach</td>
                    <td><b>{{ $f->runs }}</b>@if ($f->last_run_at)<div class="sub">{{ $f->last_run_at->diffForHumans() }}</div>@endif</td>
                    <td>@if ($f->enabled)<span class="bdg ok">مفعّل</span>@else<span class="bdg g">معطل</span>@endif</td>
                    <td class="acts">
                        <a class="btn ghost xs" href="{{ route('flows.edit', $f->id) }}" title="عدّل المسار مع حفظ عدّاد تشغيلاته">✏️ تعديل</a>
                        <a class="btn ghost xs" href="{{ route('flows.sandbox', $f->id) }}" title="جرّب المسار على سجل حقيقي بلا تنفيذ">🧪 تجربة</a>
                        <form class="inline" method="POST" action="{{ route('flows.duplicate', $f->id) }}">@csrf<button class="btn ghost xs" title="انسخه معطّلاً لبناء متغيّر منه">📑 نسخ</button></form>
                        <form class="inline" method="POST" action="{{ route('flows.toggle', $f->id) }}">@csrf<button class="btn ghost xs">{{ $f->enabled ? 'تعطيل' : 'تفعيل' }}</button></form>
                        <form class="inline" method="POST" action="{{ route('flows.destroy', $f->id) }}" data-confirm="حذف المسار؟">@csrf @method('DELETE')<button class="btn ghost xs" style="color:var(--bad)">حذف</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">🪄</span>
        {{ $total ? 'لا مسار يطابق التصفية — امسح الفلاتر أو غيّر البحث' : 'لا مسارات بعد — شغّل hub:flows-starter أو ابنِ أول مسار بالأسفل' }}
    </div></div>
@endforelse

{{-- الباني --}}
<div class="card" style="max-width:760px" id="builder">
    <h3>＋ مسار جديد</h3>
    <form method="GET" class="crow" style="margin-bottom:12px">
        <select class="inp" name="m" onchange="this.form.submit()" style="max-width:280px">
            <option value="">— اختر الوحدة أولاً —</option>
            @foreach (hub_modules() as $mk => $md)<option value="{{ $mk }}" @selected($module === $mk)>{{ $md['label'] }}</option>@endforeach
        </select>
        <noscript><button class="btn sm">اختيار</button></noscript>
    </form>

    @if ($def)
        @include('flows._form')
    @endif
</div>
@endsection
