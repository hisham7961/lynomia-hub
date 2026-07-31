@extends('layouts.app')
@section('title', 'باني مسارات العمل')
@section('content')
<div class="hero">
    <div>
        <h2>🪄 باني مسارات العمل</h2>
        <div class="sub">«عندما يحدث كذا ← افعل كذا» — بلا كود. القوالب: {{ '{' }}مفتاح_الحقل{{ '}' }} و{{ '{' }}_display{{ '}' }} و{{ '{' }}_module{{ '}' }} و{{ '{' }}_by{{ '}' }}</div>
    </div>
</div>

{{-- المسارات الموجودة --}}
<div class="card pad0">
    <div class="tblwrap"><table class="tbl">
        <thead><tr><th>المسار</th><th>متى</th><th>الإجراءات</th><th>التشغيلات</th><th>الحالة</th><th class="acts">إجراء</th></tr></thead>
        <tbody>
        @forelse ($flows as $f)
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
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🪄</span>لا مسارات بعد — اختر وحدة بالأسفل وابنِ أول مسار</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>

{{-- الباني --}}
<div class="card" style="max-width:760px">
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
