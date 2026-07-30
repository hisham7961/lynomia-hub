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
        </div>
        <div class="formfoot">
            <button class="btn p" type="submit">{{ $updating ? 'حفظ التعديلات' : 'إضافة' }}</button>
            @if ($hx)
                <button class="btn ghost" type="button" onclick="Hub.closeModal()">إلغاء</button>
            @else
                <a class="btn ghost" href="{{ route('m.index', $module) }}">إلغاء</a>
            @endif
        </div>
    </form>
</div>
