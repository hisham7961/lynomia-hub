{{-- المرفقات الشاملة — يتوقع: $aModule $aRecordId $attachments $aUsers (id=>name) --}}
<div class="card" id="attachments">
    <h3 style="margin-bottom:10px">📎 المرفقات <span class="bdg g">{{ $attachments->count() }}</span></h3>

    @forelse ($attachments as $a)
        @php
            $ico = str_starts_with((string) $a->mime, 'image/') ? '🖼️'
                : (str_contains((string) $a->mime, 'pdf') ? '📕'
                : (str_contains((string) $a->mime, 'sheet') || str_contains((string) $a->mime, 'csv') ? '📊' : '📄'));
        @endphp
        <div class="arow" id="att-{{ $a->id }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:7px 0;border-bottom:1px solid var(--brd)">
            <span aria-hidden="true">{{ $ico }}</span>
            <div style="min-width:0;flex:1">
                <a href="{{ route('att.dl', $a->id) }}"><b>{{ \Illuminate\Support\Str::limit($a->original_name, 60) }}</b></a>
                <div class="sub">
                    {{ hub_bytes($a->size) }} · {{ $aUsers[$a->uploaded_by] ?? '—' }}
                    · {{ optional($a->created_at)->format('Y-m-d H:i') }}
                    @if ($a->downloads) · ⬇ {{ $a->downloads }}@endif
                    @if ($a->field) · <span title="ملاحظة">{{ \Illuminate\Support\Str::limit($a->field, 60) }}</span>@endif
                </div>
            </div>
            @if ($a->uploaded_by === auth()->id() || hub_is_owner() || hub_can(auth()->user(), $aModule, 'e'))
                <form method="POST" action="{{ route('att.destroy', $a->id) }}" class="inline"
                      {{-- الاسم في سمة HTML مُهرَّبة — لا سياق JS فلا صنف الحقن القديم أصلاً --}}
                      data-confirm="حذف المرفق «{{ \Illuminate\Support\Str::limit($a->original_name, 40) }}»؟">
                    @csrf @method('DELETE')
                    <button class="btn ghost xs" aria-label="حذف المرفق {{ $a->original_name }}">حذف</button>
                </form>
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
        <input class="inp" id="att-note" type="text" name="note" maxlength="200" placeholder="ملاحظة (اختياري)">
        <button class="btn p sm" type="submit">إرفاق</button>
    </form>
    @error('file')<div class="err">{{ $message }}</div>@enderror
</div>
