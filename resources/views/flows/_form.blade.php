{{-- نموذج المسار — يخدم الإنشاء والتعديل معاً.
     يتوقع: $def $module $users، و$flow اختيارياً (تعديل) وإلا فإنشاء. --}}
@php
    // سياق الإنشاء لا يمرّر $flow — تعريفه أولاً شرطٌ لأن الإغلاقات أدناه
    // تلتقطه بـ use، وPHP يرفع «متغير غير معرّف» عند تعريف الإغلاق نفسه
    $flow = $flow ?? null;
    $isEdit = (bool) $flow;
    // قيم العرض: المُدخل السابق عند خطأ التحقق، ثم قيم المسار عند التعديل، ثم الفراغ
    $v = function (string $key, $fallback = '') use ($isEdit, $flow) {
        return old($key, $isEdit ? ($flow->{$key} ?? $fallback) : $fallback);
    };
    // إجراءات المسار مفهرسة بنوعها — لتعبئة الصناديق
    $acts = [];
    if ($isEdit) foreach ((array) $flow->actions as $a) $acts[$a['type'] ?? ''] = $a;
    $on = fn (string $type, string $input) => old($input) !== null
        ? (bool) old($input)
        : isset($acts[$type]);
    $av = fn (string $type, string $key, string $input, $fallback = '') => old($input,
        $acts[$type][$key] ?? $fallback);
@endphp

<form method="POST" action="{{ $isEdit ? route('flows.update', $flow->id) : route('flows.store') }}">
    @csrf
    @if ($isEdit) @method('PUT') @endif
    <input type="hidden" name="m" value="{{ $module }}">
    <div class="fg">
        <div class="fld fw"><label>اسم المسار <b class="req">*</b></label>
            <input class="inp" name="name" required maxlength="190" value="{{ $v('name') }}"
                   placeholder="مثال: تنبيه المالكين عند تذكرة عاجلة"></div>
        <div class="fld"><label>متى يعمل؟ <b class="req">*</b></label>
            @php $curEvent = $v('event', 'created'); @endphp
            <select class="inp" name="event">
                <option value="created" @selected($curEvent === 'created')>عند إنشاء سجل</option>
                <option value="updated" @selected($curEvent === 'updated')>عند تعديل سجل</option>
                <option value="status" @selected($curEvent === 'status')>عند تحول الحالة إلى…</option>
                @php $sem = \App\Support\HubEvents::namesFor($def['key'] ?? ''); @endphp
                @if (count($sem))
                    {{-- أحداث الأعمال: تُغني عن مطابقة نص الحالة يدوياً --}}
                    <optgroup label="أحداث الأعمال">
                        @foreach ($sem as $ev => $lbl)
                            <option value="{{ $ev }}" @selected($curEvent === $ev)>{{ $lbl }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select></div>
        <div class="fld"><label>الحالة الهدف <span class="sub">(لحدث تحول الحالة)</span></label>
            @php
                $stField = collect($def['fields'])->firstWhere('col', $def['status'] ?? '');
                $curStatus = $v('status_to');
            @endphp
            @if ($stField && ! empty($stField['options']))
                <select class="inp" name="status_to"><option value=""></option>
                    @foreach ($stField['options'] as $o)<option @selected($curStatus === $o)>{{ $o }}</option>@endforeach
                </select>
            @else
                <input class="inp" name="status_to" value="{{ $curStatus }}" placeholder="اكتب الحالة نصاً">
            @endif</div>
    </div>

    <h3 style="margin:14px 0 8px" class="sub">شرط اختياري</h3>
    @php $curCondField = $v('cond_field'); $curCondOp = $v('cond_op', 'eq'); @endphp
    <div class="crow">
        <select class="inp" name="cond_field" style="max-width:200px"><option value="">— بلا شرط —</option>
            @foreach ($def['fields'] as $f)@continue(in_array($f['type'], ['file', 'img', 'sec'], true))<option value="{{ $f['key'] }}" @selected($curCondField === $f['key'])>{{ $f['label'] }}</option>@endforeach
        </select>
        <select class="inp" name="cond_op" style="max-width:120px">
            <option value="eq" @selected($curCondOp === 'eq')>يساوي</option>
            <option value="has" @selected($curCondOp === 'has')>يحتوي</option>
            <option value="gt" @selected($curCondOp === 'gt')>أكبر من</option>
            <option value="lt" @selected($curCondOp === 'lt')>أصغر من</option>
        </select>
        <input class="inp" name="cond_value" value="{{ $v('cond_value') }}" placeholder="القيمة" style="max-width:200px">
    </div>

    <h3 style="margin:16px 0 8px" class="sub">الإجراءات (فعّل ما تريد)</h3>
    @error('actions')<div class="ferr" style="margin-bottom:8px">{{ $message }}</div>@enderror
    <div class="fg">
        <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
            <label class="chk"><input type="checkbox" name="a_notify" value="1" @checked($on('notify', 'a_notify'))> 🔔 إشعار داخلي</label>
            <div class="crow">
                @php $notifyTo = $av('notify', 'to', 'a_notify_to', 'owners'); @endphp
                <select class="inp" name="a_notify_to" style="max-width:200px">
                    <option value="owners" @selected($notifyTo === 'owners')>للمالكين والمعتمدين</option>
                    @foreach ($users as $uid => $un)<option value="{{ $uid }}" @selected((string) $notifyTo === (string) $uid)>{{ $un }}</option>@endforeach
                </select>
                <input class="inp" name="a_notify_text" value="{{ $av('notify', 'text', 'a_notify_text') }}"
                       placeholder="النص — مثال: تذكرة عاجلة: {{ '{' }}_display{{ '}' }}">
            </div>
        </div>
        <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
            <label class="chk"><input type="checkbox" name="a_tg" value="1" @checked($on('tg', 'a_tg'))> 📨 رسالة تلجرام (لقناة الشركة)</label>
            <input class="inp" name="a_tg_text" value="{{ $av('tg', 'text', 'a_tg_text') }}" placeholder="نص الرسالة بالقوالب">
        </div>
        <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
            <label class="chk"><input type="checkbox" name="a_mail" value="1" @checked($on('mail', 'a_mail'))> ✉️ بريد إلكتروني</label>
            <div class="crow">
                <input class="inp ltr" type="email" name="a_mail_to" value="{{ $av('mail', 'to_email', 'a_mail_to') }}"
                       placeholder="to@example.com" style="max-width:220px">
                <input class="inp" name="a_mail_text" value="{{ $av('mail', 'text', 'a_mail_text') }}" placeholder="نص الرسالة">
            </div>
        </div>
        <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
            <label class="chk"><input type="checkbox" name="a_task" value="1" @checked($on('task', 'a_task'))> ✅ إنشاء مهمة (ترث مشروع السجل)</label>
            <div class="crow">
                <input class="inp" name="a_task_title" value="{{ $av('task', 'text', 'a_task_title') }}"
                       placeholder="عنوان المهمة — مثال: متابعة {{ '{' }}_display{{ '}' }}">
                @php $taskTo = $av('task', 'assignee', 'a_task_assignee'); @endphp
                <select class="inp" name="a_task_assignee" style="max-width:200px"><option value="">بلا مسؤول</option>
                    @foreach ($users as $uid => $un)<option value="{{ $uid }}" @selected((string) $taskTo === (string) $uid)>{{ $un }}</option>@endforeach
                </select>
            </div>
        </div>
        <div class="fld fw" style="border:1px solid var(--ln);border-radius:12px;padding:12px">
            <label class="chk"><input type="checkbox" name="a_set" value="1" @checked($on('set', 'a_set'))> ✏️ تعيين قيمة حقل في السجل نفسه</label>
            <div class="crow">
                @php $setField = $av('set', 'field', 'a_set_field'); @endphp
                <select class="inp" name="a_set_field" style="max-width:200px">
                    @foreach ($def['fields'] as $f)@continue(in_array($f['type'], ['file', 'img', 'sec', 'ref'], true))<option value="{{ $f['key'] }}" @selected($setField === $f['key'])>{{ $f['label'] }}</option>@endforeach
                </select>
                <input class="inp" name="a_set_value" value="{{ $av('set', 'value', 'a_set_value') }}" placeholder="القيمة">
            </div>
        </div>
    </div>
    <div class="formfoot">
        <button class="btn p">{{ $isEdit ? '💾 حفظ التعديل' : '🪄 إنشاء المسار' }}</button>
        @if ($isEdit)
            <a class="btn ghost" href="{{ route('flows.index', ['m' => $module]) }}">إلغاء</a>
        @endif
    </div>
</form>
