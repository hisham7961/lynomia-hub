@extends('layouts.portal')
@section('title', 'الفواتير')
@section('content')

<div class="hero"><div><h2>🧾 فواتيرُك</h2>
    <div class="sub">فواتيرُ المبيعات والمقبوضاتُ الخاصّة بك.</div></div></div>

@if ($invoices->isEmpty())
    <div class="cportal-empty"><h3>لا بيانات بعد</h3>
        <p class="sub">لا فواتيرَ لك حتى الآن.</p></div>
@else
    <div class="card" style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse">
            <thead><tr>
                <th style="padding:8px;text-align:start">الرقم</th>
                <th style="padding:8px;text-align:start">النوع</th>
                <th style="padding:8px;text-align:start">التاريخ</th>
                <th style="padding:8px;text-align:start">الاستحقاق</th>
                <th style="padding:8px;text-align:start">الإجمالي</th>
                <th style="padding:8px;text-align:start">الحالة</th>
            </tr></thead>
            <tbody>
            @foreach ($invoices as $inv)
                <tr style="border-top:1px solid var(--bd,#e5e7eb)">
                    <td style="padding:8px"><a href="{{ route('portal.invoice', $inv->id) }}"><b>{{ $inv->doc_no }}</b></a></td>
                    <td style="padding:8px">{{ $inv->kind ?: '—' }}</td>
                    <td style="padding:8px">{{ $inv->date ? \Illuminate\Support\Str::of((string) $inv->date)->substr(0, 10) : '—' }}</td>
                    <td style="padding:8px">{{ $inv->due ? \Illuminate\Support\Str::of((string) $inv->due)->substr(0, 10) : '—' }}</td>
                    <td style="padding:8px">{{ number_format((float) $inv->total, 3) }} {{ $inv->currency ?: '' }}</td>
                    <td style="padding:8px"><span class="bdg">{{ $inv->state ?: '—' }}</span></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
