{{-- المرفقات الشاملة — يتوقع: $aModule $aRecordId $attachments $aUsers (id=>name) --}}
<div class="card" id="attachments">
    <h3>📎 المرفقات <span class="bdg g">{{ $attachments->count() }}</span></h3>

    @forelse ($attachments as $a)
        @php
            $isImg = in_array($a->mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp', 'image/avif'], true);
            $isPdf = $a->mime === 'application/pdf';
            $ico = $isImg ? '🖼️' : ($isPdf ? '📕'
                : (str_contains((string) $a->mime, 'sheet') || str_contains((string) $a->mime, 'csv') ? '📊' : '📄'));
        @endphp
        <div class="arow" id="att-{{ $a->id }}" style="padding:7px 0;border-bottom:1px solid var(--brd)">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                {{-- الصور والشهادات واللوجوهات تُرى قبل أي ضغطة: مصغّرة حية لا أيقونة صمّاء --}}
                @if ($isImg)
                    <img src="{{ route('att.view', $a->id) }}" alt="" loading="lazy"
                         style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid var(--ln);background:#fff">
                @else
                    <span aria-hidden="true" style="font-size:22px">{{ $ico }}</span>
                @endif
                <div style="min-width:0;flex:1">
                    <a href="{{ route('att.dl', $a->id) }}"><b>{{ \Illuminate\Support\Str::limit($a->original_name, 60) }}</b></a>
                    <div class="sub">
                        {{ hub_bytes($a->size) }} · {{ $aUsers[$a->uploaded_by] ?? '—' }}
                        · {{ optional($a->created_at)->format('Y-m-d H:i') }}
                        @if ($a->downloads) · ⬇ {{ $a->downloads }}@endif
                        @php $aNote = $a->note ?: $a->field; @endphp
                        @if ($a->kind) · <span class="bdg g">{{ hub_doc_label($aModule, $a->kind) }}</span>@endif
                        @if ($a->doc_no) · رقم {{ $a->doc_no }}@endif
                        @if ($a->expires_at) · ينتهي {{ $a->expires_at->toDateString() }}@endif
                        @if ($aNote) · <span title="ملاحظة">{{ \Illuminate\Support\Str::limit($aNote, 60) }}</span>@endif
                    </div>
                </div>
                @if ($isImg || $isPdf)
                    <a class="btn ghost xs" href="{{ route('att.view', $a->id) }}" target="_blank" rel="noopener" title="فتح المعاينة في تبويب">↗</a>
                @endif
                @if ($a->uploaded_by === auth()->id() || hub_is_owner() || hub_can(auth()->user(), $aModule, 'e'))
                    <form method="POST" action="{{ route('att.destroy', $a->id) }}" class="inline"
                          {{-- الاسم في سمة HTML مُهرَّبة — لا سياق JS فلا صنف الحقن القديم أصلاً --}}
                          data-confirm="حذف المرفق «{{ \Illuminate\Support\Str::limit($a->original_name, 40) }}»؟">
                        @csrf @method('DELETE')
                        <button class="btn ghost xs" aria-label="حذف المرفق {{ $a->original_name }}">حذف</button>
                    </form>
                @endif
            </div>
            {{-- معاينة كاملة داخل الصفحة نفسها — بلا منبثقات: الصورة تتمدد وPDF بعارضه --}}
            @if ($isImg || $isPdf)
                <details style="margin-top:4px">
                    <summary class="sub pointer">👁 معاينة كاملة داخل الصفحة</summary>
                    @if ($isImg)
                        <img src="{{ route('att.view', $a->id) }}" alt="{{ $a->original_name }}" loading="lazy"
                             style="max-width:100%;max-height:70vh;border-radius:12px;border:1px solid var(--ln);margin-top:6px;background:#fff">
                    @else
                        <iframe src="{{ route('att.view', $a->id) }}" title="{{ $a->original_name }}" loading="lazy"
                                style="width:100%;height:60vh;border:1px solid var(--ln);border-radius:12px;margin-top:6px;background:#fff"></iframe>
                    @endif
                </details>
            @endif
        </div>
    @empty
        <div class="sub" style="padding:8px 0 14px">لا مرفقات — أرفق عقداً، إيصالاً، تصميماً، أو أي ملف يخص هذا السجل</div>
    @endforelse

    <form method="POST" action="{{ route('att.store') }}" enctype="multipart/form-data" class="crow" style="margin-top:10px">
        @csrf
        <input type="hidden" name="module" value="{{ $aModule }}">
        <input type="hidden" name="record_id" value="{{ $aRecordId }}">
        <label class="vh" for="att-file">اختر ملفاً</label>
        <input class="inp" id="att-file" type="file" name="file" required>
        <label class="vh" for="att-note">ملاحظة عن الملف</label>
        <input class="inp" id="att-note" type="text" name="note" maxlength="300" placeholder="ملاحظة (اختياري)">
        @php $aSpec = hub_doc_spec($aModule); @endphp
        @if ($aSpec)
            <label class="vh" for="att-kind">نوع الوثيقة</label>
            <select class="inp" id="att-kind" name="kind">
                <option value="">— نوع الوثيقة (اختياري) —</option>
                @foreach ($aSpec as $d)
                    <option value="{{ $d['key'] }}" @selected(request()->query('kind') === $d['key'])>{{ $d['label'] }}</option>
                @endforeach
            </select>
            <label class="vh" for="att-docno">رقم الوثيقة</label>
            <input class="inp" id="att-docno" type="text" name="doc_no" maxlength="80" placeholder="رقمها (اختياري)" style="max-width:140px">
            <label class="vh" for="att-exp">تاريخ الانتهاء</label>
            <input class="inp" id="att-exp" type="date" name="expires_at" title="تاريخ الانتهاء — يدخل رادار «ينتهي قريباً»" style="max-width:160px">
        @endif
        <button class="btn p sm" type="submit">إرفاق</button>
    </form>
    @error('kind')<div class="err">{{ $message }}</div>@enderror
    @error('file')<div class="err">{{ $message }}</div>@enderror
</div>
