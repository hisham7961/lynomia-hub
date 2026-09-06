@extends('layouts.portal')
@section('title', 'فاتورة')
@section('content')

<div class="hero">
    <div><h2>🧾 {{ $inv->doc_no }}</h2>
        <div class="sub">{{ $inv->kind ?: '' }}</div></div>
    <a class="btn ghost sm" href="{{ route('portal.invoices') }}">← كل الفواتير</a>
</div>

<div class="card">
    <div class="grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
        <div><div class="sub">التاريخ</div><b>{{ $inv->date ? \Illuminate\Support\Str::of((string) $inv->date)->substr(0, 10) : '—' }}</b></div>
        <div><div class="sub">الاستحقاق</div><b>{{ $inv->due ? \Illuminate\Support\Str::of((string) $inv->due)->substr(0, 10) : '—' }}</b></div>
        <div><div class="sub">الإجمالي</div><b>{{ number_format((float) $inv->total, 3) }} {{ $inv->currency ?: '' }}</b></div>
        <div><div class="sub">المدفوع</div><b>{{ number_format((float) $inv->paid, 3) }} {{ $inv->currency ?: '' }}</b></div>
        <div><div class="sub">الحالة</div><b>{{ $inv->state ?: '—' }}</b></div>
    </div>
</div>

@endsection
