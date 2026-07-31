@php
    $k = $f['key']; $c = $f['col']; $t = $f['type'];
    // التعبئة المسبقة تعمل في الإضافة فقط — التعديل يعرض قيم السجل حصراً
    $raw = $row ? $row->{$c} : (($prefill ?? [])[$k] ?? null);
    $wide = in_array($t, ['ta', 'tags']) || ! empty($f['multi']);
@endphp
<div class="fld {{ $wide ? 'fw' : '' }} @error($k) haserr @enderror">
    <label>{{ $f['label'] }} @if(!empty($f['required']))<b class="req">*</b>@endif</label>

    @if ($t === 'ta')
        <textarea class="inp" name="{{ $k }}" rows="3">{{ old($k, $raw) }}</textarea>

    @elseif ($t === 'sel')
        <select class="inp" name="{{ $k }}">
            <option value=""></option>
            @foreach ($f['options'] ?? [] as $o)
                <option value="{{ $o }}" @selected(old($k, $raw) === $o)>{{ $o }}</option>
            @endforeach
        </select>

    @elseif ($t === 'ref' && ! empty($f['multi']))
        {{-- رقائق اختيارٍ متعدد بدل مربع النظام الخام (Ctrl+نقر لم يعرفه أحد):
             كل خيارٍ حبةٌ تُنقر، والمختار يتوشح ✓ — والنموذج يرسل k[] نفسها بلا تغيير عقد --}}
        @php $sel = collect(old($k, is_array($raw) ? $raw : (json_decode($raw ?? '[]', true) ?: []))); @endphp
        <div class="chips" data-chips>
            @if (count($refOptions[$k] ?? []) > 8)
                <input class="inp chipq" type="search" placeholder="تصفية الخيارات…" data-chipfilter aria-label="تصفية {{ $f['label'] }}">
            @endif
            <div class="chipgrid">
                @forelse ($refOptions[$k] ?? [] as $id => $label)
                    <label class="chip">
                        <input type="checkbox" name="{{ $k }}[]" value="{{ $id }}" @checked($sel->contains($id))>
                        <span>{{ $label }}</span>
                    </label>
                @empty
                    <span class="sub">لا خيارات بعد</span>
                @endforelse
            </div>
        </div>

    @elseif ($t === 'ref')
        <select class="inp" name="{{ $k }}">
            <option value=""></option>
            @foreach ($refOptions[$k] ?? [] as $id => $label)
                <option value="{{ $id }}" @selected(old($k, $raw) === $id)>{{ $label }}</option>
            @endforeach
        </select>

    @elseif ($t === 'date')
        <input class="inp" type="date" name="{{ $k }}" value="{{ old($k, $raw ? substr($raw, 0, 10) : '') }}">

    @elseif ($t === 'dt')
        <input class="inp" type="datetime-local" name="{{ $k }}" value="{{ old($k, $raw ? str_replace(' ', 'T', substr($raw, 0, 16)) : '') }}">

    @elseif ($t === 'num' || $t === 'big')
        <input class="inp" type="number" step="any" name="{{ $k }}" value="{{ old($k, $raw) }}">

    @elseif ($t === 'bool')
        <label class="chk"><input type="checkbox" name="{{ $k }}" value="1" @checked(old($k, (bool) $raw))> نعم</label>

    @elseif ($t === 'tags')
        @php $tv = old($k, is_string($raw) ? implode('، ', json_decode($raw, true) ?: []) : ''); @endphp
        <input class="inp" name="{{ $k }}" value="{{ $tv }}" placeholder="افصل بينها بفواصل">

    @elseif ($t === 'sec')
        <input class="inp mono" type="password" name="{{ $k }}" value="" placeholder="{{ $raw ? '•••••• (اتركه فارغاً للإبقاء)' : '' }}" autocomplete="new-password">

    @elseif ($t === 'file' || $t === 'img')
        <label class="filefield">
            <input type="file" name="{{ $k }}" data-empty="لم يُحدَّد ملف"
                onchange="var n=this.parentNode.querySelector('.filename');n.textContent=this.files&&this.files.length?this.files[0].name:this.dataset.empty">
            <span class="filebtn">📎 اختر ملفاً</span>
            <span class="filename">لم يُحدَّد ملف</span>
        </label>
        @if ($raw)
            <div class="sub">
                @if ($t === 'img')<img class="thumb" src="{{ route('file.show', $raw) }}" alt="">
                @else<a href="{{ route('file.show', $raw) }}" target="_blank">الملف الحالي ↗</a>@endif
            </div>
        @endif

    @elseif ($t === 'url')
        <input class="inp mono ltr" name="{{ $k }}" value="{{ old($k, $raw) }}" placeholder="https://…">

    @else
        <input class="inp" name="{{ $k }}" value="{{ old($k, $raw) }}">
    @endif
    @error($k)<span class="ferr">{{ $message }}</span>@enderror
</div>
