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
    <table class="tbl">
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
                    <a class="btn ghost xs" href="{{ route('flows.sandbox', $f->id) }}" title="جرّب المسار على سجل حقيقي بلا تنفيذ">🧪 تجربة</a>
                    <form class="inline" method="POST" action="{{ route('flows.toggle', $f->id) }}">@csrf<button class="btn ghost xs">{{ $f->enabled ? 'تعطيل' : 'تفعيل' }}</button></form>
                    <form class="inline" method="POST" action="{{ route('flows.destroy', $f->id) }}" onsubmit="return confirm('حذف المسار؟')">@csrf @method('DELETE')<button class="btn ghost xs" style="color:var(--bad)">حذف</button></form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty"><span class="big">🪄</span>لا مسارات بعد — اختر وحدة بالأسفل وابنِ أول مسار</td></tr>
        @endforelse
        </tbody>
    </table>
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
    <form method="POST" action="{{ route('flows.store') }}">
        @csrf
        <input type="hidden" name="m" value="{{ $module }}">
        <div class="fg">
            <div class="fld fw"><label>اسم المسار <span class="req">*</span></label>
                <input class="inp" name="name" required maxlength="190" value="{{ old('name') }}" placeholder="مثال: تنبيه المالكين عند تذكرة عاجلة"></div>
            <div class="fld"><label>متى يعمل؟ <span class="req">*</span></label>
                <select class="inp" name="event">
                    <option value="created">عند إنشاء سجل</option>
                    <option value="updated" @selected(old('event') === 'updated')>عند تعديل سجل</option>
                    <option value="status" @selected(old('event') === 'status')>عند تحول الحالة إلى…</option>
                    @php $sem = \App\Support\HubEvents::namesFor($def['key'] ?? ''); @endphp
                    @if (count($sem))
                        {{-- أحداث الأعمال: تُغني عن مطابقة نص الحالة يدوياً --}}
                        <optgroup label="أحداث الأعمال">
                            @foreach ($sem as $ev => $lbl)
                                <option value="{{ $ev }}" @selected(old('event') === $ev)>{{ $lbl }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select></div>
            <div class="fld"><label>الحالة الهدف <span class="sub">(لحدث تحول الحالة)</span></label>
                @php $stField = collect($def['fields'])->firstWhere('col', $def['status'] ?? ''); @endphp
                @if ($stField && ! empty($stField['options']))
                    <select class="inp" name="status_to"><option value=""></option>
                        @foreach ($stField['options'] as $o)<option @selected(old('status_to') === $o)>{{ $o }}</option>@endforeach
                    </select>
                @else
                    <input class="inp" name="status_to" value="{{ old('status_to') }}" placeholder="اكتب الحالة نصاً">
                @endif</div>
        </div>

        <h3 style="margin:14px 0 8px" class="sub">شرط اختياري</h3>
        <div class="crow">
            <select class="inp" name="cond_field" style="max-width:200px"><option value="">— بلا شرط —</option>
                @foreach ($def['fields'] as $f)@continue(in_array($f['type'], ['file', 'img', 'sec'], true))<option value="{{ $f['key'] }}" @selected(old('cond_field') === $f['key'])>{{ $f['label'] }}</option>@endforeach
            </select>
            <select class="inp" name="cond_op" style="max-width:120px">
                <option value="eq">يساوي</option><option value="has" @selected(old('cond_op') === 'has')>يحتوي</option>
                <option value="gt" @selected(old('cond_op') === 'gt')>أكبر من</option><option value="lt" @selected(old('cond_op') === 'lt')>أصغر من</option>
            </select>
            <input class="inp" name="cond_value" value="{{ old('cond_value') }}" placeholder="القيمة" style="max-width:200px">
        </div>

        <h3 style="margin:16px 0 8px" class="sub">الإجراءات (فعّل ما تريد)</h3>
        @error('actions')<div class="ferr" style="margin-bottom:8px">{{ $message }}</div>@enderror
        <div class="fg">
            <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
                <label class="chk"><input type="checkbox" name="a_notify" value="1" @checked(old('a_notify'))> 🔔 إشعار داخلي</label>
                <div class="crow">
                    <select class="inp" name="a_notify_to" style="max-width:200px">
                        <option value="owners">للمالكين والمعتمدين</option>
                        @foreach ($users as $uid => $un)<option value="{{ $uid }}">{{ $un }}</option>@endforeach
                    </select>
                    <input class="inp" name="a_notify_text" value="{{ old('a_notify_text') }}" placeholder="النص — مثال: تذكرة عاجلة: {{ '{' }}_display{{ '}' }}">
                </div>
            </div>
            <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
                <label class="chk"><input type="checkbox" name="a_tg" value="1" @checked(old('a_tg'))> 📨 رسالة تلجرام (لقناة الشركة)</label>
                <input class="inp" name="a_tg_text" value="{{ old('a_tg_text') }}" placeholder="نص الرسالة بالقوالب">
            </div>
            <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
                <label class="chk"><input type="checkbox" name="a_mail" value="1" @checked(old('a_mail'))> ✉️ بريد إلكتروني</label>
                <div class="crow">
                    <input class="inp ltr" type="email" name="a_mail_to" value="{{ old('a_mail_to') }}" placeholder="to@example.com" style="max-width:220px">
                    <input class="inp" name="a_mail_text" value="{{ old('a_mail_text') }}" placeholder="نص الرسالة">
                </div>
            </div>
            <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
                <label class="chk"><input type="checkbox" name="a_task" value="1" @checked(old('a_task'))> ✅ إنشاء مهمة (ترث مشروع السجل)</label>
                <div class="crow">
                    <input class="inp" name="a_task_title" value="{{ old('a_task_title') }}" placeholder="عنوان المهمة — مثال: متابعة {{ '{' }}_display{{ '}' }}">
                    <select class="inp" name="a_task_assignee" style="max-width:200px"><option value="">بلا مسؤول</option>
                        @foreach ($users as $uid => $un)<option value="{{ $uid }}">{{ $un }}</option>@endforeach
                    </select>
                </div>
            </div>
            <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
                <label class="chk"><input type="checkbox" name="a_set" value="1" @checked(old('a_set'))> ✏️ تعيين قيمة حقل في السجل نفسه</label>
                <div class="crow">
                    <select class="inp" name="a_set_field" style="max-width:200px">
                        @foreach ($def['fields'] as $f)@continue(in_array($f['type'], ['file', 'img', 'sec', 'ref'], true))<option value="{{ $f['key'] }}">{{ $f['label'] }}</option>@endforeach
                    </select>
                    <input class="inp" name="a_set_value" value="{{ old('a_set_value') }}" placeholder="القيمة">
                </div>
            </div>
        </div>
        <div class="formfoot"><button class="btn p">🪄 إنشاء المسار</button></div>
    </form>
    @endif
</div>
@endsection
