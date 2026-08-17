@php
    $k = $f['key']; $c = $f['col']; $t = $f['type'];
    // التعبئة المسبقة تعمل في الإضافة فقط — التعديل يعرض قيم السجل حصراً
    $raw = $row ? $row->{$c} : (($prefill ?? [])[$k] ?? null);
    $wide = in_array($t, ['ta', 'tags']) || ! empty($f['multi']);
    // v2.128: ربطٌ برمجي — id للحقل وfor على العنوان، فالقارئ الشاشي يسمي كل حقل،
    // ونقر العنوان يركّز حقله. required الفعلية تعكس إلزامية السجل لا النجمة وحدها.
    $fid = 'f-' . $k;
    $req = ! empty($f['required']) ? ' required aria-required=true' : '';
@endphp
<div class="fld {{ $wide ? 'fw' : '' }} @error($k) haserr @enderror">
    <label @if(! in_array($t, ['bool', 'file', 'img']) && empty($f['multi'])) for="{{ $fid }}" @endif>{{ $f['label'] }} @if(!empty($f['required']))<b class="req" aria-hidden="true">*</b>@endif</label>

    @if ($t === 'ta')
        <textarea class="inp @error($k) err @enderror" id="{{ $fid }}" name="{{ $k }}" rows="3"{!! $req !!}>{{ old($k, $raw) }}</textarea>

    @elseif ($t === 'sel')
        @php $selRaw = old($k, $raw); @endphp
        <select class="inp @error($k) err @enderror" id="{{ $fid }}" name="{{ $k }}"{!! $req !!}>
            <option value=""></option>
            {{-- قيمةٌ حاليةٌ خارج القائمة (عملةُ فاتورةٍ مولَّدةٍ بمفرداتٍ أخرى مثلاً)
                 تُعرَض خياراً محدَّداً بدل إسقاطها صامتةً عند أوّل حفظ — فلا يُفرَّغ حقلٌ
                 لم يُقصَد تفريغُه --}}
            @if (filled($selRaw) && ! in_array($selRaw, $f['options'] ?? [], true))
                <option value="{{ $selRaw }}" selected>{{ $selRaw }} — قيمةٌ حالية</option>
            @endif
            @foreach ($f['options'] ?? [] as $o)
                <option value="{{ $o }}" @selected($selRaw === $o)>{{ $o }}</option>
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
        <select class="inp @error($k) err @enderror" id="{{ $fid }}" name="{{ $k }}"{!! $req !!}>
            <option value=""></option>
            @foreach ($refOptions[$k] ?? [] as $id => $label)
                {{-- بلا === النوعية: old() نصٌّ ومعرفُ الخيار قد يكون رقماً — كانت
                     المقارنةُ الصارمة تُفقد الاختيارَ بعد أي خطأ تحقق --}}
                <option value="{{ $id }}" @selected((string) old($k, $raw) === (string) $id)>{{ $label }}</option>
            @endforeach
        </select>

    @elseif ($t === 'date')
        <input class="inp @error($k) err @enderror" id="{{ $fid }}" type="date" name="{{ $k }}" value="{{ old($k, $raw ? substr($raw, 0, 10) : '') }}"{!! $req !!}>

    @elseif ($t === 'dt')
        <input class="inp @error($k) err @enderror" id="{{ $fid }}" type="datetime-local" name="{{ $k }}" value="{{ old($k, $raw ? str_replace(' ', 'T', substr($raw, 0, 16)) : '') }}"{!! $req !!}>

    @elseif ($t === 'num' || $t === 'big')
        <input class="inp @error($k) err @enderror" id="{{ $fid }}" type="number" step="any" name="{{ $k }}" value="{{ old($k, $raw) }}"{!! $req !!}>

    @elseif ($t === 'bool')
        <label class="chk"><input type="checkbox" name="{{ $k }}" value="1" @checked(old($k, (bool) $raw))> نعم</label>

    @elseif ($t === 'tags')
        {{-- الكاست `'tags' => 'array'` يجعل $raw **مصفوفة**، وشرطُ is_string كان
             يُسقطها فيُرسَم المربّع فارغاً ويمحو وسومَ السجل عند أي حفظ، في ٢٨
             وحدة. تُقبل الصورتان — كما يفعل حقل ref المتعدد أعلاه. --}}
        @php $tv = old($k, implode('، ', is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []))); @endphp
        <input class="inp" id="{{ $fid }}" name="{{ $k }}" value="{{ $tv }}" placeholder="افصل بينها بفواصل"{!! $req !!}>

    @elseif ($t === 'sec')
        {{-- **بلا `required` عمداً**: حقلُ السرّ يُعرض فارغاً دائماً حتى في
             التعديل («اتركه فارغاً للإبقاء»)، فاشتراطُ الملء يمنع تعديلَ أيّ
             حقلٍ آخر في السجل. الإلزامُ يُفرض عند الإنشاء في مسار الكتابة. --}}
        <input class="inp mono" id="{{ $fid }}" type="password" name="{{ $k }}" value="" placeholder="{{ $raw ? '•••••• (اتركه فارغاً للإبقاء)' : '' }}" autocomplete="new-password">

    @elseif ($t === 'file' || $t === 'img')
        <label class="filefield">
            <input type="file" name="{{ $k }}" data-empty="لم يُحدَّد ملف"
                onchange="var n=this.parentNode.querySelector('.filename');n.textContent=this.files&&this.files.length?this.files[0].name:this.dataset.empty">
            <span class="filebtn">📎 اختر ملفاً</span>
            <span class="filename">لم يُحدَّد ملف</span>
        </label>
        <span class="sub fhint">حتى {{ hub_upload_cap()['label'] }} — بعدّاد تقدّمٍ أثناء الرفع</span>
        @if ($raw)
            <div class="sub" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                @if ($t === 'img')<img class="thumb" src="{{ route('file.show', $raw) }}" alt="">
                @else<a href="{{ route('file.show', $raw) }}" target="_blank" rel="noopener">الملف الحالي ↗</a>@endif
                {{-- تنزيلُ القائم قبل استبداله: الرفعُ الجديد يدهس القديم بلا نسخة --}}
                <a class="btn ghost xs" href="{{ route('file.show', ['path' => $raw, 'dl' => 1]) }}"
                   title="تنزيل الملف الحالي بصيغته الأصلية">⬇ تحميل</a>
            </div>
        @endif

    @elseif ($t === 'url')
        {{-- رايةُ الإلزام هنا كما في الفرع العام: كان الحقلُ عليه نجمةُ الإلزام
             والمتصفحُ لا يمنع إرسالَه فارغاً — وعدٌ في الشاشة لا ينفّذه شيء --}}
        <input class="inp mono ltr @error($k) err @enderror" id="{{ $fid }}" name="{{ $k }}" value="{{ old($k, $raw) }}" placeholder="https://…"{!! $req !!}>

    @else
        <input class="inp @error($k) err @enderror" id="{{ $fid }}" name="{{ $k }}" value="{{ old($k, $raw) }}"{!! $req !!}>
    @endif
    @if (! empty($f['hint']))<span class="sub fhint">{{ $f['hint'] }}</span>@endif
    @error($k)<span class="ferr" id="{{ $fid }}-err">{{ $message }}</span>@enderror
</div>
