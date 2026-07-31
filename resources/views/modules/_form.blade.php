@php $hx = $hx ?? false; $dup = $dup ?? false; $updating = $row && empty($dup); $hasFile = collect($def['fields'])->whereIn('type', ['file', 'img'])->isNotEmpty(); @endphp
<div id="recform">
    <div class="mhead">
        <b>{{ $updating ? 'تعديل' : ($dup ? 'نسخ سجل' : 'إضافة') }} — {{ $def['label'] }}</b>
        @if ($dup)<span class="bdg wn">نسخة عن سجل موجود — عدّل ثم احفظ</span>@endif
    </div>
    <form method="POST"
          action="{{ $updating ? route('m.update', [$module, $row->id]) : route('m.store', $module) }}"
          data-draft="{{ $module }}:{{ $updating ? $row->id : 'new' }}"
          @if ($hasFile) enctype="multipart/form-data" @endif
          @if ($hx) hx-boost="true" hx-target="#recform" hx-select="#recform" hx-swap="outerHTML"
              hx-select-oob="#tblzone:outerHTML,#flash:innerHTML" hx-push-url="false" @endif>
        @csrf
        @if ($updating)@method('PUT')@endif
        <div class="fg">
            @foreach ($def['fields'] as $f)
                @php $fm = hub_field_mode(auth()->user(), $module, $f['key']); @endphp
                @continue($fm === 'hide')
                @if ($fm === 'ro')
                    {{-- أسماء المراجع تُبنى من خيارات النموذج نفسها، وإلا عُرض معرّف خام بدل الاسم --}}
                    <div class="fld"><span class="lbl">{{ $f['label'] }} <span class="sub">· قراءة فقط</span></span>
                        <div class="ro" aria-readonly="true">
                            @if ($row)
                                @include('partials._display', ['f' => $f, 'row' => $row, 'ctx' => 'show',
                                    'labels' => [$f['key'] => ($refOptions[$f['key']] ?? [])]])
                            @else
                                <span class="sub">—</span>
                            @endif
                        </div>
                    </div>
                @else
                    @include('partials._field', ['f' => $f, 'row' => $row, 'refOptions' => $refOptions])
                @endif
            @endforeach
            @foreach (hub_custom_fields($module) as $cf)
                @php $ck = $cf['key']; $cv = old("custom.$ck", data_get($row?->custom, $ck)); @endphp
                <div class="fld {{ $errors->has("custom.$ck") ? 'haserr' : '' }}">
                    <label>{{ $cf['label'] }} @if (! empty($cf['required']))<b class="req">*</b>@endif <span class="sub">· مخصص</span></label>
                    @if (($cf['type'] ?? 'text') === 'sel')
                        <select class="inp" name="custom[{{ $ck }}]">
                            <option value=""></option>
                            @foreach ((array) ($cf['options'] ?? []) as $o)<option @selected($cv === $o)>{{ $o }}</option>@endforeach
                        </select>
                    @elseif ($cf['type'] === 'bool')
                        <label class="chk"><input type="checkbox" name="custom[{{ $ck }}]" value="1" @checked($cv)> نعم</label>
                    @elseif ($cf['type'] === 'ref')
                        <select class="inp" name="custom[{{ $ck }}]">
                            <option value=""></option>
                            {{-- $cv يمرَّر ليُضمن ظهور المختار حتى بعد سقف الـ٥٠٠ صف — وإلا حُذف المرجع بصمت عند الحفظ --}}
                            @foreach (hub_ref_options($cf['ref'], $cv) as $rid => $rname)<option value="{{ $rid }}" @selected((string) $cv === (string) $rid)>{{ $rname }}</option>@endforeach
                        </select>
                    @elseif ($cf['type'] === 'num')
                        <input class="inp ltr" type="number" step="any" name="custom[{{ $ck }}]" value="{{ $cv }}">
                    @elseif ($cf['type'] === 'date')
                        <input class="inp ltr" type="date" name="custom[{{ $ck }}]" value="{{ $cv }}">
                    @else
                        <input class="inp" name="custom[{{ $ck }}]" value="{{ $cv }}">
                    @endif
                    @error("custom.$ck")<span class="ferr">{{ $message }}</span>@enderror
                </div>
            @endforeach
        </div>
        @if ($updating)
            <div class="fld fw" style="margin-top:6px">
                <label>سبب التعديل <span class="sub">(اختياري — يُحفظ في سجل التدقيق)</span></label>
                <input class="inp" name="_reason" maxlength="380" placeholder="مثال: تصحيح رقم الهاتف بطلب من العميل">
            </div>
        @endif
        {{-- عقدٌ جديد: خيار التحويل للتوقيع الإلكتروني إن لم يكن موقّعاً بعد --}}
        @if ($module === 'contracts' && ! $updating)
            <label class="chk fw" style="margin-top:8px;padding:11px 14px;border:1px dashed color-mix(in srgb,var(--p) 40%,var(--ln));border-radius:12px;background:color-mix(in srgb,var(--p) 4%,transparent)">
                <input type="checkbox" name="to_esign" value="1">
                ✍️ <b>هذا العقد غير موقّع بعد</b> — بعد الحفظ يُضبط على «قيد التوقيع» ويُحوَّل لمركز التوقيع الإلكتروني لإرساله للطرف الآخر، وتُحدَّث حالته تلقائياً عند توقيعه.
            </label>
        @endif
        <div class="formfoot">
            <button class="btn p" type="submit">{{ $updating ? 'حفظ التعديلات' : 'إضافة' }}</button>
            @unless ($updating || $hx)
                <button class="btn" type="submit" name="_stay" value="1">حفظ وإضافة آخر</button>
            @endunless
            @if ($hx)
                <button class="btn ghost" type="button" onclick="Hub.closeModal()">إلغاء</button>
            @else
                <a class="btn ghost" href="{{ route('m.index', $module) }}">إلغاء</a>
            @endif
        </div>
    </form>
</div>
