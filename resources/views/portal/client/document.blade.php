@extends('layouts.portal')
@section('title', 'وثيقة')
@section('content')

<div class="hero">
    <div><h2>📄 {{ $doc->name }}</h2>
        <div class="sub">{{ $doc->cat ?: '' }}{{ $doc->doc_no ? ' · ' . $doc->doc_no : '' }}</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        {{-- (الجولة 1 · F25) تنزيلٌ آمنٌ عبر المسار المصادَق — يُرسم فقط حين يوجد ملفٌّ فعلاً --}}
        @if ($hasFile ?? false)
            <a class="btn p sm" href="{{ route('portal.document.download', $doc->id) }}">⬇ تنزيل الملف</a>
        @endif
        <a class="btn ghost sm" href="{{ route('portal.documents') }}">← كل الوثائق</a>
    </div>
</div>

<div class="card">
    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div><div class="sub">التصنيف</div><b>{{ $doc->cat ?: '—' }}</b></div>
        <div><div class="sub">الرقم</div><b>{{ $doc->doc_no ?: '—' }}</b></div>
        <div><div class="sub">تاريخ الإصدار</div><b>{{ $doc->issue_date ? \Illuminate\Support\Str::of((string) $doc->issue_date)->substr(0, 10) : '—' }}</b></div>
        <div><div class="sub">تاريخ الانتهاء</div><b>{{ $doc->expiry ? \Illuminate\Support\Str::of((string) $doc->expiry)->substr(0, 10) : '—' }}</b></div>
    </div>
    @if ($doc->description)
        <div style="margin-top:14px"><div class="sub">الوصف</div><p>{{ $doc->description }}</p></div>
    @endif
</div>

@endsection
