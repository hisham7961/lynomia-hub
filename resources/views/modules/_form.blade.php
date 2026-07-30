@php $hx = $hx ?? false; $dup = $dup ?? false; $updating = $row && empty($dup); $hasFile = collect($def['fields'])->whereIn('type', ['file', 'img'])->isNotEmpty(); @endphp
<div id="recform">
    <div class="mhead">
        <b>{{ $updating ? 'تعديل' : ($dup ? 'نسخ سجل' : 'إضافة') }} — {{ $def['label'] }}</b>
        @if ($dup)<span class="bdg wn">نسخة عن سجل موجود — عدّل ثم احفظ</span>@endif
    </div>
    <form method="POST"
          action="{{ $updating ? route('m.update', [$module, $row->id]) : route('m.store', $module) }}"
          @if ($hasFile) enctype="multipart/form-data" @endif
          @if ($hx) hx-boost="true" hx-target="#recform" hx-select="#recform" hx-swap="outerHTML"
              hx-select-oob="#tblzone:outerHTML,#flash:innerHTML" hx-push-url="false" @endif>
        @csrf
        @if ($updating)@method('PUT')@endif
        <div class="fg">
            @foreach ($def['fields'] as $f)
                @include('partials._field', ['f' => $f, 'row' => $row, 'refOptions' => $refOptions])
            @endforeach
            @foreach (hub_custom_fields($module) as $cf)
                @php $ck = $cf['key']; $cv = old("custom.$ck", data_get($row?->custom, $ck)); @endphp
                <div class="fld {{ $errors->has("custom.$ck") ? 'haserr' : '' }}">
                    <label>{{ $cf['label'] }} @if (! empty($cf['required']))<span class="req">*</span>@endif <span class="sub">· مخصص</span></label>
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
                            @foreach (hub_ref_options($cf['ref']) as $rid => $rname)<option value="{{ $rid }}" @selected((string) $cv === (string) $rid)>{{ $rname }}</option>@endforeach
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
