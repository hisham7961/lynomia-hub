@extends('layouts.app')
@section('title', 'صندوق الوثائق')
@section('content')
<div class="hero">
    <div>
        <h2>📥 صندوق الوثائق الوارد</h2>
        <div class="sub">ارفع أولاً وصنّف لاحقاً — فاتورة وصلت بالبريد، ورقة من الحكومة، عقد ممسوح: كله هنا فلا يضيع شيء</div>
    </div>
</div>

<div class="card">
    <h3>⬆️ رفع سريع</h3>
    <form method="POST" action="{{ route('inboxdocs.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="crow">
            <label class="vh" for="upf">الملف</label><input class="inp" id="upf" type="file" name="file" required style="max-width:280px">
            <label class="vh" for="upn">ملاحظة عن الوثيقة</label><input class="inp" id="upn" name="note" placeholder="ملاحظة (اختياري): من أين وصلت؟ ما هي؟" maxlength="390" style="max-width:320px">
            <button class="btn p sm">رفع إلى الوارد</button>
        </div>
        @error('file')<span class="ferr">{{ $message }}</span>@enderror
    </form>
</div>

<div class="toolbar">
    <div class="tabs">
        @foreach (['وارد' => '📥', 'مصنف' => '✅'] as $tab => $ico)
            <a class="tab {{ $st === $tab ? 'on' : '' }}" href="{{ route('inboxdocs.index', ['st' => $tab]) }}">{{ $ico }} {{ $tab }} ({{ $counts[$tab] ?? 0 }})</a>
        @endforeach
    </div>
</div>

<div class="cards" style="grid-template-columns:1fr">
@forelse ($rows as $doc)
    <div class="card">
        <div style="display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;align-items:flex-start">
            <div>
                <a href="{{ route('file.show', $doc->path) }}" target="_blank"><b>📄 {{ $doc->orig }}</b></a>
                <span class="sub">({{ number_format($doc->size / 1024, 0) }} KB)</span>
                <div class="sub">رفعها {{ $users[$doc->uploaded_by] ?? '؟' }} {{ $doc->created_at->diffForHumans() }}{{ $doc->note ? ' · «' . $doc->note . '»' : '' }}</div>
                @if ($doc->status === 'مصنف')
                    <div class="sub" style="margin-top:4px">
                        ✅ صنّفها {{ $users[$doc->classified_by] ?? '؟' }}:
                        @if ($doc->module)<span class="bdg g">{{ hub_mod($doc->module)['label'] ?? $doc->module }}</span>@endif
                        @if ($doc->record_id && $doc->module)<a class="bdg" href="{{ route('m.show', [$doc->module, $doc->record_id]) }}">فتح السجل ←</a>@endif
                        @if ($doc->kind)<span class="bdg">{{ $doc->kind }}</span>@endif
                        @if ($doc->party)<span class="bdg">🏛 {{ $doc->party }}</span>@endif
                        @if ($doc->doc_date)<span class="bdg">📅 {{ $doc->doc_date->format('Y-m-d') }}</span>@endif
                        @if ($doc->expiry)<span class="bdg {{ $doc->expiry->isPast() ? 'bad' : 'wn' }}">⏳ ينتهي {{ $doc->expiry->format('Y-m-d') }}</span>@endif
                    </div>
                @endif
            </div>
            @if (auth()->user()->role?->is_owner)
                <form method="POST" action="{{ route('inboxdocs.destroy', $doc->id) }}" onsubmit="return confirm('حذف الوثيقة؟')">
                    @csrf @method('DELETE')<button class="btn ghost xs" style="color:var(--bad)">🗑</button>
                </form>
            @endif
        </div>

        @if ($doc->status === 'وارد')
            <details style="margin-top:8px"><summary class="sub" style="cursor:pointer">🏷️ تصنيف الوثيقة ▾</summary>
                <form method="POST" action="{{ route('inboxdocs.classify', $doc->id) }}" class="frm" style="margin-top:8px">
                    @csrf
                    <div class="crow">
                        <select class="inp" aria-label="الوحدة" name="module" style="max-width:180px">
                            <option value="">— الوحدة (اختياري)</option>
                            @foreach ($modules as $mk => $ml)<option value="{{ $mk }}">{{ $ml }}</option>@endforeach
                        </select>
                        <input class="inp" aria-label="اسم السجل" name="record" placeholder="اسم السجل داخل الوحدة (اختياري)" maxlength="160" style="max-width:230px">
                        <select class="inp" aria-label="الشركة" name="company_id" style="max-width:170px">
                            <option value="">— الشركة</option>
                            @foreach ($companies as $cid => $cn)<option value="{{ $cid }}">{{ $cn }}</option>@endforeach
                        </select>
                    </div>
                    <div class="crow" style="margin-top:6px">
                        <input class="inp" aria-label="الجهة" name="party" placeholder="الجهة: مورد/عميل/وزارة…" maxlength="150" style="max-width:180px">
                        <input class="inp" aria-label="نوع الوثيقة" name="kind" placeholder="النوع: فاتورة/عقد/رخصة…" maxlength="50" style="max-width:170px">
                        <label class="sub">تاريخها <input class="inp" type="date" name="doc_date"></label>
                        <label class="sub">تنتهي <input class="inp" type="date" name="expiry"></label>
                        <button class="btn sm">حفظ التصنيف</button>
                    </div>
                    @error('module')<span class="ferr">{{ $message }}</span>@enderror
                    @error('record')<span class="ferr">{{ $message }}</span>@enderror
                </form>
            </details>
        @endif
    </div>
@empty
    <div class="card"><div class="empty"><span class="big">📭</span>{{ $st === 'وارد' ? 'لا وثائق بانتظار التصنيف — ممتاز' : 'لا وثائق مصنفة بعد' }}</div></div>
@endforelse
</div>
{{ $rows->links('partials.pagination') }}
@endsection
